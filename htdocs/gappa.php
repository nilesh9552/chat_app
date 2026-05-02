<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>


<?php
include "config.php";
include "chat_auth.php";

$name = chat_require_auth();
?>

<!DOCTYPE html>
<html>

<head>
<title>WhatsApp</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="./css/style.css?v=<?php echo (int) @filemtime(__DIR__ . '/css/style.css'); ?>">
<script src="./js/gappa.js?v=<?php echo (int) @filemtime(__DIR__ . '/js/gappa.js'); ?>"></script>
</head>

<body>

<div class="mobile-shell">
	<header class="chat-header">
		<div class="avatar-pill"><?php echo strtoupper(substr($name, 0, 1)); ?></div>
		<div class="header-meta">
			<div class="room-name" id="peer-name">Contact</div>
			<div class="user-name" id="presence-status">offline</div>
		</div>
		<div class="header-tools">
			<button class="call-btn" type="button" onclick="startVoiceCall(false)" title="Start call">Call</button>
			<button class="call-btn" type="button" onclick="refreshCallState()" title="Refresh call state">Refresh</button>
			<div class="clock-dropdown" id="clock-dropdown">
				<button class="clock-trigger-btn" type="button" onclick="toggleClockDropdown(event)" title="Clock">Clock</button>
				<div class="clock-popover" id="clock-popover" hidden>
					<div class="clock-label">Shared Clock</div>
					<div id="clock-display" class="clock-display">00:00:00</div>
					<div class="clock-controls">
						<input type="time" id="clock-input" step="1">
						<button type="button" onclick="setClockTime()">Set</button>
					</div>
				</div>
			</div>
			<button class="clear-chat-btn" type="button" onclick="clearChatManually()" title="Clear chat">Clear</button>
		</div>
	</header>

	<div class="active-users" id="active-users"></div>

	<div class="call-banner" id="call-banner" hidden>
		<span id="call-status-text">Call is idle</span>
		<div class="call-actions">
			<button type="button" id="call-accept-btn" onclick="acceptIncomingCall()" hidden>Join</button>
			<button type="button" id="call-decline-btn" onclick="declineIncomingCall()" hidden>Decline</button>
			<button type="button" id="call-cancel-btn" onclick="cancelOutgoingCall()" hidden>Cancel</button>
			<button type="button" id="call-end-btn" onclick="endCurrentCall()" hidden>End</button>
		</div>
	</div>

	<div id="chat-box" class="chat-box"></div>

	<div class="composer">
		<input type="text" id="msg" placeholder="Type a message">
		<label class="attach-btn" for="photo">+</label>
		<input type="file" id="photo" accept="image/*,video/*,.pdf,application/pdf">
		<button class="send-btn" type="button" onclick="sendMsg()" aria-label="Send message" title="Send message">&#10148;</button>
	</div>
</div>

<input type="hidden" id="name" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
<input type="hidden" id="clear-on-load" value="<?php echo !empty($clearOnLoadEnabled) ? '1' : '0'; ?>">
<input type="hidden" id="clear-token" value="<?php echo htmlspecialchars((string)$adminClearToken, ENT_QUOTES, 'UTF-8'); ?>">

<audio id="remote-audio" autoplay playsinline></audio>

<script>
(function () {
	const navEntry = performance.getEntriesByType("navigation")[0];
	const isReload = (navEntry && navEntry.type === "reload") || (performance.navigation && performance.navigation.type === 1);

	if (isReload) {
		window.location.replace("index.html");
	}
})();
</script>

</body>

</html>