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

    const localSrc = "js/lib/dictation_support.js";
    const tryLocal = () =>
        new Promise((resolve, reject) => {
            const script = document.createElement("script");
            script.src = typeof risAssetUrl === "function" ? risAssetUrl(localSrc) : localSrc;
            script.async = true;
            script.onload = () => {
                if (typeof DictationSupport !== "undefined") {
                    resolve();
                } else {
                    reject(new Error("dictation_support local no expuso DictationSupport"));
                }
            };
            script.onerror = () => reject(new Error("No se pudo cargar dictation_support local"));
            document.head.appendChild(script);
        });

    const tryCdn = () =>
        new Promise((resolve, reject) => {
            const script = document.createElement("script");
            script.src = DICTATION_SUPPORT_CDN;
            script.async = true;
            script.onload = () => {
                if (typeof DictationSupport !== "undefined") {
                    resolve();
                } else {
                    reject(new Error("dictation_support CDN no expuso DictationSupport"));
                }
            };
            script.onerror = () => reject(new Error("No se pudo cargar dictation_support (CDN)"));
            document.head.appendChild(script);
        });

    _dictationSupportLoadPromise = tryLocal().catch(() => tryCdn());
    return _dictationSupportLoadPromise;
}

function speechMikeDictationSupported() {
    return typeof navigator !== "undefined" && !!navigator.hid && typeof DictationSupport !== "undefined";
}

function speechMikeEventModeLabel(mode) {
    const EM = DictationSupport?.EventMode;
    if (!EM) {
        return String(mode);
    }
    const map = {
        [EM.HID]: "HID (evento)",
        [EM.KEYBOARD]: "TECLADO",
        [EM.BROWSER]: "NAVEGADOR",
        [EM.WINDOWS_SR]: "Windows SR",
        [EM.DRAGON_FOR_MAC]: "Dragon Mac",
        [EM.DRAGON_FOR_WINDOWS]: "Dragon Windows",
    };
    return map[mode] || `modo ${mode}`;
}

/** LFH3200/3300 (SpeechMike III): sin modo navegador F3; usan HID/evento vía WebHID. */
function speechMikeSupportsBrowserMode(device) {
    if (!device || typeof device.getDeviceType !== "function") {
        return false;
    }
    const dt = device.getDeviceType();
    const DT = DictationSupport.DeviceType;
    const withBrowser = [
        DT.SPEECHMIKE_LFH_3500,
        DT.SPEECHMIKE_LFH_3510,
        DT.SPEECHMIKE_LFH_3520,
        DT.SPEECHMIKE_LFH_3600,
        DT.SPEECHMIKE_LFH_3610,
        DT.SPEECHMIKE_SMP_3700,
        DT.SPEECHMIKE_SMP_3710,
        DT.SPEECHMIKE_SMP_3720,
        DT.SPEECHMIKE_SMP_3800,
        DT.SPEECHMIKE_SMP_3810,
        DT.SPEECHMIKE_SMP_4000,
        DT.SPEECHMIKE_SMP_4010,
    ];
    return withBrowser.includes(dt);
}

function speechMikeDeviceLabel(device) {
    if (!device || typeof device.getDeviceType !== "function") {
        return "SpeechMike";
    }
    const dt = device.getDeviceType();
    const DT = DictationSupport.DeviceType;
    const labels = {
        [DT.SPEECHMIKE_LFH_3200]: "SpeechMike III Pro (LFH3200)",
        [DT.SPEECHMIKE_LFH_3210]: "SpeechMike III (LFH3210)",
        [DT.SPEECHMIKE_LFH_3220]: "SpeechMike III (LFH3220)",
        [DT.SPEECHMIKE_LFH_3300]: "SpeechMike III (LFH3300)",
        [DT.SPEECHMIKE_LFH_3310]: "SpeechMike III (LFH3310)",
        [DT.SPEECHMIKE_LFH_3500]: "SpeechMike Premium (LFH3500)",
        [DT.SPEECHMIKE_LFH_3600]: "SpeechMike Premium (LFH3600)",
    };
    return labels[dt] || `SpeechMike (tipo ${dt})`;
}

function updateSpeechMikeConnectUi(extraStatus) {
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
        const first = devices[0];
        const name = speechMikeDeviceLabel(first);
        const modeHint = speechMikeSupportsBrowserMode(first)
            ? "Modo navegador (F3) opcional."
            : "LFH3200: botones g/e vía HID; si no responden, use «Probar teclas».";
        const suffix = extraStatus ? ` ${extraStatus}` : "";
        $status
            .removeClass("text-warning text-danger")
            .addClass("text-success")
            .text(`Conectado: ${name}. ${modeHint}${suffix}`);
        $btn.text("Cambiar dispositivo");
    } else {
        $status
            .removeClass("text-success text-danger")
            .addClass("text-warning")
            .text(
                extraStatus ||
                    "Pulse «Conectar SpeechMike». Si no aparece en la lista, use SpeechControl en modo teclado con Num+ / F4."
            );
        $btn.text("Conectar SpeechMike");
    }
}

async function configureSpeechMikeForWeb(device) {
    if (!device || typeof device.setEventMode !== "function") {
        return { mode: "hid", eventMode: null };
    }

    try {
        if (speechMikeSupportsBrowserMode(device)) {
            await device.setEventMode(DictationSupport.EventMode.BROWSER);
            return { mode: "browser", eventMode: DictationSupport.EventMode.BROWSER };
        }

        let current =
            typeof device.getEventMode === "function"
                ? await device.getEventMode()
                : DictationSupport.EventMode.HID;

        if (current === DictationSupport.EventMode.KEYBOARD) {
            await device.setEventMode(DictationSupport.EventMode.HID);
            current = DictationSupport.EventMode.HID;
        } else if (current !== DictationSupport.EventMode.HID) {
            await device.setEventMode(DictationSupport.EventMode.HID);
            current = DictationSupport.EventMode.HID;
        }

        return { mode: "hid", eventMode: current };
    } catch (err) {
        console.warn("SpeechMike configureSpeechMikeForWeb:", err);
        return { mode: "hid", eventMode: null };
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

function speechMikeMaskToNames(bitMask) {
    const BE = DictationSupport.ButtonEvent;
    const names = [];
    Object.keys(BE).forEach((k) => {
        const v = BE[k];
        if (typeof v === "number" && v > 0 && (bitMask & v)) {
            names.push(k);
        }
    });
    return names;
}

function handleSpeechMikeHidButton(device, bitMask) {
    const BE = DictationSupport.ButtonEvent;
    const newlyPressed = bitMask & ~_speechMikeLastButtonMask;
    _speechMikeLastButtonMask = bitMask;

    if (localStorage.getItem("ris_debug_speechmike") === "1") {
        const names = speechMikeMaskToNames(newlyPressed || bitMask);
        if (names.length && typeof showToast === "function") {
            showToast(`SpeechMike HID: ${names.join(", ")}`, "info");
        }
        console.info("[SpeechMike HID]", {
            bitMask,
            newlyPressed,
            names: speechMikeMaskToNames(bitMask),
        });
    }

    if (!newlyPressed) {
        return;
    }

    const stopBits =
        BE.STOP | BE.SCAN_END | BE.SCAN_SUCCESS | BE.EOL_PRIO | BE.COMMAND;
    const recordBits = BE.RECORD | BE.INSTR | BE.INS_OVR | BE.F4_D;
    const playBits = BE.PLAY | BE.FORWARD | BE.REWIND;

    if (newlyPressed & stopBits) {
        executeSpeechMikeRecordingAction("stop");
        return;
    }

    if (newlyPressed & recordBits) {
        executeSpeechMikeRecordingAction(isRecordingSessionActive() ? "stop" : "start");
        return;
    }

    if (newlyPressed & playBits) {
        executeSpeechMikeRecordingAction(
            isRecordingPaused() ? "resume" : isRecordingActive() ? "pause" : "start"
        );
        return;
    }

    if (newlyPressed & (BE.F1_A | BE.F2_B | BE.F3_C)) {
        executeSpeechMikeRecordingAction("toggle");
    }
}

function onSpeechMikeHidConnected() {
    updateSpeechMikeConnectUi();
    if (typeof showToast === "function") {
        showToast("SpeechMike listo. Pulse «Probar teclas» si los botones no responden.", "success");
    }
}

function onSpeechMikeHidDisconnected() {
    _speechMikeLastButtonMask = 0;
    updateSpeechMikeConnectUi();
}

async function listPhilipsHidDevices() {
    if (!navigator.hid?.getDevices) {
        return [];
    }
    const all = await navigator.hid.getDevices();
    return all.filter((d) => d.vendorId === 0x0911 || d.vendorId === 0x0554);
}

async function diagnoseSpeechMike() {
    const lines = [];
    if (!navigator.hid) {
        lines.push("WebHID no disponible (use Chrome o Edge).");
    } else {
        const permitted = await listPhilipsHidDevices();
        lines.push(`HID permitidos (Philips/Nuance): ${permitted.length}`);
        permitted.forEach((d, i) => {
            const cols =
                d.collections?.map((c) => `page ${c.usagePage} usage ${c.usage}`).join("; ") ||
                "sin colecciones";
            lines.push(
                `  ${i + 1}. ${d.productName || "dispositivo"} pid=${d.productId} opened=${d.opened} (${cols})`
            );
        });
    }

    const sdkDevices = _dictationDeviceManager?.getDevices?.() || [];
    lines.push(`SDK dictation_support: ${sdkDevices.length} dispositivo(s)`);
    for (const device of sdkDevices) {
        const label = speechMikeDeviceLabel(device);
        let modeText = "?";
        try {
            if (typeof device.getEventMode === "function") {
                const m = await device.getEventMode();
                modeText = speechMikeEventModeLabel(m);
                if (m === DictationSupport.EventMode.KEYBOARD) {
                    lines.push(
                        "  AVISO: modo TECLADO — el navegador no recibe botones HID. En SpeechControl asigne Num+ y F4 a Chrome."
                    );
                }
            }
        } catch (e) {
            modeText = "error";
        }
        lines.push(`  · ${label} — ${modeText}`);
    }

    const msg = lines.join("\n");
    console.info("[SpeechMike diagnóstico]\n" + msg);
    if (typeof showToast === "function") {
        showToast(lines.slice(0, 3).join(" · "), sdkDevices.length ? "info" : "warning", 9000);
    }
    return msg;
}

async function connectSpeechMikeDevice() {
    if (!_dictationDeviceManager) {
        if (typeof showToast === "function") {
            showToast("El controlador del micrófono aún no está listo. Espere un momento.", "warning");
        }
        return;
    }

    try {
        let devices = await _dictationDeviceManager.requestDevice();
        if (!devices.length) {
            const permitted = await listPhilipsHidDevices();
            if (permitted.length) {
                devices = _dictationDeviceManager.getDevices();
            }
        }

        if (!devices.length) {
            await diagnoseSpeechMike();
            if (typeof showToast === "function") {
                showToast(
                    "No hay SpeechMike HID activo. En SpeechControl: perfil para Chrome con Num+ (grabar) y F4 (detener), o modo evento + Conectar.",
                    "warning",
                    12000
                );
            }
            return;
        }

        const hints = [];
        for (const device of devices) {
            const { mode, eventMode } = await configureSpeechMikeForWeb(device);
            const label = speechMikeDeviceLabel(device);
            const modeLabel =
                eventMode != null ? speechMikeEventModeLabel(eventMode) : mode;
            hints.push(`${label} (${modeLabel})`);

            if (eventMode === DictationSupport.EventMode.KEYBOARD) {
                if (typeof showToast === "function") {
                    showToast(
                        "Micrófono en modo TECLADO: asigne en SpeechControl Num+ para grabar y F4 para detener en esta ventana de Chrome.",
                        "warning",
                        12000
                    );
                }
            }
        }

        localStorage.setItem("ris_speechmike_hid_connected", "1");
        updateSpeechMikeConnectUi();
        onSpeechMikeHidConnected();
        if (typeof showToast === "function" && hints.length) {
            showToast(hints.join(" · "), "success", 8000);
        }
        await diagnoseSpeechMike();
    } catch (err) {
        console.error("connectSpeechMikeDevice:", err);
        await diagnoseSpeechMike();
        if (typeof showToast === "function") {
            showToast(
                "No se pudo conectar. Chrome/Edge, cable USB directo (no RDP), y en el selector elija «SpeechMike» con página de uso 65440.",
                "danger",
                10000
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

    $("#btnSpeechMikeDebug").off("dblclick.risSpeechMike").on("dblclick.risSpeechMike", function () {
        if (typeof diagnoseSpeechMike === "function") {
            diagnoseSpeechMike();
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
        $("#speechMikeHidStatus").text(
            "No se pudo cargar el controlador del micrófono. Revise que exista js/lib/dictation_support.js."
        );
        return;
    }

    if (_speechMikeHidReady) {
        updateSpeechMikeConnectUi();
        const existing = _dictationDeviceManager?.getDevices?.() || [];
        if (existing.length) {
            for (const device of existing) {
                await configureSpeechMikeForWeb(device);
            }
        }
        return;
    }

    try {
        _dictationDeviceManager = new DictationSupport.DictationDeviceManager();
        _dictationDeviceManager.addButtonEventListener(handleSpeechMikeHidButton);
        _dictationDeviceManager.addDeviceConnectedEventListener(async (device) => {
            await configureSpeechMikeForWeb(device);
            onSpeechMikeHidConnected();
        });
        _dictationDeviceManager.addDeviceDisconnectedEventListener(onSpeechMikeHidDisconnected);

        await _dictationDeviceManager.init();
        _speechMikeHidReady = true;

        const existing = _dictationDeviceManager.getDevices();
        if (existing.length > 0) {
            for (const device of existing) {
                await configureSpeechMikeForWeb(device);
            }
            updateSpeechMikeConnectUi();
        } else if (localStorage.getItem("ris_speechmike_hid_connected") === "1") {
            $("#speechMikeHidStatus").text(
                "Permiso guardado: pulse «Conectar SpeechMike». Doble clic en «Probar teclas» = diagnóstico."
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
window.diagnoseSpeechMike = diagnoseSpeechMike;
