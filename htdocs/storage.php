<?php
function storage_dir_path() {
    static $resolvedDir = null;

    if ($resolvedDir !== null) {
        return $resolvedDir;
    }

    $candidates = [
        __DIR__ . DIRECTORY_SEPARATOR . 'storage',
        __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . '.storage',
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'chat-app-storage'
    ];

    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (is_dir($dir) && is_writable($dir)) {
            $resolvedDir = $dir;
            return $resolvedDir;
        }
    }

    $resolvedDir = __DIR__;
    return $resolvedDir;
}

function storage_messages_file() {
    return storage_dir_path() . DIRECTORY_SEPARATOR . 'messages.json';
}

function storage_clock_file() {
    return storage_dir_path() . DIRECTORY_SEPARATOR . 'clock.json';
}

function storage_presence_file() {
    return storage_dir_path() . DIRECTORY_SEPARATOR . 'presence.json';
}

function storage_call_file() {
    return storage_dir_path() . DIRECTORY_SEPARATOR . 'call.json';
}

function storage_read_json($filePath, $defaultValue) {
    if (!is_file($filePath)) {
        return $defaultValue;
    }

    $content = @file_get_contents($filePath);
    if ($content === false || $content === '') {
        return $defaultValue;
    }

    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return $defaultValue;
    }

    return $decoded;
}

function storage_write_json($filePath, $data) {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return @file_put_contents($filePath, $json, LOCK_EX) !== false;
}

function storage_get_messages() {
    $messages = storage_read_json(storage_messages_file(), []);
    if (!is_array($messages)) {
        return [];
    }

    return $messages;
}

function storage_add_message($name, $message, $photoPath) {
    $messages = storage_get_messages();
    $nextId = 1;
    $nowUnix = time();

    if (!empty($messages)) {
        $last = end($messages);
        $lastId = isset($last['id']) ? (int)$last['id'] : 0;
        $nextId = $lastId + 1;
        reset($messages);
    }

    $messages[] = [
        'id' => $nextId,
        'name' => $name,
        'message' => $message,
        'photo_path' => $photoPath,
        'created_at' => date('Y-m-d H:i:s'),
        'sent_at_unix' => $nowUnix,
        'seen' => 0
    ];

    return storage_write_json(storage_messages_file(), $messages);
}

function storage_mark_seen_by_viewer($viewerName) {
    $viewerName = trim((string)$viewerName);
    if ($viewerName === '') {
        return false;
    }

    $messages = storage_get_messages();
    $updated = false;

    foreach ($messages as &$message) {
        $sender = isset($message['name']) ? (string)$message['name'] : '';
        $isSeen = isset($message['seen']) ? (int)$message['seen'] : 0;

        if ($sender !== '' && strcasecmp($sender, $viewerName) !== 0 && $isSeen === 0) {
            $message['seen'] = 1;
            $updated = true;
        }
    }
    unset($message);

    if (!$updated) {
        return true;
    }

    return storage_write_json(storage_messages_file(), $messages);
}

function storage_clear_messages() {
    $messages = storage_get_messages();
    storage_write_json(storage_messages_file(), []);
    return $messages;
}

function storage_get_clock_time() {
    $clock = storage_read_json(storage_clock_file(), ['clock_time' => '12:00:00']);
    $value = isset($clock['clock_time']) ? (string)$clock['clock_time'] : '12:00:00';

    if (!preg_match('/^([01]\\d|2[0-3]):[0-5]\\d:[0-5]\\d$/', $value)) {
        return '12:00:00';
    }

    return $value;
}

function storage_set_clock_time($clockTime) {
    return storage_write_json(storage_clock_file(), ['clock_time' => $clockTime]);
}

function storage_update_presence($role, $displayName) {
    $role = trim((string)$role);
    $displayName = trim((string)$displayName);

    if ($role === '') {
        return false;
    }

    $presence = storage_read_json(storage_presence_file(), []);
    if (!is_array($presence)) {
        $presence = [];
    }

    $presence[$role] = [
        'display_name' => $displayName,
        'last_seen' => time()
    ];

    return storage_write_json(storage_presence_file(), $presence);
}

function storage_get_presence($role) {
    $role = trim((string)$role);
    if ($role === '') {
        return null;
    }

    $presence = storage_read_json(storage_presence_file(), []);
    if (!is_array($presence) || !isset($presence[$role]) || !is_array($presence[$role])) {
        return null;
    }

    return $presence[$role];
}

function storage_default_call_state() {
    return [
        'status' => 'idle',
        'call_id' => '',
        'caller' => '',
        'callee' => '',
        'is_silent' => false,
        'offer' => null,
        'answer' => null,
        'caller_candidates' => [],
        'callee_candidates' => [],
        'updated_at' => 0
    ];
}

function storage_get_call_state() {
    $call = storage_read_json(storage_call_file(), storage_default_call_state());
    if (!is_array($call)) {
        return storage_default_call_state();
    }

    return array_merge(storage_default_call_state(), $call);
}

function storage_set_call_state($state) {
    if (!is_array($state)) {
        return false;
    }

    $payload = array_merge(storage_default_call_state(), $state);
    $payload['updated_at'] = time();

    return storage_write_json(storage_call_file(), $payload);
}

function storage_reset_call_state() {
    return storage_set_call_state(storage_default_call_state());
}
