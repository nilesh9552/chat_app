<?php
function chat_start_session() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function chat_secret_is_configured() {
    global $chatOwnerSecret, $chatUserSecret;

    return $chatOwnerSecret !== '' || $chatUserSecret !== '';
}

function chat_is_valid_secret($secretName) {
    global $chatOwnerSecret, $chatUserSecret;

    $secretName = trim((string)$secretName);
    if ($secretName === '') {
        return false;
    }

    if ($chatOwnerSecret !== '' && hash_equals($chatOwnerSecret, $secretName)) {
        return true;
    }

    if ($chatUserSecret !== '' && hash_equals($chatUserSecret, $secretName)) {
        return true;
    }

    return false;
}

function chat_authenticate($secretName, $displayName = '') {
    global $chatOwnerSecret, $chatUserSecret, $chatOwnerName, $chatUserName;

    if (!chat_secret_is_configured()) {
        return [false, 'Chat secrets are not configured'];
    }

    $resolvedName = '';
    $resolvedRole = '';

    if ($chatOwnerSecret !== '' && hash_equals($chatOwnerSecret, $secretName)) {
        $resolvedName = $chatOwnerName !== '' ? $chatOwnerName : 'Owner';
        $resolvedRole = 'owner';
    } elseif ($chatUserSecret !== '' && hash_equals($chatUserSecret, $secretName)) {
        $resolvedName = $chatUserName !== '' ? $chatUserName : 'User';
        $resolvedRole = 'user';
    }

    if ($resolvedName === '') {
        return [false, 'Invalid secret name'];
    }

    $displayName = trim((string) $displayName);
    if ($displayName !== '') {
        if (function_exists('mb_strlen')) {
            $nameLength = mb_strlen($displayName);
        } else {
            $nameLength = strlen($displayName);
        }

        if ($nameLength > 100) {
            return [false, 'Name is too long'];
        }

        $resolvedName = $displayName;
    }

    chat_start_session();
    $_SESSION['chat_authenticated'] = true;
    $_SESSION['chat_user_name'] = $resolvedName;
    $_SESSION['chat_user_role'] = $resolvedRole;

    return [true, null];
}

function chat_require_auth($redirectOnFailure = true) {
    chat_start_session();

    if (!empty($_SESSION['chat_authenticated'])) {
        $userName = isset($_SESSION['chat_user_name']) ? trim((string) $_SESSION['chat_user_name']) : '';
        return $userName !== '' ? $userName : 'Owner';
    }

    if ($redirectOnFailure) {
        header('Location: index.html');
    } else {
        http_response_code(403);
        echo 'Unauthorized';
    }

    exit;
}

function chat_get_current_role() {
    chat_start_session();

    if (isset($_SESSION['chat_user_role'])) {
        $role = trim((string)$_SESSION['chat_user_role']);
        if ($role === 'owner' || $role === 'user') {
            return $role;
        }
    }

    return '';
}
?>