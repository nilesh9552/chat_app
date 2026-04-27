<?php
include "config.php";

$username = isset($_POST['username']) ? trim($_POST['username']) : '';

if ($username === '') {
    http_response_code(400);
    echo "Username is required";
    exit;
}

if (mb_strlen($username) > 100) {
    http_response_code(400);
    echo "Username is too long";
    exit;
}

$stmt = $conn->prepare("INSERT IGNORE INTO users(username) VALUES(?)");

if (!$stmt) {
    http_response_code(500);
    echo "Failed to prepare registration statement";
    exit;
}

$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->close();

echo "ok";
?>