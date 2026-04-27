<?php
include "config.php";

$currentUser = isset($_POST['current_user']) ? trim($_POST['current_user']) : '';
$withUser = isset($_POST['with_user']) ? trim($_POST['with_user']) : '';

if ($currentUser === '' || $withUser === '') {
    http_response_code(400);
    echo "Invalid delete request";
    exit;
}

$sql = "
DELETE FROM chat_messages
WHERE (sender = ? AND receiver = ?)
   OR (sender = ? AND receiver = ?)
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo "Failed to prepare delete statement";
    exit;
}

$stmt->bind_param("ssss", $currentUser, $withUser, $withUser, $currentUser);
$stmt->execute();
$stmt->close();

echo "ok";
?>