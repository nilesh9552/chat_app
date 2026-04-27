<?php
include "config.php";

$currentUser = isset($_GET['current_user']) ? trim($_GET['current_user']) : '';

if ($currentUser === '') {
	echo "[]";
	exit;
}

if (mb_strlen($currentUser) > 100) {
	echo "[]";
	exit;
}

$stmt = $conn->prepare("SELECT username FROM users WHERE username <> ? ORDER BY username ASC");

if (!$stmt) {
	echo "[]";
	exit;
}

$stmt->bind_param("s", $currentUser);
$stmt->execute();
$result = $stmt->get_result();

$users = [];

while ($row = $result->fetch_assoc()) {
	$users[] = $row['username'];
}

$stmt->close();

header('Content-Type: application/json');
echo json_encode($users);
?>
