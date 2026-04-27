<?php
include "config.php";
include "chat_auth.php";
include "storage.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo "Method not allowed";
	exit;
}

$token = isset($_POST['token']) ? (string)$_POST['token'] : '';
if ($token === '' && isset($_SERVER['HTTP_X_CLEAR_TOKEN'])) {
	$token = (string)$_SERVER['HTTP_X_CLEAR_TOKEN'];
}

chat_start_session();
$isAuthenticated = !empty($_SESSION['chat_authenticated']);

// Allow clear when a logged-in chat user triggers it.
// Keep token-based access as a fallback for external/admin automation.
$configuredToken = isset($adminClearToken) ? trim((string)$adminClearToken) : '';
if (!$isAuthenticated && $configuredToken !== '' && !hash_equals($configuredToken, $token)) {
	http_response_code(403);
	echo "Invalid clear token";
	exit;
}

if (!$isAuthenticated && $configuredToken === '') {
	http_response_code(403);
	echo "Unauthorized";
	exit;
}

// Clear only chat messages; keep clock settings intact.
if ($conn instanceof mysqli) {
	$result = $conn->query("SELECT photo_path FROM messages WHERE photo_path IS NOT NULL AND photo_path <> ''");

	if ($result) {
		while ($row = $result->fetch_assoc()) {
			$fileName = basename($row['photo_path']);
			$filePath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $fileName;

			if (is_file($filePath)) {
				@unlink($filePath);
			}
		}
	}

	if (!$result) {
		error_log("Clear message photo query failed, continuing with fallback clear: " . $conn->error);
	}

	$messagesCleared = $conn->query("TRUNCATE TABLE messages");
	$privateCleared = $conn->query("TRUNCATE TABLE chat_messages");

	if (!$messagesCleared || !$privateCleared) {
		error_log("Clear tables failed, using fallback storage clear.");
		$messages = storage_clear_messages();
		foreach ($messages as $row) {
			$photoPath = isset($row['photo_path']) ? trim((string)$row['photo_path']) : '';
			if ($photoPath === '') {
				continue;
			}

			$fileName = basename($photoPath);
			$filePath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $fileName;
			if (is_file($filePath)) {
				@unlink($filePath);
			}
		}
	}
} else {
	$messages = storage_clear_messages();
	foreach ($messages as $row) {
		$photoPath = isset($row['photo_path']) ? trim((string)$row['photo_path']) : '';
		if ($photoPath === '') {
			continue;
		}

		$fileName = basename($photoPath);
		$filePath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $fileName;
		if (is_file($filePath)) {
			@unlink($filePath);
		}
	}
}

echo "ok";
?>