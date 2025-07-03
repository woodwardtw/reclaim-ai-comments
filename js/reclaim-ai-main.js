document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.reply-as-user-button').forEach(button => {
        button.addEventListener('click', function () {
            const commentId = this.dataset.commentId;
            const select = document.getElementById(`reply-user-${commentId}`);
            const userId = select.value;

            if (!userId) {
                alert('Please select a user.');
                return;
            }

            fetch(maddenessAjax.ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'reply_as_user',
                    comment_id: commentId,
                    user_id: userId
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Reply posted.');
                    location.reload(); // or dynamically insert it into the DOM
                } else {
                    alert('Error: ' + data.data);
                }
            });
        });
    });
});
