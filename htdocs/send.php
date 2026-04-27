<?php
include "config.php";
include "chat_auth.php";
include "storage.php";

function string_length($value) {
	if (function_exists('mb_strlen')) {
		return mb_strlen($value);
	}

	return strlen($value);
}

$name = chat_require_auth(false);
$msg = isset($_POST['msg']) ? trim($_POST['msg']) : '';
$photoPath = null;

if ($name !== '' && string_length($name) > 100) {
	http_response_code(400);
	echo "Name is too long";
	exit;
}

if ($msg !== '' && string_length($msg) > 2000) {
	http_response_code(400);
	echo "Message is too long";
	exit;
}

if ($name === '' || $msg === '') {
		if (!isset($_FILES['photo']) || !is_array($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
		http_response_code(400);
		echo "Message or attachment is required";
		exit;
		}
}

if (isset($_FILES['photo']) && is_array($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
		$maxUploadSize = 25 * 1024 * 1024;
		if (isset($_FILES['photo']['size']) && (int)$_FILES['photo']['size'] > $maxUploadSize) {
		http_response_code(400);
		echo "Attachment is too large (max 25MB)";
		exit;
		}

		if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
		http_response_code(400);
		echo "Photo upload failed";
		exit;
		}

		$tmpPath = $_FILES['photo']['tmp_name'];
		$mimeType = mime_content_type($tmpPath);
		$allowed = [
				'image/jpeg' => 'jpg',
				'image/png' => 'png',
				'image/gif' => 'gif',
				'image/webp' => 'webp',
				'video/mp4' => 'mp4',
				'video/webm' => 'webm',
				'video/ogg' => 'ogv',
				'application/pdf' => 'pdf'
		];

		if (!isset($allowed[$mimeType])) {
		http_response_code(400);
		echo "Only JPG, PNG, GIF, WEBP, MP4, WEBM, OGV, PDF allowed";
		exit;
		}

		$uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
		if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0755, true)) {
		http_response_code(500);
		echo "Unable to create uploads directory";
		exit;
		}

		$fileName = uniqid('img_', true) . '.' . $allowed[$mimeType];
		$destination = $uploadsDir . DIRECTORY_SEPARATOR . $fileName;

		if (!move_uploaded_file($tmpPath, $destination)) {
		http_response_code(500);
		echo "Unable to save uploaded photo";
		exit;
		}

		$photoPath = $fileName;
}

if ($name === '' || ($msg === '' && $photoPath === null)) {
	http_response_code(400);
	echo "Invalid message payload";
	exit;
}

if ($conn instanceof mysqli) {
	$sentAtUnix = time();
	$hasSentAtUnix = false;
	$sentAtColumn = $conn->query("SHOW COLUMNS FROM messages LIKE 'sent_at_unix'");
	if ($sentAtColumn && $sentAtColumn->num_rows > 0) {
		$hasSentAtUnix = true;
	} else {
		$conn->query("ALTER TABLE messages ADD COLUMN sent_at_unix INT UNSIGNED NULL AFTER created_at");
		$sentAtColumnRetry = $conn->query("SHOW COLUMNS FROM messages LIKE 'sent_at_unix'");
		if ($sentAtColumnRetry && $sentAtColumnRetry->num_rows > 0) {
			$hasSentAtUnix = true;
		}
	}

	if ($hasSentAtUnix) {
		$stmt = $conn->prepare("INSERT INTO messages(name, message, photo_path, sent_at_unix) VALUES(?, ?, ?, ?)");
	} else {
		$stmt = $conn->prepare("INSERT INTO messages(name, message, photo_path) VALUES(?, ?, ?)");
	}

	if (!$stmt) {
		error_log("Message prepare failed, using fallback storage: " . $conn->error);
		if (!storage_add_message($name, $msg, $photoPath)) {
			http_response_code(500);
			echo "Failed to send message";
			exit;
		}
		echo "ok";
		exit;
	}

	if ($hasSentAtUnix) {
		$stmt->bind_param("sssi", $name, $msg, $photoPath, $sentAtUnix);
	} else {
		$stmt->bind_param("sss", $name, $msg, $photoPath);
	}
	if (!$stmt->execute()) {
		error_log("Message insert failed, using fallback storage: " . $stmt->error);
		$stmt->close();

		if (!storage_add_message($name, $msg, $photoPath)) {
			http_response_code(500);
			echo "Failed to send message";
			exit;
		}

		echo "ok";
		exit;
	}
	$stmt->close();
} else {
	if (!storage_add_message($name, $msg, $photoPath)) {
		http_response_code(500);
		echo "Failed to send message";
		exit;
	}
}

echo "ok";
?>