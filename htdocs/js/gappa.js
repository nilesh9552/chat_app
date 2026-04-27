let clockDate = null;
let clockTickId = null;
let isClockInputFocused = false;
let isClockInputDirty = false;
let peerDisplayName = "Contact";

let callState = null;
let callPollId = null;
let peerConnection = null;
let localStream = null;
let remoteStream = null;
let activeCallId = "";
let isCallInitiator = false;
let receivedCallerCandidates = 0;
let receivedCalleeCandidates = 0;
let remoteAnswerApplied = false;
let autoJoiningCallId = "";
let forceIdleCallUi = false;
let activeSilentCall = false;

const rtcConfig = {
    iceServers: [
        { urls: ["stun:stun.l.google.com:19302"] }
    ]
};

const RTCPeerConnectionCtor = window.RTCPeerConnection || window.webkitRTCPeerConnection || window.mozRTCPeerConnection;
const RTCSessionDescriptionCtor = window.RTCSessionDescription || window.webkitRTCSessionDescription || window.mozRTCSessionDescription;
const RTCIceCandidateCtor = window.RTCIceCandidate || window.webkitRTCIceCandidate || window.mozRTCIceCandidate;

function hasAudioCaptureSupport() {
    return !!(
        (navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === "function") ||
        navigator.getUserMedia ||
        navigator.webkitGetUserMedia ||
        navigator.mozGetUserMedia
    );
}

function isSecureMediaContext() {
    if (window.isSecureContext) {
        return true;
    }

    return location.hostname === "localhost" || location.hostname === "127.0.0.1";
}

function getUserMediaCompat(constraints) {
    if (navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === "function") {
        return navigator.mediaDevices.getUserMedia(constraints);
    }

    const legacyGetUserMedia = navigator.getUserMedia || navigator.webkitGetUserMedia || navigator.mozGetUserMedia;
    if (!legacyGetUserMedia) {
        return Promise.reject(new Error("Audio capture is not supported"));
    }

    return new Promise(function (resolve, reject) {
        legacyGetUserMedia.call(navigator, constraints, resolve, reject);
    });
}

function toRtcSessionDescription(payload) {
    if (!payload || typeof payload !== "object") {
        return payload;
    }

    return RTCSessionDescriptionCtor ? new RTCSessionDescriptionCtor(payload) : payload;
}

function toRtcIceCandidate(payload) {
    if (!payload || typeof payload !== "object") {
        return payload;
    }

    return RTCIceCandidateCtor ? new RTCIceCandidateCtor(payload) : payload;
}

function getCurrentUserName() {
    const nameInput = document.getElementById("name");
    return nameInput ? (nameInput.value || "") : "";
}

function canCurrentUserEndCall() {
    return getCurrentUserName().trim().toLowerCase() === "nilesh";
}

function isSilentCallState(state) {
    return !!(state && (state.is_silent === true || state.is_silent === 1 || state.is_silent === "1"));
}

function setCallBanner(statusText, showBanner) {
    const banner = document.getElementById("call-banner");
    const textNode = document.getElementById("call-status-text");
    if (!banner || !textNode) {
        return;
    }

    textNode.textContent = statusText;
    banner.hidden = !showBanner;
}

function toggleCallActionButtons(options) {
    const acceptBtn = document.getElementById("call-accept-btn");
    const declineBtn = document.getElementById("call-decline-btn");
    const cancelBtn = document.getElementById("call-cancel-btn");
    const endBtn = document.getElementById("call-end-btn");

    if (!acceptBtn || !declineBtn || !cancelBtn || !endBtn) {
        return;
    }

    acceptBtn.hidden = !options.accept;
    declineBtn.hidden = !options.decline;
    cancelBtn.hidden = !options.cancel;
    endBtn.hidden = !options.end;
}

function resetCallRuntimeState() {
    activeCallId = "";
    isCallInitiator = false;
    receivedCallerCandidates = 0;
    receivedCalleeCandidates = 0;
    remoteAnswerApplied = false;
}

function cleanupMediaTracks() {
    if (localStream) {
        localStream.getTracks().forEach(function (track) {
            track.stop();
        });
    }

    if (peerConnection) {
        peerConnection.close();
    }

    localStream = null;
    remoteStream = null;
    peerConnection = null;

    const remoteAudio = document.getElementById("remote-audio");
    if (remoteAudio) {
        remoteAudio.srcObject = null;
    }
}

async function ensureLocalAudioStream() {
    if (localStream) {
        return localStream;
    }

    try {
        localStream = await getUserMediaCompat({ audio: true, video: false });
        return localStream;
    } catch (err) {
        const mediaErrorName = err && err.name ? String(err.name) : "";
        const mediaErrorMessage = err && err.message ? String(err.message) : "";
        const notFound = mediaErrorName === "NotFoundError" || /requested device not found/i.test(mediaErrorMessage);

        if (notFound) {
            // Allow joining in receive-only mode when no microphone is available.
            return null;
        }

        throw err;
    }
}

function createPeerConnectionForCall(callId, initiator) {
    if (peerConnection) {
        return peerConnection;
    }

    activeCallId = callId;
    isCallInitiator = initiator;
    peerConnection = new RTCPeerConnectionCtor(rtcConfig);

    peerConnection.onicecandidate = function (event) {
        if (!event.candidate || !activeCallId) {
            return;
        }

        postCallAction("candidate", {
            call_id: activeCallId,
            candidate: JSON.stringify(event.candidate)
        }).catch(function () {
            // Ignore transient candidate signaling failures.
        });
    };

    peerConnection.ontrack = function (event) {
        if (!remoteStream) {
            remoteStream = new MediaStream();
        }

        event.streams[0].getTracks().forEach(function (track) {
            remoteStream.addTrack(track);
        });

        const remoteAudio = document.getElementById("remote-audio");
        if (remoteAudio) {
            remoteAudio.srcObject = remoteStream;
            remoteAudio.play().catch(function () {
                // Playback may require user interaction on some browsers.
            });
        }
    };

    peerConnection.onconnectionstatechange = function () {
        if (!peerConnection) {
            return;
        }

        const state = peerConnection.connectionState;
        if (state === "connected") {
            if (canCurrentUserEndCall()) {
                setCallBanner("Voice call connected", true);
                toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: true });
            } else {
                setCallBanner("", false);
                toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: false });
            }
            return;
        }

        if (state === "failed" || state === "disconnected" || state === "closed") {
            cleanupMediaTracks();
            resetCallRuntimeState();
            setCallBanner("Call ended", false);
            toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: false });
        }
    };

    return peerConnection;
}

async function attachLocalTracks(callId, initiator) {
    const stream = await ensureLocalAudioStream();
    const pc = createPeerConnectionForCall(callId, initiator);

    if (!stream) {
        return;
    }

    stream.getTracks().forEach(function (track) {
        pc.addTrack(track, stream);
    });
}

function applyRemoteCandidatesFromState(state) {
    if (!peerConnection || !state) {
        return;
    }

    if (isCallInitiator) {
        const candidates = Array.isArray(state.callee_candidates) ? state.callee_candidates : [];
        for (let i = receivedCalleeCandidates; i < candidates.length; i += 1) {
            peerConnection.addIceCandidate(toRtcIceCandidate(candidates[i])).catch(function () {
                // Ignore malformed or stale candidates.
            });
        }
        receivedCalleeCandidates = candidates.length;
    } else {
        const candidates = Array.isArray(state.caller_candidates) ? state.caller_candidates : [];
        for (let i = receivedCallerCandidates; i < candidates.length; i += 1) {
            peerConnection.addIceCandidate(toRtcIceCandidate(candidates[i])).catch(function () {
                // Ignore malformed or stale candidates.
            });
        }
        receivedCallerCandidates = candidates.length;
    }
}

async function postCallAction(action, payload) {
    const params = new URLSearchParams();
    params.set("action", action);

    Object.keys(payload || {}).forEach(function (key) {
        params.set(key, payload[key]);
    });

    const response = await fetch("call.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: params.toString()
    });

    const raw = await response.text();
    let parsed = null;
    try {
        parsed = JSON.parse(raw);
    } catch (err) {
        throw new Error("Invalid call response");
    }

    if (!response.ok || !parsed.ok) {
        throw new Error(parsed && parsed.error ? parsed.error : "Call action failed");
    }

    return parsed.state;
}

async function fetchCallState() {
    const response = await fetch("call.php", { cache: "no-store" });
    const raw = await response.text();
    let parsed = null;

    try {
        parsed = JSON.parse(raw);
    } catch (err) {
        return null;
    }

    if (!response.ok || !parsed || !parsed.ok) {
        return null;
    }

    return parsed.state;
}

async function startVoiceCall(silentMode) {
    try {
        if (!isSecureMediaContext()) {
            alert("Voice call requires HTTPS. Open this site on HTTPS or localhost.");
            return;
        }

        if (!RTCPeerConnectionCtor || !hasAudioCaptureSupport()) {
            alert("Voice call is not supported. Use latest Chrome/Edge/Firefox.");
            return;
        }

        const useSilentMode = !!silentMode;
        const state = await postCallAction("start", { silent: useSilentMode ? "1" : "0" });
        callState = state;
        activeSilentCall = useSilentMode;

        if (useSilentMode) {
            setCallBanner("", false);
            toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: canCurrentUserEndCall() });
        } else {
            setCallBanner("Calling...", true);
            toggleCallActionButtons({ accept: false, decline: false, cancel: true, end: false });
        }

        await attachLocalTracks(state.call_id, true);
        const offer = await peerConnection.createOffer({ offerToReceiveAudio: true });
        await peerConnection.setLocalDescription(offer);

        await postCallAction("offer", {
            call_id: state.call_id,
            offer: JSON.stringify(offer)
        });

        if (!localStream && !useSilentMode) {
            setCallBanner("No microphone found. Joined in listen-only mode.", true);
        }
    } catch (err) {
        const message = err && err.message ? String(err.message) : "";
        if (/requested device not found/i.test(message)) {
            alert("Microphone not found on this device. Connect/enable a mic and retry.");
            return;
        }

        alert(message || "Unable to start call");
    }
}

async function acceptIncomingCall() {
    try {
        if (!callState || !callState.call_id) {
            return;
        }

        await postCallAction("accept", { call_id: callState.call_id });
        await attachLocalTracks(callState.call_id, false);

        if (!callState.offer) {
            throw new Error("Offer not available yet");
        }

        await peerConnection.setRemoteDescription(toRtcSessionDescription(callState.offer));
        applyRemoteCandidatesFromState(callState);

        const answer = await peerConnection.createAnswer();
        await peerConnection.setLocalDescription(answer);

        await postCallAction("answer", {
            call_id: callState.call_id,
            answer: JSON.stringify(answer)
        });

        if (!canCurrentUserEndCall()) {
            forceIdleCallUi = true;
            setCallBanner("Call is idle", true);
        } else if (!localStream) {
            setCallBanner("Joined in listen-only mode (no microphone).", true);
        } else {
            setCallBanner("Call connecting...", true);
        }
        toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: canCurrentUserEndCall() });
    } catch (err) {
        const message = err && err.message ? String(err.message) : "";
        if (/requested device not found/i.test(message)) {
            alert("Microphone not found on this device. Connect/enable a mic and retry.");
            return;
        }

        alert(message || "Unable to accept call");
    }
}

function shouldAutoJoinIncomingCall(state) {
    return false;
}

async function declineIncomingCall() {
    try {
        if (callState && callState.call_id) {
            await postCallAction("decline", { call_id: callState.call_id });
        }
    } catch (err) {
        // Ignore decline errors and clear local state.
    }

    cleanupMediaTracks();
    resetCallRuntimeState();
    setCallBanner("Call declined", false);
    toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: false });
}

async function cancelOutgoingCall() {
    try {
        if (callState && callState.call_id) {
            await postCallAction("cancel", { call_id: callState.call_id });
        }
    } catch (err) {
        // Ignore cancel errors and clear local state.
    }

    cleanupMediaTracks();
    resetCallRuntimeState();
    setCallBanner("Call cancelled", false);
    toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: false });
}

async function endCurrentCall() {
    try {
        if (callState && callState.call_id) {
            await postCallAction("end", { call_id: callState.call_id });
        }
    } catch (err) {
        // Ignore end errors and clear local state.
    }

    cleanupMediaTracks();
    resetCallRuntimeState();
    setCallBanner("Call ended", false);
    toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: false });
}

async function syncCallUiFromState(state) {
    callState = state;
    activeSilentCall = isSilentCallState(state);

    if (!state || state.status !== "ringing") {
        autoJoiningCallId = "";
    }

    if (!state || state.status === "idle") {
        forceIdleCallUi = false;
        cleanupMediaTracks();
        resetCallRuntimeState();
        setCallBanner("Call is idle", false);
        toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: false });
        return;
    }

    const currentUser = getCurrentUserName();
    const callerName = (state.caller || "").toLowerCase();
    const calleeName = (state.callee || "").toLowerCase();
    const userName = currentUser.toLowerCase();
    const canEndCall = canCurrentUserEndCall();

    if (activeSilentCall) {
        forceIdleCallUi = !canEndCall;
        if (forceIdleCallUi) {
            setCallBanner("Call is idle", true);
            toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: false });
        } else {
            setCallBanner("", false);
            toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: true });
        }
        return;
    }

    if (forceIdleCallUi && !canEndCall) {
        setCallBanner("Call is idle", true);
        toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: false });
        return;
    }

    const isCaller = callerName === userName;
    const isCallee = calleeName !== "" && calleeName === userName;
    const hasCallee = calleeName !== "";

    if (isCaller) {
        if (state.status === "ringing") {
            setCallBanner("Calling... Waiting for someone to join", true);
            toggleCallActionButtons({ accept: false, decline: false, cancel: true, end: false });
        } else if (state.status === "connecting") {
            if (canEndCall) {
                setCallBanner("Call connecting...", true);
            } else {
                setCallBanner("", false);
            }
            toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: canEndCall });
        } else {
            if (canEndCall) {
                setCallBanner("Voice call active", true);
            } else {
                setCallBanner("", false);
            }
            toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: canEndCall });
        }

        if (peerConnection && state.answer && !remoteAnswerApplied) {
            await peerConnection.setRemoteDescription(toRtcSessionDescription(state.answer));
            remoteAnswerApplied = true;
        }
        applyRemoteCandidatesFromState(state);
        return;
    }

    if (state.status === "ringing") {
        setCallBanner("Incoming call from " + (state.caller || "Contact"), true);

        if (!hasCallee) {
            toggleCallActionButtons({ accept: true, decline: false, cancel: false, end: false });
        } else if (isCallee) {
            toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: false });
        } else {
            setCallBanner("Someone is joining the call...", true);
            toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: false });
        }
        return;
    }

    if (state.status === "connecting") {
        if (canEndCall) {
            setCallBanner("Call connecting...", true);
        } else {
            setCallBanner("", false);
        }
        if (isCallee || isCaller) {
            toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: canEndCall });
        } else {
            toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: false });
        }
        applyRemoteCandidatesFromState(state);
        return;
    }

    if (canEndCall) {
        setCallBanner("Voice call active", true);
    } else {
        setCallBanner("", false);
    }
    if (isCallee || isCaller) {
        toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: canEndCall });
    } else {
        toggleCallActionButtons({ accept: false, decline: false, cancel: false, end: false });
    }

    if (isCallee) {
        applyRemoteCandidatesFromState(state);
    }
}

async function loadCallState() {
    try {
        const state = await fetchCallState();
        if (!state) {
            return;
        }

        await syncCallUiFromState(state);

        if (shouldAutoJoinIncomingCall(state) && autoJoiningCallId !== state.call_id) {
            autoJoiningCallId = state.call_id;
            await acceptIncomingCall();
            return;
        }
    } catch (err) {
        // Ignore polling failures and retry in the next interval.
        autoJoiningCallId = "";
    }
}

function toggleClockDropdown(event) {
    if (event) {
        event.stopPropagation();
    }

    const popover = document.getElementById("clock-popover");
    if (!popover) {
        return;
    }

    popover.hidden = !popover.hidden;
}

function closeClockDropdown() {
    const popover = document.getElementById("clock-popover");
    if (!popover) {
        return;
    }

    popover.hidden = true;
}

function loadChat() {
    const nameInput = document.getElementById("name");
    const currentUser = nameInput ? nameInput.value : "";

    fetch("fetch.php?current_user=" + encodeURIComponent(currentUser))
    .then(res => res.text())
    .then(data => {
        const chatBox = document.getElementById("chat-box");
        const nearBottom = chatBox.scrollHeight - chatBox.scrollTop - chatBox.clientHeight < 120;

        chatBox.innerHTML = data;
        localizeMessageTimes();

        if (nearBottom || chatBox.scrollTop === 0) {
            chatBox.scrollTop = chatBox.scrollHeight;
        }
    });
}

function localizeMessageTimes() {
    const timeNodes = document.querySelectorAll(".msg-time[data-ts]");
    timeNodes.forEach(function (node) {
        const rawTs = node.getAttribute("data-ts");
        const unixTs = rawTs ? parseInt(rawTs, 10) : 0;
        if (!unixTs || Number.isNaN(unixTs)) {
            return;
        }

        const localDate = new Date(unixTs * 1000);
        node.textContent = localDate.toLocaleTimeString([], {
            hour: "2-digit",
            minute: "2-digit",
            hour12: false
        });
    });
}

function loadPresence() {
    fetch("presence.php", { cache: "no-store" })
    .then(res => {
        if (!res.ok) {
            throw new Error("Failed to load presence");
        }

        return res.json();
    })
    .then(data => {
        const peerName = document.getElementById("peer-name");
        const status = document.getElementById("presence-status");

        if (peerName && typeof data.peer_name === "string" && data.peer_name.trim() !== "") {
            peerName.textContent = data.peer_name;
            peerDisplayName = data.peer_name;
        } else {
            peerDisplayName = "Contact";
        }

        if (!status) {
            return;
        }

        const isOnline = !!data.is_online;
        status.textContent = isOnline ? "online" : "offline";
        status.classList.toggle("online", isOnline);
        status.classList.toggle("offline", !isOnline);

        const activeUsersBar = document.getElementById("active-users");
        if (activeUsersBar && Array.isArray(data.active_users)) {
            activeUsersBar.innerHTML = "";

            data.active_users.forEach(function (user) {
                const userPill = document.createElement("span");
                const online = !!user.is_online;
                userPill.className = "active-user-pill " + (online ? "online" : "offline");
                userPill.textContent = (user.name || "User") + " - " + (online ? "online" : "offline");
                activeUsersBar.appendChild(userPill);
            });
        }
    })
    .catch(() => {
        const status = document.getElementById("presence-status");
        if (!status) {
            return;
        }

        status.textContent = "offline";
        status.classList.remove("online");
        status.classList.add("offline");

        const activeUsersBar = document.getElementById("active-users");
        if (activeUsersBar) {
            activeUsersBar.innerHTML = "";
        }
    });
}

function clearChat() {
    const tokenInput = document.getElementById("clear-token");
    const token = tokenInput ? tokenInput.value : "";

    return fetch("clear.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: "token=" + encodeURIComponent(token)
    });
}

function clearChatManually() {
    const shouldClear = window.confirm("Clear all chat messages?");
    if (!shouldClear) {
        return;
    }

    clearChat()
        .then(async (res) => {
            if (!res.ok) {
                const errorText = (await res.text()) || "Failed to clear chat";
                throw new Error(errorText);
            }

            loadChat();
        })
        .catch((err) => {
            alert(err.message || "Failed to clear chat");
        });
}

function formatClock(date) {
    const h = String(date.getHours()).padStart(2, "0");
    const m = String(date.getMinutes()).padStart(2, "0");
    const s = String(date.getSeconds()).padStart(2, "0");
    return h + ":" + m + ":" + s;
}

function renderClock() {
    if (!clockDate) {
        return;
    }

    document.getElementById("clock-display").textContent = formatClock(clockDate);
    clockDate = new Date(clockDate.getTime() + 1000);
}

function loadClock() {
    fetch("clock.php")
    .then(res => {
        if (!res.ok) {
            throw new Error("Failed to load clock");
        }

        return res.json();
    })
    .then(data => {
        const time = data.clock_time || "12:00:00";
        const parts = time.split(":");
        const now = new Date();
        now.setHours(parseInt(parts[0], 10), parseInt(parts[1], 10), parseInt(parts[2], 10), 0);
        clockDate = now;

        const input = document.getElementById("clock-input");
        // Keep the user's in-progress edit intact instead of restoring old value.
        if (input && !isClockInputFocused && !isClockInputDirty) {
            input.value = time;
        }

        if (clockTickId) {
            clearInterval(clockTickId);
        }

        renderClock();
        clockTickId = setInterval(renderClock, 1000);
    })
    .catch(() => {
        if (!clockDate) {
            clockDate = new Date();
            renderClock();
        }
    });
}

function setClockTime() {
    const input = document.getElementById("clock-input");
    if (!input || !input.value) {
        return;
    }

    const value = input.value.length === 5 ? input.value + ":00" : input.value;

    fetch("clock.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: "clock_time=" + encodeURIComponent(value)
    }).then(() => {
        isClockInputDirty = false;
        loadClock();
    });
}

setInterval(loadChat, 1000);
setInterval(loadClock, 4000);
setInterval(loadPresence, 5000);

document.addEventListener("DOMContentLoaded", function () {
    const msgInput = document.getElementById("msg");
    const clockInput = document.getElementById("clock-input");
    const clearOnLoadInput = document.getElementById("clear-on-load");
    const clearOnLoadEnabled = clearOnLoadInput && clearOnLoadInput.value === "1";

    if (clearOnLoadEnabled) {
        clearChat().finally(function () {
            loadChat();
        });
    } else {
        loadChat();
    }
    loadClock();
    loadPresence();
    loadCallState();

    if (callPollId) {
        clearInterval(callPollId);
    }
    callPollId = setInterval(loadCallState, 1200);

    if (clockInput) {
        clockInput.addEventListener("input", function () {
            isClockInputDirty = true;
        });

        clockInput.addEventListener("focus", function () {
            isClockInputFocused = true;
        });

        clockInput.addEventListener("blur", function () {
            isClockInputFocused = false;
        });
    }

    if (!msgInput) {
        return;
    }

    msgInput.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
            event.preventDefault();
            sendMsg();
        }
    });

    document.addEventListener("click", function (event) {
        const dropdown = document.getElementById("clock-dropdown");
        if (!dropdown) {
            return;
        }

        if (!dropdown.contains(event.target)) {
            closeClockDropdown();
        }
    });
});

window.addEventListener("beforeunload", function () {
    const clearOnLoadInput = document.getElementById("clear-on-load");
    const tokenInput = document.getElementById("clear-token");
    const clearOnLoadEnabled = clearOnLoadInput && clearOnLoadInput.value === "1";
    const token = tokenInput ? tokenInput.value : "";

    if (!clearOnLoadEnabled) {
        return;
    }

    const payload = new URLSearchParams();
    payload.set("token", token);
    navigator.sendBeacon("clear.php", payload);
});

window.addEventListener("beforeunload", function () {
    if (!callState || !callState.call_id) {
        return;
    }

    const payload = new URLSearchParams();
    payload.set("action", "end");
    payload.set("call_id", callState.call_id);
    navigator.sendBeacon("call.php", payload);
});

function sendMsg() {
    const name = document.getElementById("name").value;
    const msgInput = document.getElementById("msg");
    const photoInput = document.getElementById("photo");
    const msg = msgInput.value;
    const photoFile = photoInput && photoInput.files.length ? photoInput.files[0] : null;

    if (msg.trim() === "" && !photoFile) {
        return;
    }

    const formData = new FormData();
    formData.append("name", name);
    formData.append("msg", msg);
    if (photoFile) {
        formData.append("photo", photoFile);
    }

    fetch("send.php", {
        method: "POST",
        body: formData
    }).then(async (res) => {
        if (!res.ok) {
            const errorText = (await res.text()) || "Failed to send message";
            throw new Error(errorText);
        }

        msgInput.value = "";
        if (photoInput) {
            photoInput.value = "";
        }
        loadChat();
        loadPresence();
    }).catch((err) => {
        alert(err.message || "Failed to send message");
    });
}