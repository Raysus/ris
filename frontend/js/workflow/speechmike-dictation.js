/* =========================================
   SpeechMike / pedal Philips — WebHID (dictation_support)
   https://github.com/GoogleChromeLabs/dictation_support
   ========================================= */

const DICTATION_SUPPORT_CDN =
    "https://unpkg.com/dictation_support@1.0.5/dist/index.js";

let _dictationDeviceManager = null;
let _speechMikeLastButtonMask = 0;
let _speechMikeHidReady = false;
let _dictationSupportLoadPromise = null;

function loadDictationSupportSdk() {
    if (typeof DictationSupport !== "undefined") {
        return Promise.resolve();
    }
    if (_dictationSupportLoadPromise) {
        return _dictationSupportLoadPromise;
    }

    _dictationSupportLoadPromise = new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src = DICTATION_SUPPORT_CDN;
        script.async = true;
        script.onload = () => {
            if (typeof DictationSupport !== "undefined") {
                resolve();
            } else {
                reject(new Error("dictation_support no expuso DictationSupport"));
            }
        };
        script.onerror = () => reject(new Error("No se pudo cargar dictation_support"));
        document.head.appendChild(script);
    });

    return _dictationSupportLoadPromise;
}

function speechMikeDictationSupported() {
    return typeof navigator !== "undefined" && !!navigator.hid && typeof DictationSupport !== "undefined";
}

function updateSpeechMikeConnectUi() {
    const $status = $("#speechMikeHidStatus");
    const $btn = $("#btnConnectSpeechMike");
    if (!$status.length) {
        return;
    }

    if (!navigator.hid) {
        $status.text("Use Chrome o Edge en esta PC (WebHID no disponible).");
        $btn.prop("disabled", true);
        return;
    }

    if (typeof DictationSupport === "undefined") {
        $status.text("Cargando controlador del micrófono…");
        return;
    }

    const devices = _dictationDeviceManager?.getDevices?.() || [];
    if (devices.length > 0) {
        $status
            .removeClass("text-warning text-danger")
            .addClass("text-success")
            .text(`Conectado (${devices.length} dispositivo). Botones listos.`);
        $btn.text("Cambiar dispositivo");
    } else {
        $status
            .removeClass("text-success text-danger")
            .addClass("text-warning")
            .text("Pulse «Conectar SpeechMike» y elija el micrófono (modo navegador F3 recomendado).");
        $btn.text("Conectar SpeechMike");
    }
}

async function trySetSpeechMikeBrowserMode(device) {
    if (!device || typeof device.setEventMode !== "function") {
        return;
    }
    try {
        await device.setEventMode(DictationSupport.EventMode.BROWSER);
    } catch (err) {
        console.warn("SpeechMike setEventMode(BROWSER):", err);
    }
}

async function trySetSpeechMikeRecordingLed(active) {
    const devices = _dictationDeviceManager?.getDevices?.() || [];
    for (const device of devices) {
        if (typeof device.setSimpleLedState !== "function") {
            continue;
        }
        try {
            const state = active
                ? DictationSupport.SimpleLedState.RECORD_OVERWRITE
                : DictationSupport.SimpleLedState.OFF;
            await device.setSimpleLedState(state);
        } catch (err) {
            console.warn("SpeechMike LED:", err);
        }
    }
}

function executeSpeechMikeRecordingAction(action) {
    if (!action) {
        return;
    }

    if (!canHandleSpeechMikeHotkey() && !isRecordingSessionActive()) {
        if (typeof showToast === "function") {
            showToast("Seleccione un examen antes de grabar audio.", "warning");
        }
        return;
    }

    const refreshLed = () => trySetSpeechMikeRecordingLed(isRecordingSessionActive());

    switch (action) {
        case "stop":
            if (stopAudioRecording()) {
                refreshLed();
            }
            break;
        case "start":
            startAudioRecording().then(() => refreshLed());
            break;
        case "toggle":
            toggleAudioRecording();
            refreshLed();
            break;
        case "pause":
            toggleAudioRecordingPause();
            refreshLed();
            break;
        case "resume":
            if (resumeAudioRecording()) {
                refreshLed();
            }
            break;
        default:
            break;
    }
}

function handleSpeechMikeHidButton(device, bitMask) {
    const BE = DictationSupport.ButtonEvent;
    const newlyPressed = bitMask & ~_speechMikeLastButtonMask;
    _speechMikeLastButtonMask = bitMask;

    if (localStorage.getItem("ris_debug_speechmike") === "1") {
        const names = [];
        Object.keys(BE).forEach((k) => {
            const v = BE[k];
            if (typeof v === "number" && v > 0 && (newlyPressed & v)) {
                names.push(k);
            }
        });
        if (names.length && typeof showToast === "function") {
            showToast(`SpeechMike: ${names.join(", ")}`, "info");
        }
        console.info("[SpeechMike HID]", { bitMask, newlyPressed, names });
    }

    if (!newlyPressed) {
        return;
    }

    if (newlyPressed & BE.STOP) {
        executeSpeechMikeRecordingAction("stop");
        return;
    }

    if (newlyPressed & BE.RECORD) {
        executeSpeechMikeRecordingAction(isRecordingSessionActive() ? "stop" : "start");
        return;
    }

    if (newlyPressed & BE.PLAY) {
        executeSpeechMikeRecordingAction(
            isRecordingPaused() ? "resume" : isRecordingActive() ? "pause" : "start"
        );
        return;
    }

    if (newlyPressed & (BE.F1_A | BE.F2_B | BE.F3_C | BE.F4_D)) {
        executeSpeechMikeRecordingAction("toggle");
    }
}

function onSpeechMikeHidConnected() {
    updateSpeechMikeConnectUi();
    if (typeof showToast === "function") {
        showToast("SpeechMike listo. Use el botón de grabar del micrófono.", "success");
    }
}

function onSpeechMikeHidDisconnected() {
    _speechMikeLastButtonMask = 0;
    updateSpeechMikeConnectUi();
}

async function connectSpeechMikeDevice() {
    if (!_dictationDeviceManager) {
        if (typeof showToast === "function") {
            showToast("El controlador del micrófono aún no está listo. Espere un momento.", "warning");
        }
        return;
    }

    try {
        const devices = await _dictationDeviceManager.requestDevice();
        if (!devices.length) {
            if (typeof showToast === "function") {
                showToast("No se seleccionó ningún dispositivo.", "warning");
            }
            return;
        }

        for (const device of devices) {
            await trySetSpeechMikeBrowserMode(device);
        }

        localStorage.setItem("ris_speechmike_hid_connected", "1");
        updateSpeechMikeConnectUi();
        onSpeechMikeHidConnected();
    } catch (err) {
        console.error("connectSpeechMikeDevice:", err);
        if (typeof showToast === "function") {
            showToast(
                "No se pudo conectar. Use Chrome/Edge, modo navegador (F3) en el micrófono y permita el acceso HID.",
                "danger"
            );
        }
    }
}

function setupSpeechMikeUiBindings() {
    $("#btnConnectSpeechMike").off("click.risSpeechMike").on("click.risSpeechMike", function () {
        if (typeof connectSpeechMikeDevice === "function") {
            connectSpeechMikeDevice();
            return;
        }
        if (typeof showToast === "function") {
            showToast("Controlador SpeechMike no cargado. Recargue Radiólogo (Ctrl+F5).", "warning");
        }
    });
}

async function initSpeechMikeDictation() {
    setupSpeechMikeUiBindings();
    updateSpeechMikeConnectUi();

    if (!navigator.hid) {
        return;
    }

    try {
        await loadDictationSupportSdk();
    } catch (err) {
        console.error(err);
        $("#speechMikeHidStatus").text("No se pudo cargar el controlador del micrófono (red/CDN).");
        return;
    }

    if (_speechMikeHidReady) {
        updateSpeechMikeConnectUi();
        return;
    }

    try {
        _dictationDeviceManager = new DictationSupport.DictationDeviceManager();
        _dictationDeviceManager.addButtonEventListener(handleSpeechMikeHidButton);
        _dictationDeviceManager.addDeviceConnectedEventListener(async (device) => {
            await trySetSpeechMikeBrowserMode(device);
            onSpeechMikeHidConnected();
        });
        _dictationDeviceManager.addDeviceDisconnectedEventListener(onSpeechMikeHidDisconnected);

        await _dictationDeviceManager.init();
        _speechMikeHidReady = true;

        const existing = _dictationDeviceManager.getDevices();
        if (existing.length > 0) {
            for (const device of existing) {
                await trySetSpeechMikeBrowserMode(device);
            }
            updateSpeechMikeConnectUi();
        } else if (localStorage.getItem("ris_speechmike_hid_connected") === "1") {
            $("#speechMikeHidStatus").text(
                "Permiso guardado: pulse «Conectar SpeechMike» si el micrófono no responde."
            );
        }

        updateSpeechMikeConnectUi();
    } catch (err) {
        console.error("initSpeechMikeDictation:", err);
        if (typeof showToast === "function") {
            showToast("Error al iniciar WebHID para SpeechMike.", "danger");
        }
    }
}

window.connectSpeechMikeDevice = connectSpeechMikeDevice;
window.initSpeechMikeDictation = initSpeechMikeDictation;
