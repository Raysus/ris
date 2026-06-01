/* =========================================
   Dictado por voz en el navegador (Web Speech API)
   Estándar W3C; motor depende del navegador (Chromium: gratuito).
   Alternativa open source sin instalar Dragon.
   ========================================= */

let _browserRecognition = null;
let _browserDictationActive = false;
let _browserDictationBaseText = "";
let _browserDictationFinalBuffer = "";

function getSpeechRecognitionConstructor() {
    return window.SpeechRecognition || window.webkitSpeechRecognition || null;
}

function isBrowserDictationSupported() {
    return !!getSpeechRecognitionConstructor();
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

function updateBrowserDictationUi(active) {
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
            .html('<i class="bi bi-record-circle me-1"></i> Escuchando… hable al micrófono');
    } else {
        $btn
            .removeClass("btn-primary")
            .addClass("btn-outline-primary")
            .html('<i class="bi bi-mic-fill me-1"></i> INICIAR DICTADO VOZ');
        $status
            .removeClass("text-danger fw-bold")
            .addClass("text-muted")
            .text("Listo (Chrome/Edge + micrófono)");
    }
}

function syncBrowserDictationToStudy() {
    const txt = $("#textoInforme");
    if (currentRadioStudy && txt.length) {
        currentRadioStudy.reportText = txt.val();
    }
}

function applyBrowserDictationTextToTextarea(interimPiece) {
    const txt = document.getElementById("textoInforme");
    if (!txt) {
        return;
    }

    const combined = _browserDictationBaseText + _browserDictationFinalBuffer + interimPiece;
    const len = combined.length;
    txt.value = combined;
    txt.selectionStart = len;
    txt.selectionEnd = len;
    syncBrowserDictationToStudy();
}

function stopBrowserDictation(silent) {
    if (_browserRecognition) {
        try {
            _browserRecognition.stop();
        } catch (e) {
            console.warn("stopBrowserDictation:", e);
        }
        _browserRecognition = null;
    }

    _browserDictationActive = false;
    updateBrowserDictationUi(false);

    const txt = $("#textoInforme");
    txt.removeClass("border-primary border-2 shadow");

    if (!silent && typeof showToast === "function") {
        showToast("Dictado por voz detenido.", "secondary");
    }
}

function startBrowserDictation() {
    if (!currentRadioStudy) {
        if (typeof showToast === "function") {
            showToast("Seleccione un examen antes de dictar.", "warning");
        }
        return false;
    }

    const Ctor = getSpeechRecognitionConstructor();
    if (!Ctor) {
        if (typeof showToast === "function") {
            showToast(
                "Dictado por voz del navegador no disponible. Use Chrome o Edge (no Firefox).",
                "danger",
                10000
            );
        }
        return false;
    }

    if (typeof stopAudioRecording === "function" && isRecordingSessionActive()) {
        stopAudioRecording();
    }
    if (typeof dragonSyncInterval !== "undefined" && dragonSyncInterval) {
        clearInterval(dragonSyncInterval);
    }

    stopBrowserDictation(true);

    const txt = $("#textoInforme");
    txt.prop("disabled", false).focus();
    txt.addClass("border border-primary border-2 shadow").removeClass("border-0");

    _browserDictationBaseText = txt.val();
    if (_browserDictationBaseText.length && !_browserDictationBaseText.endsWith(" ")) {
        _browserDictationBaseText += " ";
    }
    _browserDictationFinalBuffer = "";

    const recognition = new Ctor();
    recognition.lang = getBrowserDictationLang();
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.maxAlternatives = 1;

    recognition.onstart = () => {
        _browserDictationActive = true;
        if (typeof currentDictationMethod !== "undefined") {
            currentDictationMethod = "browser_stt";
        }
        updateBrowserDictationUi(true);
    };

    recognition.onresult = (event) => {
        let interim = "";
        for (let i = event.resultIndex; i < event.results.length; i++) {
            const piece = event.results[i][0].transcript;
            if (event.results[i].isFinal) {
                _browserDictationFinalBuffer += piece;
            } else {
                interim += piece;
            }
        }
        applyBrowserDictationTextToTextarea(interim);
    };

    recognition.onerror = (event) => {
        console.warn("browser dictation error:", event.error);
        const messages = {
            "not-allowed": "Permiso de micrófono denegado. Permita el micrófono en Chrome.",
            "no-speech": "No se detectó voz. Intente de nuevo.",
            "network": "Error de red del motor de voz. Reintente.",
            "aborted": "Dictado interrumpido.",
        };
        if (typeof showToast === "function") {
            showToast(messages[event.error] || `Error de dictado: ${event.error}`, "warning", 8000);
        }
        stopBrowserDictation(true);
    };

    recognition.onend = () => {
        if (_browserDictationActive) {
            try {
                recognition.start();
            } catch (e) {
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
                "Dictado por voz activo. Hable en español; el texto se escribe en el informe. Pulse de nuevo para detener.",
                "success",
                9000
            );
        }
        return true;
    } catch (err) {
        console.error("startBrowserDictation:", err);
        if (typeof showToast === "function") {
            showToast("No se pudo iniciar el dictado. Compruebe permiso del micrófono.", "danger");
        }
        return false;
    }
}

function toggleBrowserDictation() {
    if (_browserDictationActive) {
        stopBrowserDictation();
        return false;
    }
    return startBrowserDictation();
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
                    showToast("Idioma actualizado. Pulse Iniciar dictado de nuevo.", "info");
                }
            }
        });
    }

    if (!isBrowserDictationSupported()) {
        $("#btnBrowserDictation").prop("disabled", true);
        $("#browserDictationStatus").text("No disponible (use Chrome o Edge)");
    } else {
        $("#btnBrowserDictation").prop("disabled", false);
        updateBrowserDictationUi(false);
    }
}

window.isBrowserDictationSupported = isBrowserDictationSupported;
window.isBrowserDictationActive = isBrowserDictationActive;
window.startBrowserDictation = startBrowserDictation;
window.stopBrowserDictation = stopBrowserDictation;
window.toggleBrowserDictation = toggleBrowserDictation;
window.setupBrowserDictationUi = setupBrowserDictationUi;
