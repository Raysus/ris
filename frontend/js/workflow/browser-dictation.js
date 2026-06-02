/* =========================================
   Dictado por voz en el navegador (Web Speech API)
   ========================================= */

let _browserRecognition = null;
let _browserDictationActive = false;
let _browserDictationStopping = false;
let _browserDictationBaseText = "";
let _browserDictationSessionFinal = "";
let _browserDictationClickBusy = false;

function getSpeechRecognitionConstructor() {
    return window.SpeechRecognition || window.webkitSpeechRecognition || null;
}

function isBrowserDictationSupported() {
    if (!window.isSecureContext) {
        return false;
    }
    return !!getSpeechRecognitionConstructor() && !!navigator.mediaDevices?.getUserMedia;
}

function getBrowserDictationBlockReason() {
    if (!window.isSecureContext) {
        return "Abra el RIS con https:// (no http ni IP sin certificado).";
    }
    if (!getSpeechRecognitionConstructor()) {
        return "Use Chrome o Microsoft Edge en esta PC.";
    }
    if (!navigator.mediaDevices?.getUserMedia) {
        return "El navegador no permite acceso al micrófono.";
    }
    return null;
}

function isBrowserDictationActive() {
    return _browserDictationActive;
}

function getBrowserDictationLang() {
    return localStorage.getItem("ris_dictation_lang") || "es-CL";
}

function setBrowserDictationLang(lang) {
    localStorage.setItem("ris_dictation_lang", lang || "es-CL");
}

function setBrowserDictationStatusMessage(text, isError) {
    const $status = $("#browserDictationStatus");
    if (!$status.length) {
        return;
    }
    $status.removeClass("text-muted text-danger text-warning fw-bold");
    if (isError) {
        $status.addClass("text-danger fw-bold");
    } else {
        $status.addClass("text-muted");
    }
    $status.text(text);
}

function notifyBrowserDictation(msg, tipo) {
    setBrowserDictationStatusMessage(msg, tipo === "danger" || tipo === "warning");
    try {
        if (typeof showToast === "function") {
            showToast(msg, tipo || "info");
        }
    } catch (e) {
        console.warn("showToast:", e);
    }
}

function setBrowserDictationButtonBusy(busy, label) {
    const btn = document.getElementById("btnBrowserDictation");
    if (!btn) {
        return;
    }
    btn.disabled = !!busy;
    if (busy && label) {
        btn.innerHTML = label;
    } else if (!busy && !_browserDictationActive) {
        btn.innerHTML = '<i class="bi bi-mic-fill me-1"></i> INICIAR DICTADO VOZ';
    }
}

function updateBrowserDictationUi(active, hint) {
    const $btn = $("#btnBrowserDictation");
    const $status = $("#browserDictationStatus");
    if (!$btn.length) {
        return;
    }

    if (active) {
        $btn
            .removeClass("btn-outline-primary")
            .addClass("btn-primary")
            .html('<i class="bi bi-mic-mute-fill me-1"></i> DETENER DICTADO');
        if ($status.length) {
            $status
                .removeClass("text-muted")
                .addClass("text-danger fw-bold")
                .html(hint || '<i class="bi bi-record-circle me-1"></i> Escuchando… hable al micrófono');
        }
    } else {
        $btn
            .removeClass("btn-primary")
            .addClass("btn-outline-primary")
            .html('<i class="bi bi-mic-fill me-1"></i> INICIAR DICTADO VOZ');
        if (hint) {
            setBrowserDictationStatusMessage(hint, false);
        } else {
            refreshBrowserDictationButtonState();
        }
    }
}

function syncBrowserDictationToStudy() {
    const txt = $("#textoInforme");
    if (typeof currentRadioStudy !== "undefined" && currentRadioStudy && txt.length) {
        currentRadioStudy.reportText = txt.val();
    }
}

function renderBrowserDictationText(interimPiece) {
    const txt = document.getElementById("textoInforme");
    if (!txt) {
        return;
    }

    const combined = _browserDictationBaseText + _browserDictationSessionFinal + (interimPiece || "");
    txt.value = combined;
    const len = combined.length;
    txt.selectionStart = len;
    txt.selectionEnd = len;
    syncBrowserDictationToStudy();
}

function stopBrowserDictation(silent) {
    _browserDictationStopping = true;
    _browserDictationActive = false;
    _browserDictationClickBusy = false;

    if (typeof currentDictationMethod !== "undefined" && currentDictationMethod === "browser_stt") {
        currentDictationMethod = "teclado";
    }

    if (_browserRecognition) {
        try {
            _browserRecognition.onend = null;
            _browserRecognition.onerror = null;
            _browserRecognition.stop();
        } catch (e) {
            console.warn("stopBrowserDictation:", e);
        }
        _browserRecognition = null;
    }

    updateBrowserDictationUi(false);

    const txt = $("#textoInforme");
    txt.removeClass("border-primary border-2 shadow");

    if (!silent) {
        notifyBrowserDictation("Dictado por voz detenido.", "secondary");
    }

    _browserDictationStopping = false;
}

/** Solo pide permiso; libera el micrófono antes de SpeechRecognition (evita bloqueo en Chrome). */
async function ensureMicrophonePermissionForDictation() {
    const stream = await navigator.mediaDevices.getUserMedia({
        audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
        },
    });

    let label = "micrófono del sistema";
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const inputs = devices.filter((d) => d.kind === "audioinput");
        const defaultInput = inputs.find((d) => d.deviceId === "default") || inputs[0];
        if (defaultInput?.label) {
            label = defaultInput.label;
        }
    } catch (e) {
        console.warn("enumerateDevices:", e);
    }

    stream.getTracks().forEach((t) => t.stop());
    await new Promise((r) => setTimeout(r, 150));

    return { label };
}

function beginRecognitionSession(Ctor, micLabel) {
    const recognition = new Ctor();
    recognition.lang = getBrowserDictationLang();
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.maxAlternatives = 1;

    recognition.onstart = () => {
        _browserDictationActive = true;
        _browserDictationStopping = false;
        _browserDictationClickBusy = false;
        setBrowserDictationButtonBusy(false);
        if (typeof currentDictationMethod !== "undefined") {
            currentDictationMethod = "browser_stt";
        }
        updateBrowserDictationUi(true, `<i class="bi bi-record-circle me-1"></i> Escuchando (${micLabel})`);
        notifyBrowserDictation(`Dictado activo (${micLabel}). Hable al micrófono.`, "success");
    };

    recognition.onresult = (event) => {
        let interim = "";
        for (let i = event.resultIndex; i < event.results.length; i++) {
            const piece = event.results[i][0].transcript;
            if (event.results[i].isFinal) {
                _browserDictationSessionFinal += piece;
                if (!/\s$/.test(_browserDictationSessionFinal)) {
                    _browserDictationSessionFinal += " ";
                }
            } else {
                interim += piece;
            }
        }
        renderBrowserDictationText(interim);
    };

    recognition.onerror = (event) => {
        console.warn("browser dictation error:", event.error);
        const messages = {
            "not-allowed": "Micrófono bloqueado. Chrome → candado → Micrófono → Permitir.",
            "no-speech": "No se detectó voz. Hable más cerca del micrófono.",
            network: "Sin conexión al motor de voz (necesita Internet).",
            aborted: "Dictado interrumpido.",
            "audio-capture": "No hay micrófono. Revise Windows → Sonido → Entrada.",
            "service-not-allowed": "Dictado bloqueado por política del navegador.",
        };
        if (event.error !== "no-speech" && event.error !== "aborted") {
            notifyBrowserDictation(messages[event.error] || `Error: ${event.error}`, "warning");
            stopBrowserDictation(true);
        }
    };

    recognition.onend = () => {
        if (_browserDictationActive && !_browserDictationStopping) {
            try {
                recognition.start();
            } catch (e) {
                console.warn("recognition restart:", e);
                stopBrowserDictation(true);
            }
        } else if (!_browserDictationActive) {
            updateBrowserDictationUi(false);
        }
    };

    recognition.start();
    _browserRecognition = recognition;
}

async function startBrowserDictation() {
    const block = getBrowserDictationBlockReason();
    if (block) {
        notifyBrowserDictation(block, "danger");
        return false;
    }

    if (typeof currentRadioStudy === "undefined" || !currentRadioStudy) {
        notifyBrowserDictation("Seleccione un examen en la bandeja izquierda.", "warning");
        return false;
    }

    if (typeof stopAudioRecording === "function" && typeof isRecordingSessionActive === "function") {
        if (isRecordingSessionActive()) {
            stopAudioRecording();
        }
    }
    if (typeof dragonSyncInterval !== "undefined" && dragonSyncInterval) {
        clearInterval(dragonSyncInterval);
    }

    stopBrowserDictation(true);

    const txt = $("#textoInforme");
    txt.prop("disabled", false).focus();
    txt.addClass("border border-primary border-2 shadow").removeClass("border-0");

    _browserDictationBaseText = txt.val();
    if (_browserDictationBaseText.length && !/\s$/.test(_browserDictationBaseText)) {
        _browserDictationBaseText += " ";
    }
    _browserDictationSessionFinal = "";

    setBrowserDictationStatusMessage("Preparando micrófono…", false);

    let micInfo = { label: "micrófono" };
    try {
        micInfo = await ensureMicrophonePermissionForDictation();
    } catch (err) {
        console.error("ensureMicrophonePermissionForDictation:", err);
        _browserDictationClickBusy = false;
        notifyBrowserDictation(
            "No se pudo usar el micrófono. Permita el acceso en Chrome (candado en la barra).",
            "danger"
        );
        return false;
    }

    const Ctor = getSpeechRecognitionConstructor();
    try {
        beginRecognitionSession(Ctor, micInfo.label);
        return true;
    } catch (err) {
        console.error("startBrowserDictation:", err);
        _browserDictationClickBusy = false;
        notifyBrowserDictation(
            "No se pudo iniciar el dictado. Cierre otras apps que usen el micrófono e intente de nuevo.",
            "danger"
        );
        return false;
    }
}

async function onBrowserDictationButtonClick(ev) {
    if (ev) {
        ev.preventDefault();
        ev.stopPropagation();
    }

    if (_browserDictationClickBusy) {
        return;
    }

    if (_browserDictationActive) {
        stopBrowserDictation();
        return;
    }

    _browserDictationClickBusy = true;
    setBrowserDictationButtonBusy(
        true,
        '<span class="spinner-border spinner-border-sm me-1"></span> Preparando…'
    );
    setBrowserDictationStatusMessage("Preparando micrófono y dictado…", false);
    notifyBrowserDictation("Preparando dictado por voz…", "info");

    try {
        await startBrowserDictation();
    } catch (err) {
        console.error("onBrowserDictationButtonClick:", err);
        notifyBrowserDictation(`Error al dictar: ${err.message || err}`, "danger");
    } finally {
        if (!_browserDictationActive) {
            _browserDictationClickBusy = false;
            setBrowserDictationButtonBusy(false);
            updateBrowserDictationUi(false);
        }
    }
}

function toggleBrowserDictation() {
    onBrowserDictationButtonClick();
}

function speechMikeActionPrefersBrowserDictation(action) {
    if (!action || !isBrowserDictationSupported()) {
        return false;
    }
    if (typeof currentRadioStudy === "undefined" || !currentRadioStudy) {
        return false;
    }
    return ["toggle", "start", "stop", "pause"].includes(action);
}

function handleSpeechMikeBrowserDictationAction(action) {
    if (!speechMikeActionPrefersBrowserDictation(action)) {
        return false;
    }

    if (action === "stop" || (action === "pause" && _browserDictationActive)) {
        if (_browserDictationActive) {
            stopBrowserDictation();
        }
        return true;
    }

    if (action === "toggle" || action === "start" || action === "pause") {
        onBrowserDictationButtonClick();
        return true;
    }

    return false;
}

function setupBrowserDictationUi() {
    $(document)
        .off("click.risBrowserStt", "#btnBrowserDictation")
        .on("click.risBrowserStt", "#btnBrowserDictation", function (e) {
            e.preventDefault();
            e.stopPropagation();
            onBrowserDictationButtonClick();
        });

    const $lang = $("#selDictationLang");
    if ($lang.length) {
        $lang.val(getBrowserDictationLang());
        $lang.off("change.risBrowserStt").on("change.risBrowserStt", function () {
            setBrowserDictationLang($(this).val());
            if (_browserDictationActive) {
                stopBrowserDictation(true);
                notifyBrowserDictation("Idioma cambiado. Pulse Iniciar dictado de nuevo.", "info");
            }
        });
    }

    refreshBrowserDictationButtonState();
}

function refreshBrowserDictationButtonState() {
    const $btn = $("#btnBrowserDictation");
    if (!$btn.length) {
        return;
    }

    $btn.prop("disabled", false);
    setBrowserDictationButtonBusy(false);

    if (_browserDictationActive) {
        return;
    }

    const block = getBrowserDictationBlockReason();
    if (block) {
        setBrowserDictationStatusMessage(block, true);
        return;
    }

    const studyReady = typeof currentRadioStudy !== "undefined" && !!currentRadioStudy;
    if (!studyReady) {
        setBrowserDictationStatusMessage("Seleccione un examen en la bandeja izquierda", false);
        return;
    }

    setBrowserDictationStatusMessage("", false);
}

window.risStartBrowserDictationClick = onBrowserDictationButtonClick;
window.isBrowserDictationSupported = isBrowserDictationSupported;
window.isBrowserDictationActive = isBrowserDictationActive;
window.startBrowserDictation = startBrowserDictation;
window.stopBrowserDictation = stopBrowserDictation;
window.toggleBrowserDictation = toggleBrowserDictation;
window.setupBrowserDictationUi = setupBrowserDictationUi;
window.refreshBrowserDictationButtonState = refreshBrowserDictationButtonState;
window.handleSpeechMikeBrowserDictationAction = handleSpeechMikeBrowserDictationAction;
