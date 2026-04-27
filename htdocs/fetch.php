<?php
include "config.php";
include "chat_auth.php";
include "storage.php";

$currentUser = chat_require_auth(false);

if ($conn instanceof mysqli) {
    // Ensure seen column exists for read-receipt support on older databases.
    $columnResult = $conn->query("SHOW COLUMNS FROM messages LIKE 'seen'");
    if ($columnResult && $columnResult->num_rows === 0) {
        $conn->query("ALTER TABLE messages ADD COLUMN seen TINYINT(1) NOT NULL DEFAULT 0 AFTER created_at");
    }

    $hasSentAtUnix = false;
    $sentAtColumn = $conn->query("SHOW COLUMNS FROM messages LIKE 'sent_at_unix'");
    if ($sentAtColumn && $sentAtColumn->num_rows > 0) {
        $hasSentAtUnix = true;
    }

    $seenUpdateStmt = $conn->prepare("UPDATE messages SET seen = 1 WHERE seen = 0 AND LOWER(name) <> LOWER(?)");
    if ($seenUpdateStmt) {
        $seenUpdateStmt->bind_param("s", $currentUser);
        $seenUpdateStmt->execute();
        $seenUpdateStmt->close();
    }

    if ($hasSentAtUnix) {
        $result = $conn->query("SELECT name, message, photo_path, created_at, sent_at_unix, seen FROM messages ORDER BY id ASC");
    } else {
        $result = $conn->query("SELECT name, message, photo_path, created_at, seen FROM messages ORDER BY id ASC");
    }

    if (!$result) {
        error_log("Message fetch failed, using fallback storage: " . $conn->error);
        $rows = storage_get_messages();
    } else {
        $rows = [];
        while ($dbRow = $result->fetch_assoc()) {
            $rows[] = $dbRow;
        }
    }
} else {
    storage_mark_seen_by_viewer($currentUser);
    $rows = storage_get_messages();
}

foreach ($rows as $row) {
    $rawSender = isset($row['name']) ? (string)$row['name'] : '';
    $sender = htmlspecialchars($rawSender, ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars((string)$row['message'], ENT_QUOTES, 'UTF-8');
    $photoPath = isset($row['photo_path']) ? trim($row['photo_path']) : '';
    $createdAtRaw = isset($row['created_at']) ? trim((string)$row['created_at']) : '';
    $sentAtUnix = isset($row['sent_at_unix']) ? (int)$row['sent_at_unix'] : 0;
    $isSeen = isset($row['seen']) ? (int)$row['seen'] === 1 : false;
    $content = "";
    $isOwnerMessage = isset($chatOwnerName) && $chatOwnerName !== '' && strcasecmp($rawSender, $chatOwnerName) === 0;
    $isUserMessage = isset($chatUserName) && $chatUserName !== '' && strcasecmp($rawSender, $chatUserName) === 0;

    if ($isOwnerMessage) {
        $rowClass = 'msg-row me';
        $bubbleClass = 'msg-bubble me';
    } elseif ($isUserMessage) {
        $rowClass = 'msg-row them';
        $bubbleClass = 'msg-bubble them';
    } else {
        // Fallback behavior for any third sender name.
        $isOwn = $currentUser !== '' && strcasecmp($rawSender, $currentUser) === 0;
        $rowClass = $isOwn ? 'msg-row me' : 'msg-row them';
        $bubbleClass = $isOwn ? 'msg-bubble me' : 'msg-bubble them';
    }

    if ($message !== '') {
        $content .= "<div class=\"msg-text\">{$message}</div>";
    }

    if ($photoPath !== '') {
        $safePhoto = htmlspecialchars($photoPath, ENT_QUOTES, 'UTF-8');
        $ext = strtolower((string)pathinfo($photoPath, PATHINFO_EXTENSION));
        $fileUrl = "uploads/{$safePhoto}";

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $content .= "<div><img class=\"chat-photo\" src=\"{$fileUrl}\" alt=\"Shared image\"></div>";
        } elseif (in_array($ext, ['mp4', 'webm', 'ogv'], true)) {
            $content .= "<div><video class=\"chat-video\" controls preload=\"metadata\" src=\"{$fileUrl}\"></video></div>";
        } elseif ($ext === 'pdf') {
            $content .= "<div><a class=\"chat-pdf\" href=\"{$fileUrl}\" target=\"_blank\" rel=\"noopener noreferrer\">Open PDF</a></div>";
        } else {
            $content .= "<div><a class=\"chat-pdf\" href=\"{$fileUrl}\" target=\"_blank\" rel=\"noopener noreferrer\">Open attachment</a></div>";
        }
    }

    if ($sentAtUnix <= 0 && $createdAtRaw !== '') {
        $fallbackStamp = strtotime($createdAtRaw);
        if ($fallbackStamp !== false) {
            $sentAtUnix = $fallbackStamp;
        }
    }

    $timeLabel = $sentAtUnix > 0 ? date('H:i', $sentAtUnix) : '';

    $safeTimeLabel = htmlspecialchars($timeLabel, ENT_QUOTES, 'UTF-8');
    $timeDataAttr = $sentAtUnix > 0 ? ' data-ts="' . (int)$sentAtUnix . '"' : '';
    $isOwnMessage = $currentUser !== '' && strcasecmp($rawSender, $currentUser) === 0;
    $statusClass = $isSeen ? 'msg-status seen' : 'msg-status';
    $statusHtml = $isOwnMessage ? "<span class=\"{$statusClass}\" aria-label=\"Seen status\">✓✓</span>" : '';
    $metaHtml = ($safeTimeLabel !== '' || $statusHtml !== '')
        ? "<div class=\"msg-meta\"><span class=\"msg-time\"{$timeDataAttr}>{$safeTimeLabel}</span>{$statusHtml}</div>"
        : '';

    echo "<div class=\"{$rowClass}\"><div class=\"{$bubbleClass}\"><div class=\"msg-sender\">{$sender}</div>{$content}{$metaHtml}</div></div>";
}
?>