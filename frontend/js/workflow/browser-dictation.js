/* =========================================
   Dictado por voz en el navegador (Web Speech API)
   Usa el micrófono por defecto de Windows (SpeechMike USB o integrado).
   ========================================= */

let _browserRecognition = null;
let _browserDictationActive = false;
let _browserDictationStopping = false;
let _browserDictationBaseText = "";
let _browserDictationSessionFinal = "";
let _browserDictationStream = null;

function getSpeechRecognitionConstructor() {
    return window.SpeechRecognition || window.webkitSpeechRecognition || null;
}

function isBrowserDictationSupported() {
    return !!getSpeechRecognitionConstructor() && !!navigator.mediaDevices?.getUserMedia;
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

function releaseBrowserDictationMic() {
    if (_browserDictationStream) {
        _browserDictationStream.getTracks().forEach((t) => t.stop());
        _browserDictationStream = null;
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
        $status
            .removeClass("text-muted text-danger")
            .addClass("text-danger fw-bold")
            .html(
                hint ||
                    '<i class="bi bi-record-circle me-1"></i> Escuchando… hable al micrófono (SpeechMike o PC)'
            );
    } else {
        $btn
            .removeClass("btn-primary")
            .addClass("btn-outline-primary")
            .html('<i class="bi bi-mic-fill me-1"></i> INICIAR DICTADO VOZ');
        $status
            .removeClass("text-danger fw-bold")
            .addClass("text-muted")
            .text(hint || "Listo — use el mismo micrófono que en Windows");
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

    if (typeof currentDictationMethod !== "undefined" && currentDictationMethod === "browser_stt") {
        currentDictationMethod = "teclado";
    }

    if (_browserRecognition) {
        try {
            _browserRecognition.onend = null;
            _browserRecognition.stop();
        } catch (e) {
            console.warn("stopBrowserDictation:", e);
        }
        _browserRecognition = null;
    }

    releaseBrowserDictationMic();
    updateBrowserDictationUi(false);

    const txt = $("#textoInforme");
    txt.removeClass("border-primary border-2 shadow");

    if (!silent && typeof showToast === "function") {
        showToast("Dictado por voz detenido.", "secondary");
    }

    _browserDictationStopping = false;
}

async function warmUpMicrophoneForDictation() {
    releaseBrowserDictationMic();
    const stream = await navigator.mediaDevices.getUserMedia({
        audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
        },
    });
    _browserDictationStream = stream;

    const devices = await navigator.mediaDevices.enumerateDevices();
    const inputs = devices.filter((d) => d.kind === "audioinput");
    const defaultInput = inputs.find((d) => d.deviceId === "default") || inputs[0];
    const label = defaultInput?.label || "micrófono del sistema";

    return { label, count: inputs.length };
}

async function startBrowserDictation() {
    if (typeof currentRadioStudy === "undefined" || !currentRadioStudy) {
        if (typeof showToast === "function") {
            showToast("Seleccione un examen en la bandeja izquierda antes de dictar.", "warning");
        }
        return false;
    }

    const Ctor = getSpeechRecognitionConstructor();
    if (!Ctor) {
        if (typeof showToast === "function") {
            showToast("Use Chrome o Edge en esta PC (dictado por voz no disponible).", "danger", 10000);
        }
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

    updateBrowserDictationUi(false, '<i class="bi bi-hourglass me-1"></i> Preparando micrófono…');

    let micInfo = { label: "micrófono", count: 0 };
    try {
        micInfo = await warmUpMicrophoneForDictation();
    } catch (err) {
        console.error("warmUpMicrophoneForDictation:", err);
        updateBrowserDictationUi(false);
        if (typeof showToast === "function") {
            showToast(
                "No se pudo usar el micrófono. En Chrome: icono candado → Micrófono → Permitir. Elija SpeechMike en Windows si aplica.",
                "danger",
                12000
            );
        }
        return false;
    }

    const recognition = new Ctor();
    recognition.lang = getBrowserDictationLang();
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.maxAlternatives = 1;

    recognition.onstart = () => {
        _browserDictationActive = true;
        _browserDictationStopping = false;
        if (typeof currentDictationMethod !== "undefined") {
            currentDictationMethod = "browser_stt";
        }
        updateBrowserDictationUi(
            true,
            `<i class="bi bi-record-circle me-1"></i> Escuchando (${micInfo.label})`
        );
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
            "not-allowed":
                "Micrófono bloqueado. Permita el acceso en Chrome (candado en la barra de direcciones).",
            "no-speech": "No se oyó voz. Acerque el SpeechMike o hable más fuerte.",
            network:
                "Dictado requiere Internet (el navegador envía audio al motor de voz). Compruebe la red.",
            aborted: "Dictado interrumpido.",
            "audio-capture": "No hay micrófono disponible. Conecte el SpeechMike o revise Windows → Sonido.",
            "service-not-allowed": "Dictado bloqueado por política del navegador o la organización.",
        };
        if (typeof showToast === "function") {
            showToast(messages[event.error] || `Error de dictado: ${event.error}`, "warning", 10000);
        }
        stopBrowserDictation(true);
    };

    recognition.onend = () => {
        if (_browserDictationActive && !_browserDictationStopping) {
            try {
                recognition.start();
            } catch (e) {
                console.warn("recognition restart:", e);
                stopBrowserDictation(true);
            }
        } else {
            updateBrowserDictationUi(false);
        }
    };

    try {
        recognition.start();
        _browserRecognition = recognition;
        if (typeof showToast === "function") {
            showToast(
                `Dictado activo (${micInfo.label}). Hable con claridad; el texto va al informe. Mismo botón del SpeechMike o F4 para detener.`,
                "success",
                10000
            );
        }
        return true;
    } catch (err) {
        console.error("startBrowserDictation:", err);
        releaseBrowserDictationMic();
        updateBrowserDictationUi(false);
        if (typeof showToast === "function") {
            showToast("No se pudo iniciar el dictado. Cierre otras apps que usen el micrófono e intente de nuevo.", "danger");
        }
        return false;
    }
}

function toggleBrowserDictation() {
    if (_browserDictationActive) {
        stopBrowserDictation();
        return false;
    }
    startBrowserDictation();
    return true;
}

/** SpeechMike / teclas: priorizar dictado al informe (no audio para secretaría). */
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
        toggleBrowserDictation();
        return true;
    }

    return false;
}

function setupBrowserDictationUi() {
    $("#btnBrowserDictation").off("click.risBrowserStt").on("click.risBrowserStt", function () {
        toggleBrowserDictation();
    });

    const $lang = $("#selDictationLang");
    if ($lang.length) {
        $lang.val(getBrowserDictationLang());
        $lang.off("change.risBrowserStt").on("change.risBrowserStt", function () {
            setBrowserDictationLang($(this).val());
            if (_browserDictationActive) {
                stopBrowserDictation(true);
                if (typeof showToast === "function") {
                    showToast("Idioma cambiado. Pulse Iniciar dictado de nuevo.", "info");
                }
            }
        });
    }

    if (!isBrowserDictationSupported()) {
        $("#btnBrowserDictation").prop("disabled", true);
        $("#browserDictationStatus").text("Use Chrome o Edge con HTTPS");
    } else {
        refreshBrowserDictationButtonState();
    }
}

function refreshBrowserDictationButtonState() {
    const $btn = $("#btnBrowserDictation");
    if (!$btn.length) {
        return;
    }
    const studyReady = typeof currentRadioStudy !== "undefined" && !!currentRadioStudy;
    $btn.prop("disabled", !studyReady || !isBrowserDictationSupported());
    if (studyReady && isBrowserDictationSupported() && !_browserDictationActive) {
        updateBrowserDictationUi(false);
    }
}

window.isBrowserDictationSupported = isBrowserDictationSupported;
window.isBrowserDictationActive = isBrowserDictationActive;
window.startBrowserDictation = startBrowserDictation;
window.stopBrowserDictation = stopBrowserDictation;
window.toggleBrowserDictation = toggleBrowserDictation;
window.setupBrowserDictationUi = setupBrowserDictationUi;
window.refreshBrowserDictationButtonState = refreshBrowserDictationButtonState;
window.handleSpeechMikeBrowserDictationAction = handleSpeechMikeBrowserDictationAction;
