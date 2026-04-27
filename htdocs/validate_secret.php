<?php
include 'config.php';
include 'chat_auth.php';

$secretName = isset($_POST['secret_name']) ? trim($_POST['secret_name']) : '';

if ($secretName === '') {
    http_response_code(400);
    echo 'Secret name is required';
    exit;
}

if (!chat_secret_is_configured()) {
    http_response_code(403);
    echo 'Chat secrets are not configured';
    exit;
}

if (!chat_is_valid_secret($secretName)) {
    http_response_code(403);
    echo 'Invalid secret name';
    exit;
}

echo 'ok';
?>