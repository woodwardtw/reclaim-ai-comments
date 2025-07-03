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
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );


require plugin_dir_path(__FILE__) . '/inc/data.php';


// default post type
add_action( 'publish_post', 'maddeness_openai_ai_comment' );

function maddeness_openai_ai_comment($post_id) {  
    // Get OpenAI API key from options
    $apiKey = get_field('open_ai_key', 'option');
    if (empty($apiKey)) {
        error_log('OpenAI API key is missing.');
        return;
    }

    // Get post content safely
    $post = get_post($post_id);
    write_log($post_id);
    if (!$post) {
        error_log('Invalid post ID: ' . $post_id);
        return;
    }

    $content = $post->post_content;

    // Number of comments to generate
    $comment_number = get_field('initial_comments');
    if (empty($comment_number) || !is_numeric($comment_number)) {
        error_log('Invalid number of initial comments.');
        return;
    }

    // Get random commenters
    $commenters = ai_maddeness_get_random_author($comment_number);
    if (empty($commenters)) {
        error_log('No commenters found.');
        return;
    }

    // Loop through each commenter
    foreach ($commenters as $commenter) {
        $name = $commenter->user_nicename;
        $email = $commenter->user_email;
        $commenter_id = $commenter->ID;
        write_log("name= {$name}, email= {$email}, id= {$commenter_id}");

        // Get custom fields for each user
        $personality = get_field('personality', 'user_' . $commenter_id);
        $style = get_field('writing_style', 'user_' . $commenter_id);
        $sample = get_field('sample_post', 'user_' . $commenter_id);

        // Build prompt for OpenAI
        $prompt = "{$content}\n\nRespond to this post. Keep it short and like a normal blog comment. Your personality is: {$personality}. Your writing style is: {$style}. A sample of your type of post is: {$sample}.";

        // Prepare OpenAI API request
        $data = [
            "model" => "gpt-4o",
            "messages" => [
                [
                    "role" => "user",
                    "content" => $prompt
                ]
            ],
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

        if (curl_errno($ch)) {
            error_log('OpenAI API error: ' . curl_error($ch));
        } else {
            $responseData = json_decode($response, true);
            $generated_comment = $responseData['choices'][0]['message']['content'] ?? '';
            write_log($generated_comment);
            if (!empty($generated_comment)) {
                // Insert the comment
                wp_insert_comment([
                   'comment_post_ID'      => (int) $post_id,
                    'comment_content'      => $generated_comment,
                    'user_id'              => (int) $commenter_id,
                    'comment_author'       => sanitize_text_field($name),
                    'comment_author_email' => sanitize_email($email),
                ]);
            } else {
                error_log('Empty response from OpenAI for user ID ' . $commenter_id);
            }
        }

        sleep(1); // Optional pause between requests
    }
    curl_close($ch);
}



function ai_maddeness_get_random_author($number = 2) {
    $all_users = get_users([
        'role' => 'subscriber',
    ]);

    shuffle($all_users);

    return array_slice($all_users, 0, $number);
}



//LOGGER -- like frogger but more useful

if ( ! function_exists('write_log')) {
   function write_log ( $log )  {
      if ( is_array( $log ) || is_object( $log ) ) {
         error_log( print_r( $log, true ) );
      } else {
         error_log( $log );
      }
   }
}

  //print("<pre>".print_r($a,true)."</pre>");


    //save acf json
add_filter('acf/settings/save_json', 'ai_maddeness_json_save_point');
 
function ai_maddeness_json_save_point( $path ) {
    
    // update path
    $path = plugin_dir_path(__FILE__) . 'acf-json'; //replace w get_stylesheet_directory() for theme
    
    // return
    return $path;
    
}


// load acf json
add_filter('acf/settings/load_json', 'ai_maddeness_json_load_point');

function ai_maddeness_json_load_point( $paths ) {
    
    // remove original path (optional)
    unset($paths[0]);
    
    
    // append path
    $paths[] = plugin_dir_path(__FILE__)  . 'acf-json';//replace w get_stylesheet_directory() for theme
    
    
    // return
    return $paths;
    
}