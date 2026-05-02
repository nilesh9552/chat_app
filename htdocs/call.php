<?php
include "config.php";
include "chat_auth.php";
include "storage.php";

header("Content-Type: application/json; charset=UTF-8");

$currentUser = chat_require_auth(false);
$method = $_SERVER['REQUEST_METHOD'];

function call_json_error($message, $statusCode = 400) {
    http_response_code($statusCode);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function call_json_ok($state) {
    echo json_encode(['ok' => true, 'state' => $state]);
    exit;
}

function call_is_nilesh($name) {
    return strcasecmp(trim((string)$name), 'Nilesh') === 0;
}

function call_user_is_participant($state, $name) {
    $name = trim((string)$name);
    if ($name === '') {
        return false;
    }

    $caller = isset($state['caller']) ? trim((string)$state['caller']) : '';
    $callee = isset($state['callee']) ? trim((string)$state['callee']) : '';

    return strcasecmp($name, $caller) === 0 || ($callee !== '' && strcasecmp($name, $callee) === 0);
}

if ($method === 'GET') {
    $state = storage_get_call_state();
    $isParticipant = call_user_is_participant($state, $currentUser);
    $isJoinedCall = $state['status'] === 'connecting' || $state['status'] === 'connected';
    $isRingingCall = $state['status'] === 'ringing';
    $isSilentCall = !empty($state['is_silent']);

    if ($isSilentCall && !call_is_nilesh($currentUser)) {
        call_json_ok(storage_default_call_state());
    }

    // Keep ringing visible for join, but hide active call details from non-participants.
    // Only hide if user is NOT a participant (caller or callee)
    if ($isJoinedCall && !$isParticipant && !call_is_nilesh($currentUser)) {
        $state = storage_default_call_state();
    }

    // Allow ringing state to be visible to everyone so they can join
    // Don't hide ringing state

    call_json_ok($state);
}

if ($method !== 'POST') {
    call_json_error('Method not allowed', 405);
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$state = storage_get_call_state();

if ($action === 'start') {
    $isSilent = isset($_POST['silent']) && trim((string)$_POST['silent']) === '1';

    if (isset($state['status']) && $state['status'] !== 'idle') {
        call_json_error('Another call is already active', 409);
    }

    $state = storage_default_call_state();
    $state['status'] = 'ringing';
    $state['call_id'] = uniqid('call_', true);
    $state['caller'] = $currentUser;
    $state['callee'] = '';
    $state['is_silent'] = $isSilent;

    if (!storage_set_call_state($state)) {
        call_json_error('Unable to create call', 500);
    }

    call_json_ok(storage_get_call_state());
}

$callId = isset($_POST['call_id']) ? trim((string)$_POST['call_id']) : '';
$currentCallId = isset($state['call_id']) ? trim((string)$state['call_id']) : '';

if ($callId !== '' && $currentCallId !== '' && !hash_equals($currentCallId, $callId)) {
    call_json_error('Call is no longer active', 409);
}

if ($action === 'offer') {
    if (strcasecmp((string)$state['caller'], $currentUser) !== 0) {
        call_json_error('Only caller can send offer', 403);
    }

    $offerRaw = isset($_POST['offer']) ? (string)$_POST['offer'] : '';
    if ($offerRaw === '') {
        call_json_error('Missing offer payload');
    }

    $offer = json_decode($offerRaw, true);
    if (!is_array($offer)) {
        call_json_error('Invalid offer payload');
    }

    $state['offer'] = $offer;

    if (!storage_set_call_state($state)) {
        call_json_error('Failed to store offer', 500);
    }

    call_json_ok(storage_get_call_state());
}

if ($action === 'accept') {
    if ($state['status'] !== 'ringing') {
        call_json_error('Call is not ringing', 409);
    }

    if (strcasecmp((string)$state['caller'], $currentUser) === 0) {
        call_json_error('Caller cannot join as callee', 403);
    }

    $existingCallee = isset($state['callee']) ? trim((string)$state['callee']) : '';
    if ($existingCallee !== '' && strcasecmp($existingCallee, $currentUser) !== 0) {
        call_json_error('Another user already joined', 409);
    }

    $state['callee'] = $currentUser;
    $state['status'] = 'connecting';

    if (!storage_set_call_state($state)) {
        call_json_error('Failed to accept call', 500);
    }

    call_json_ok(storage_get_call_state());
}

if ($action === 'answer') {
    if (strcasecmp((string)$state['callee'], $currentUser) !== 0) {
        call_json_error('Only callee can send answer', 403);
    }

    $answerRaw = isset($_POST['answer']) ? (string)$_POST['answer'] : '';
    if ($answerRaw === '') {
        call_json_error('Missing answer payload');
    }

    $answer = json_decode($answerRaw, true);
    if (!is_array($answer)) {
        call_json_error('Invalid answer payload');
    }

    $state['answer'] = $answer;
    $state['status'] = 'connected';

    if (!storage_set_call_state($state)) {
        call_json_error('Failed to store answer', 500);
    }

    call_json_ok(storage_get_call_state());
}

if ($action === 'candidate') {
    $candidateRaw = isset($_POST['candidate']) ? (string)$_POST['candidate'] : '';
    if ($candidateRaw === '') {
        call_json_error('Missing candidate payload');
    }

    $candidate = json_decode($candidateRaw, true);
    if (!is_array($candidate)) {
        call_json_error('Invalid candidate payload');
    }

    if (strcasecmp((string)$state['caller'], $currentUser) === 0) {
        $state['caller_candidates'][] = $candidate;
    } elseif (strcasecmp((string)$state['callee'], $currentUser) === 0) {
        $state['callee_candidates'][] = $candidate;
    } else {
        call_json_error('Only joined users can send candidate', 403);
    }

    if (!storage_set_call_state($state)) {
        call_json_error('Failed to store candidate', 500);
    }

    call_json_ok(storage_get_call_state());
}

if ($action === 'end' || $action === 'decline' || $action === 'cancel') {
    if (!call_is_nilesh($currentUser)) {
        call_json_error('Only Nilesh can end call', 403);
    }

    if (!storage_reset_call_state()) {
        call_json_error('Failed to reset call', 500);
    }

    call_json_ok(storage_get_call_state());
}

call_json_error('Unknown action');
?>