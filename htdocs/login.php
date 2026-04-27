<?php
include 'config.php';
include 'chat_auth.php';

$secretName = isset($_POST['secret_name']) ? trim($_POST['secret_name']) : '';
$displayName = isset($_POST['display_name']) ? trim($_POST['display_name']) : '';

if ($secretName === '') {
    http_response_code(400);
    echo 'Secret name is required';
    exit;
}

if ($displayName === '') {
    http_response_code(400);
    echo 'Name is required';
    exit;
}

if (function_exists('mb_strlen')) {
    $nameLength = mb_strlen($displayName);
} else {
    $nameLength = strlen($displayName);
}

if ($nameLength > 100) {
    http_response_code(400);
    echo 'Name is too long';
    exit;
}

list($isAuthenticated, $errorMessage) = chat_authenticate($secretName, $displayName);

if (!$isAuthenticated) {
    http_response_code(403);
    echo $errorMessage;
    exit;
}

echo 'ok';
?>