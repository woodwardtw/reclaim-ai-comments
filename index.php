<?php 
/*
Plugin Name: Reclaim AI comments - AI Maddeness
Plugin URI:  https://github.com/
Description: For using OpenAI to comment
Version:     1.0
Author:      DLINQ
Author URI:  http://altlab.vcu.edu
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Domain Path: /languages
Text Domain: my-toolset
*/

defined('ABSPATH') or die('No script kiddies please!');

add_action('wp_enqueue_scripts', 'maddeness_openai_enqueue_scripts');
function maddeness_openai_enqueue_scripts() {
    wp_enqueue_script(
        'maddeness-openai-script',
        plugin_dir_url(__FILE__) . 'js/reclaim-ai-main.js',
        ['jquery'],
        '1.0',
        true
    );

    wp_localize_script('maddeness-openai-script', 'maddenessAjax', [
        'ajaxurl' => admin_url('admin-ajax.php')
    ]);
}

require plugin_dir_path(__FILE__) . '/inc/data.php';

add_action('publish_post', 'maddeness_openai_ai_comment');
function maddeness_openai_ai_comment($post_id, $override_user_ids = null) {
    $apiKey = get_field('open_ai_key', 'option');
    if (empty($apiKey)) {
        error_log('OpenAI API key is missing.');
        return;
    }

    $post = get_post($post_id);
    if (!$post) {
        error_log('Invalid post ID: ' . $post_id);
        return;
    }

    $comment_number = (int) get_field('initial_comments');
    $comment_number = (is_numeric($comment_number) && $comment_number > 0) ? $comment_number : 1;

    $commenters = ai_maddeness_get_random_author($comment_number, $override_user_ids);
    if (empty($commenters)) {
        error_log('No commenters found.');
        return;
    }

    foreach ($commenters as $commenter) {
        $generated_comment = generate_ai_comment($post_id, $commenter->ID, $apiKey);

        if (!empty($generated_comment)) {
            wp_insert_comment([
                'comment_post_ID'      => (int) $post_id,
                'comment_content'      => $generated_comment,
                'user_id'              => (int) $commenter->ID,
                'comment_author'       => sanitize_text_field($commenter->user_nicename),
                'comment_author_email' => sanitize_email($commenter->user_email),
            ]);
        }

        sleep(1);
    }
}

function generate_ai_comment($post_id, $user_id, $apiKey, $parent_comment_id = null) {
    $user = get_user_by('ID', $user_id);
    $post = get_post($post_id);
    if (!$user || !$post) return '';

    $post_content = $post->post_content;
    $parent_comment_text = '';

    if ($parent_comment_id) {
        $parent_comment = get_comment($parent_comment_id);
        if ($parent_comment) {
            $parent_comment_text = "\n\nYou're replying to this comment: \"{$parent_comment->comment_content}\"";
        }
    }

    $personality = get_field('personality', 'user_' . $user_id);
    $style = get_field('writing_style', 'user_' . $user_id);
    $sample = get_field('sample_post', 'user_' . $user_id);

    $prompt = <<<PROMPT
{$post_content}
{$parent_comment_text}

Respond in a natural blog comment style. Keep it short. Do not reference players or information after 2001.
Your personality is: {$personality}
Your writing style is: {$style}
A sample of your posts is: {$sample}
PROMPT;

    $data = [
        "model" => "gpt-4o",
        "messages" => [["role" => "user", "content" => $prompt]],
        "max_tokens" => 1500
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    curl_close($ch);

    if (curl_errno($ch)) {
        error_log('OpenAI API error: ' . curl_error($ch));
        return '';
    }

    $responseData = json_decode($response, true);
    return $responseData['choices'][0]['message']['content'] ?? '';
}

function ai_maddeness_get_random_author($number = 1, $override_user_ids = null) {
    if (!empty($override_user_ids)) {
        $user_ids = is_array($override_user_ids) ? $override_user_ids : [$override_user_ids];
        return get_users(['include' => $user_ids]);
    }

    $all_users = get_users(['role' => 'subscriber']);
    shuffle($all_users);
    return array_slice($all_users, 0, $number);
}

add_filter('comment_text', 'add_reply_as_user_dropdown', 10, 2);
function add_reply_as_user_dropdown($comment_text, $comment) {
    if (!current_user_can('moderate_comments')) return $comment_text;

    $users = get_users(['fields' => ['ID', 'display_name']]);
    ob_start();
    ?>
    <div class="reply-as-user-tools" data-comment-id="<?php echo $comment->comment_ID; ?>">
        <label for="reply-user-<?php echo $comment->comment_ID; ?>">Reply as:</label>
        <select class="reply-as-user-select" id="reply-user-<?php echo $comment->comment_ID; ?>">
            <option value="">Select user</option>
            <?php foreach ($users as $user): ?>
                <option value="<?php echo $user->ID; ?>"><?php echo esc_html($user->display_name); ?></option>
            <?php endforeach; ?>
        </select>
        <button class="reply-as-user-button" data-comment-id="<?php echo $comment->comment_ID; ?>">Reply</button>
    </div>
    <?php
    return $comment_text . ob_get_clean();
}

add_action('wp_ajax_reply_as_user', 'handle_reply_as_user');
function handle_reply_as_user() {
    if (!current_user_can('moderate_comments')) {
        wp_send_json_error('Permission denied');
    }

    $parent_id = intval($_POST['comment_id']);
    $user_id = intval($_POST['user_id']);

    $user = get_user_by('ID', $user_id);
    $parent = get_comment($parent_id);
    $apiKey = get_field('open_ai_key', 'option');

    if (!$user || !$parent || empty($apiKey)) {
        wp_send_json_error('Invalid user, comment, or API key.');
    }

    $generated_comment = generate_ai_comment($parent->comment_post_ID, $user_id, $apiKey, $parent_id);

    if (empty($generated_comment)) {
        wp_send_json_error('AI comment generation failed.');
    }

    wp_insert_comment([
        'comment_post_ID' => $parent->comment_post_ID,
        'comment_author' => $user->display_name,
        'comment_author_email' => $user->user_email,
        'comment_author_url' => $user->user_url,
        'comment_content' => $generated_comment,
        'user_id' => $user_id,
        'comment_parent' => $parent_id,
        'comment_approved' => 1,
    ]);

    wp_send_json_success('Reply posted');
}

if (!function_exists('write_log')) {
    function write_log($log) {
        if (is_array($log) || is_object($log)) {
            error_log(print_r($log, true));
        } else {
            error_log($log);
        }
    }
}

add_filter('acf/settings/save_json', 'ai_maddeness_json_save_point');
function ai_maddeness_json_save_point($path) {
    return plugin_dir_path(__FILE__) . 'acf-json';
}

add_filter('acf/settings/load_json', 'ai_maddeness_json_load_point');
function ai_maddeness_json_load_point($paths) {
    unset($paths[0]);
    $paths[] = plugin_dir_path(__FILE__) . 'acf-json';
    return $paths;
}
