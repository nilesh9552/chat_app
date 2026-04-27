<?php
include "config.php";
include "chat_auth.php";
include "storage.php";

header("Content-Type: application/json; charset=UTF-8");

$currentUser = chat_require_auth(false);
$currentRole = chat_get_current_role();

if ($currentRole === '') {
    if (strcasecmp($currentUser, (string)$chatOwnerName) === 0) {
        $currentRole = 'owner';
    } elseif (strcasecmp($currentUser, (string)$chatUserName) === 0) {
        $currentRole = 'user';
    }
}

$peerRole = '';
$peerNameFallback = 'Contact';
if ($currentRole === 'owner') {
    $peerRole = 'user';
    $peerNameFallback = $chatUserName !== '' ? $chatUserName : 'User';
} elseif ($currentRole === 'user') {
    $peerRole = 'owner';
    $peerNameFallback = $chatOwnerName !== '' ? $chatOwnerName : 'Owner';
}

$onlineWindowSeconds = 20;
$isOnline = false;
$peerName = $peerNameFallback;
$presenceMap = [];
$dbPresenceReady = false;

if ($conn instanceof mysqli) {
    $createPresenceTableSql = "
        CREATE TABLE IF NOT EXISTS user_presence (
            role_name VARCHAR(20) PRIMARY KEY,
            display_name VARCHAR(100) NOT NULL,
            last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ";
    $tableReady = $conn->query($createPresenceTableSql) !== false;
    $dbPresenceReady = $tableReady;

    if ($tableReady && $currentRole !== '') {
        $upsertSql = "
            INSERT INTO user_presence(role_name, display_name, last_seen)
            VALUES(?, ?, NOW())
            ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), last_seen = NOW()
        ";
        $upsertStmt = $conn->prepare($upsertSql);
        if ($upsertStmt) {
            $upsertStmt->bind_param("ss", $currentRole, $currentUser);
            $upsertStmt->execute();
            $upsertStmt->close();
        }
    }

    if ($tableReady) {
        $rowsResult = $conn->query("SELECT role_name, display_name, last_seen FROM user_presence WHERE role_name IN ('owner','user')");
        if ($rowsResult) {
            while ($row = $rowsResult->fetch_assoc()) {
                $roleName = isset($row['role_name']) ? trim((string)$row['role_name']) : '';
                if ($roleName === '') {
                    continue;
                }

                $presenceMap[$roleName] = [
                    'display_name' => isset($row['display_name']) ? trim((string)$row['display_name']) : '',
                    'last_seen' => isset($row['last_seen']) ? (string)$row['last_seen'] : ''
                ];
            }
        }
    }
}

// Live-hosting fallback: use JSON presence when DB presence table is unavailable.
if (!$dbPresenceReady) {
    if ($currentRole !== '') {
        storage_update_presence($currentRole, $currentUser);
    }

    foreach (['owner', 'user'] as $roleName) {
        $presenceRow = storage_get_presence($roleName);
        if (!is_array($presenceRow)) {
            continue;
        }

        $presenceMap[$roleName] = [
            'display_name' => isset($presenceRow['display_name']) ? trim((string)$presenceRow['display_name']) : '',
            'last_seen' => isset($presenceRow['last_seen']) ? (int)$presenceRow['last_seen'] : 0
        ];
    }
}

// Ensure current logged-in user is immediately reflected as online in response.
if ($currentRole !== '') {
    $presenceMap[$currentRole] = [
        'display_name' => $currentUser,
        'last_seen' => time()
    ];
}

$activeUsers = [];
foreach (['owner', 'user'] as $roleName) {
    $fallbackName = $roleName === 'owner'
        ? ($chatOwnerName !== '' ? $chatOwnerName : 'Owner')
        : ($chatUserName !== '' ? $chatUserName : 'User');

    $row = isset($presenceMap[$roleName]) && is_array($presenceMap[$roleName]) ? $presenceMap[$roleName] : null;
    $displayName = $fallbackName;
    $lastSeenTs = 0;

    if ($row) {
        $candidateName = isset($row['display_name']) ? trim((string)$row['display_name']) : '';
        if ($candidateName !== '') {
            $displayName = $candidateName;
        }

        if ($dbPresenceReady) {
            $raw = isset($row['last_seen']) ? (string)$row['last_seen'] : '';
            if (is_numeric($raw)) {
                $lastSeenTs = (int)$raw;
            } else {
                $parsed = strtotime($raw);
                $lastSeenTs = $parsed !== false ? $parsed : 0;
            }
        } else {
            $lastSeenTs = isset($row['last_seen']) ? (int)$row['last_seen'] : 0;
        }
    }

    $online = $lastSeenTs > 0 && (time() - $lastSeenTs) <= $onlineWindowSeconds;
    $activeUsers[] = [
        'role' => $roleName,
        'name' => $displayName,
        'is_online' => $online,
        'status' => $online ? 'online' : 'offline'
    ];

    if ($peerRole !== '' && $roleName === $peerRole) {
        $peerName = $displayName;
        $isOnline = $online;
    }
}

echo json_encode([
    'peer_name' => $peerName,
    'is_online' => $isOnline,
    'status' => $isOnline ? 'online' : 'offline',
    'active_users' => $activeUsers
]);
?>