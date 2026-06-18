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

/** Parpadeo al conectar: si no enciende LED, SpeechControl u otra app tiene el HID. */
async function pulseSpeechMikeTestLed(device) {
    if (!device || typeof device.setSimpleLedState !== "function") {
        return false;
    }
    const SL = DictationSupport.SimpleLedState;
    try {
        await device.setSimpleLedState(SL.RECORD_STANDBY_OVERWRITE);
        await new Promise((r) => setTimeout(r, 700));
        await device.setSimpleLedState(SL.RECORD_OVERWRITE);
        await new Promise((r) => setTimeout(r, 700));
        await device.setSimpleLedState(SL.OFF);
        return true;
    } catch (err) {
        console.warn("pulseSpeechMikeTestLed:", err);
        return false;
    }
}

async function testSpeechMikeLed() {
    const devices = _dictationDeviceManager?.getDevices?.() || [];
    if (!devices.length) {
        if (typeof showToast === "function") {
            showToast(
                "Primero «Conectar SpeechMike». Cierre SpeechControl en la bandeja de Windows e intente de nuevo.",
                "warning",
                10000
            );
        }
        return false;
    }

    let anyOk = false;
    for (const device of devices) {
        if (await pulseSpeechMikeTestLed(device)) {
            anyOk = true;
        }
    }

    if (typeof showToast === "function") {
        showToast(
            anyOk
                ? "¿Vio parpadear el LED del micrófono? Si no, cierre SpeechControl y reconecte (modo evento/HID)."
                : "No se pudo controlar el LED. Cierre SpeechControl y en el selector elija SpeechMike (página 65440).",
            anyOk ? "success" : "danger",
            10000
        );
    }
    return anyOk;
}

function isSpeechMikeIiiLegacy(device) {
    if (!device || typeof device.getDeviceType !== "function") {
        return false;
    }
    const dt = device.getDeviceType();
    const DT = DictationSupport.DeviceType;
    return [
        DT.SPEECHMIKE_LFH_3200,
        DT.SPEECHMIKE_LFH_3210,
        DT.SPEECHMIKE_LFH_3220,
        DT.SPEECHMIKE_LFH_3300,
        DT.SPEECHMIKE_LFH_3310,
    ].includes(dt);
}

function speechMikePhilipsConflictHint() {
    return (
        "Cierre SpeechControl y la extensión Philips no sustituye «Conectar» en el RIS. " +
        "Si otra app Philips usa el micrófono, las luces y los botones no responderán en Chrome."
    );
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

function dispatchSpeechMikeAudioAction(action) {
    if (typeof executeSpeechMikeAudioAction !== "function") {
        return false;
    }
    return executeSpeechMikeAudioAction(action);
}

function resolveSpeechMikeHidAction(newlyPressed, session, preview) {
    const BE = DictationSupport.ButtonEvent;
    const fastForward = BE.FAST_FORWARD || 0;
    const scanBegin = BE.SCAN_BEGIN || 0;

    if (newlyPressed & BE.REWIND) {
        return preview && !session ? { type: "audio", action: "seek_back" } : null;
    }

    if (newlyPressed & (BE.FORWARD | fastForward)) {
        return preview && !session ? { type: "audio", action: "seek_forward" } : null;
    }

    if (newlyPressed & BE.PLAY) {
        if (preview && !session) {
            return { type: "audio", action: "toggle_play" };
        }
        if (session) {
            return { type: "rec", action: isRecordingPaused() ? "resume" : "pause" };
        }
        return null;
    }

    const stopBits =
        BE.STOP | BE.SCAN_END | BE.SCAN_SUCCESS | BE.EOL_PRIO | BE.COMMAND;
    if (newlyPressed & stopBits) {
        if (session) {
            return { type: "rec", action: "stop" };
        }
        if (preview) {
            return { type: "audio", action: "pause" };
        }
        if (newlyPressed & (BE.EOL_PRIO | BE.COMMAND | BE.SCAN_END | BE.SCAN_SUCCESS)) {
            return { type: "sign", action: "firmar" };
        }
        return null;
    }

    const recordBits = BE.RECORD | BE.INSTR | BE.INS_OVR | BE.F4_D;
    if (newlyPressed & recordBits) {
        if (preview && !session) {
            dispatchSpeechMikeAudioAction("pause");
        }
        return { type: "rec", action: session ? "stop" : "start" };
    }

    if (newlyPressed & BE.F1_A) {
        if (preview && !session) {
            return { type: "audio", action: "seek_back_long" };
        }
        return { type: "rec", action: "toggle" };
    }

    if (newlyPressed & BE.F2_B) {
        if (preview && !session) {
            return { type: "audio", action: "toggle_play" };
        }
        return { type: "rec", action: "toggle" };
    }

    if (newlyPressed & BE.F3_C) {
        if (preview && !session) {
            return { type: "audio", action: "seek_forward_long" };
        }
        return { type: "rec", action: "toggle" };
    }

    if (scanBegin && (newlyPressed & scanBegin)) {
        if (preview && !session) {
            return { type: "audio", action: "seek_back" };
        }
    }

    return null;
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

    const session = typeof isRecordingSessionActive === "function" && isRecordingSessionActive();
    const preview =
        typeof isAudioPreviewListeningMode === "function" && isAudioPreviewListeningMode();
    const resolved = resolveSpeechMikeHidAction(newlyPressed, session, preview);

    if (!resolved) {
        if (localStorage.getItem("ris_debug_speechmike") === "1") {
            console.info("[SpeechMike HID] botón sin acción asignada", speechMikeMaskToNames(newlyPressed));
        }
        return;
    }

    if (resolved.type === "audio") {
        dispatchSpeechMikeAudioAction(resolved.action);
        return;
    }

    if (resolved.type === "sign") {
        if (typeof window.firmarDirecto === "function") {
            window.firmarDirecto();
        }
        return;
    }

    executeSpeechMikeRecordingAction(resolved.action);
}

function onSpeechMikeHidConnected() {
    updateSpeechMikeConnectUi();
    if (typeof showToast === "function") {
        showToast("SpeechMike listo. En reproducción: ◀◀/▶▶ adelantan/retroceden; ▶ pausa. «Probar teclas» = diagnóstico.", "success");
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

const PHILIPS_HID_FILTERS = Object.freeze([
    { vendorId: 0x0911 },
    { vendorId: 0x0554 },
]);

function speechMikeHidPrerequisites() {
    if (!navigator.hid?.requestDevice) {
        return {
            ok: false,
            message: "Use Chrome o Microsoft Edge en esta PC (WebHID no disponible).",
        };
    }
    if (!window.isSecureContext) {
        return {
            ok: false,
            message:
                "WebHID requiere HTTPS. Abra el RIS con https:// (no http ni ventana embebida sin permisos).",
        };
    }
    return { ok: true };
}

/**
 * Debe llamarse en el mismo turno del clic (sin await previo) para que Chrome muestre el selector.
 */
function openPhilipsHidChooserFromClick() {
    return navigator.hid.requestDevice({ filters: [...PHILIPS_HID_FILTERS] });
}

function handleSpeechMikePickerError(err) {
    console.warn("SpeechMike picker:", err);
    if (err?.name === "NotFoundError") {
        if (typeof showToast === "function") {
            showToast(
                "No hay dispositivo Philips en la lista o canceló. Conecte USB, cierre SpeechControl y reintente. Si nunca aparece ventana: en Chrome abra chrome://settings/content/hid y borre permisos de este sitio.",
                "warning",
                14000
            );
        }
        return;
    }
    if (typeof showToast === "function") {
        showToast(
            `No se abrió el selector USB: ${err?.message || err}`,
            "danger",
            10000
        );
    }
}

async function ensureSpeechMikeManagerReady() {
    const pre = speechMikeHidPrerequisites();
    if (!pre.ok) {
        return false;
    }

    try {
        await loadDictationSupportSdk();
    } catch (err) {
        console.error(err);
        return false;
    }

    if (_speechMikeHidReady && _dictationDeviceManager) {
        return true;
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
        return true;
    } catch (err) {
        console.error("ensureSpeechMikeManagerReady:", err);
        return false;
    }
}

function describeHidCollection(d) {
    return (
        d.collections
            ?.map((c) => `${c.usagePage || "?"}/${c.usage || "?"}`)
            .join(", ") || "sin colecciones"
    );
}

function hasDictationHidCollection(hidDevice) {
    const USAGE_PAGE_DICTATION = 65440;
    return (
        hidDevice.collections?.some((c) => c.usagePage === USAGE_PAGE_DICTATION && c.usage === 1) ||
        false
    );
}

async function diagnoseSpeechMike() {
    const lines = [];
    let permitted = [];
    if (!navigator.hid) {
        lines.push("WebHID no disponible (use Chrome o Edge).");
    } else {
        permitted = await listPhilipsHidDevices();
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
    if (permitted.length > 0 && sdkDevices.length === 0) {
        lines.push(
            "  AVISO: hay permiso USB pero el SDK no abrió el HID de dictado. En el selector elija SpeechMike con página 65440, no «teclado»."
        );
    }
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
        if (isSpeechMikeIiiLegacy(device)) {
            lines.push(
                "  LFH3200/3300: soporte web limitado; si falla HID use SpeechControl (Num+/F4) con esta ventana enfocada."
            );
        }
    }

    lines.push(speechMikePhilipsConflictHint());

    const msg = lines.join("\n");
    console.info("[SpeechMike diagnóstico]\n" + msg);
    if (typeof showToast === "function") {
        showToast(lines.slice(0, 3).join(" · "), sdkDevices.length ? "info" : "warning", 9000);
    }
    return msg;
}

/**
 * Clic en «Conectar»: abre el selector de Chrome en el mismo turno (sin await previo).
 */
function speechMikeFlashStatus(message, isError) {
    const $status = $("#speechMikeHidStatus");
    if ($status.length) {
        $status
            .removeClass("text-success text-warning text-danger")
            .addClass(isError ? "text-danger" : "text-primary")
            .text(message);
    }
}

function risNotifySpeechMike(message, tipo = "info", durationMs = 6000) {
    speechMikeFlashStatus(message, tipo === "danger");
    if (typeof showToast === "function") {
        showToast(message, tipo, durationMs);
    } else {
        console.info("[SpeechMike]", message);
    }
}

function connectSpeechMikeDeviceFromUserClick() {
    console.info("[RIS] Conectar SpeechMike — clic recibido");

    const pre = speechMikeHidPrerequisites();
    if (!pre.ok) {
        risNotifySpeechMike(pre.message, "danger", 12000);
        return;
    }

    const $btn = $("#btnConnectSpeechMike");
    $btn.prop("disabled", true);

    risNotifySpeechMike(
        "Abriendo selector USB de Chrome… Elija «SpeechMike» / Philips (marque todas las líneas). Si no aparece ventana, revise chrome://settings/content/hid",
        "info",
        8000
    );

    openPhilipsHidChooserFromClick()
        .then((picked) => finishSpeechMikeConnectAfterPicker(picked))
        .catch((err) => handleSpeechMikePickerError(err))
        .finally(() => $btn.prop("disabled", false));
}

async function finishSpeechMikeConnectAfterPicker(picked) {
    if (!picked?.length) {
        if (typeof showToast === "function") {
            showToast(
                "No seleccionó ningún dispositivo en la lista. Marque todas las entradas «SpeechMike» / Philips que aparezcan.",
                "warning",
                12000
            );
        }
        return;
    }

    if (typeof showToast === "function") {
        showToast(
            `USB: ${picked.length} interfaz(es) autorizada(s). Si no vio ventana, Chrome ya tenía permiso (borre en chrome://settings/content/hid si falla).`,
            "info",
            7000
        );
    }

    console.info(
        "[SpeechMike] Seleccionado en Chrome:",
        picked.map((d) => ({
            name: d.productName,
            pid: d.productId,
            dictationHid: hasDictationHidCollection(d),
            cols: describeHidCollection(d),
        }))
    );

    const dictationPicked = picked.filter(hasDictationHidCollection);
    if (!dictationPicked.length && typeof showToast === "function") {
        showToast(
            "Solo se autorizó la interfaz «teclado». Vuelva a Conectar y marque también la entrada SpeechMike con página 65440 (FF00).",
            "warning",
            14000
        );
    }

    const ready = await ensureSpeechMikeManagerReady();
    if (!ready) {
        if (typeof showToast === "function") {
            showToast("No se pudo cargar el controlador del micrófono. Recargue con Ctrl+F5.", "danger");
        }
        return;
    }

    for (const hid of picked) {
        try {
            if (!hid.opened) {
                await hid.open();
            }
        } catch (openErr) {
            console.warn("SpeechMike hid.open:", openErr);
        }
    }

    await new Promise((r) => setTimeout(r, 800));

    let devices = _dictationDeviceManager.getDevices();
    if (!devices.length) {
        devices = _dictationDeviceManager.getDevices();
    }

    if (!devices.length) {
        await diagnoseSpeechMike();
        if (typeof showToast === "function") {
            showToast(
                "Permiso USB OK pero el RIS no controla ese interfaz. Elija la otra línea «SpeechMike» en la lista, o use SpeechControl (Num+/F4).",
                "warning",
                14000
            );
        }
        return;
    }

    const hints = [];
    let ledOk = false;
    for (const device of devices) {
        const { eventMode } = await configureSpeechMikeForWeb(device);
        const label = speechMikeDeviceLabel(device);
        const modeLabel =
            eventMode != null ? speechMikeEventModeLabel(eventMode) : "HID";
        hints.push(`${label} (${modeLabel})`);

        if (eventMode === DictationSupport.EventMode.KEYBOARD) {
            if (typeof showToast === "function") {
                showToast(
                    "Modo TECLADO: use SpeechControl con Num+ y F4 en Chrome.",
                    "warning",
                    12000
                );
            }
        } else if (await pulseSpeechMikeTestLed(device)) {
            ledOk = true;
        }

        if (isSpeechMikeIiiLegacy(device) && typeof showToast === "function") {
            showToast(
                "LFH3200 detectado. Si el LED no parpadeó, use SpeechControl (Num+/F4).",
                "info",
                8000
            );
        }
    }

    localStorage.setItem("ris_speechmike_hid_connected", "1");
    updateSpeechMikeConnectUi();
    onSpeechMikeHidConnected();
    if (typeof showToast === "function" && hints.length) {
        const ledMsg = ledOk
            ? " LED de prueba enviado."
            : " Sin LED — cierre SpeechControl.";
        showToast(hints.join(" · ") + ledMsg, ledOk ? "success" : "warning", 10000);
    }
    await diagnoseSpeechMike();
}

/** Compatibilidad: misma ruta que el clic (abre selector si se llama desde un botón). */
function connectSpeechMikeDevice() {
    connectSpeechMikeDeviceFromUserClick();
}

function setupSpeechMikeUiBindings() {
    $("#btnConnectSpeechMike").off("click.risSpeechMike").on("click.risSpeechMike", function () {
        if (typeof connectSpeechMikeDeviceFromUserClick === "function") {
            connectSpeechMikeDeviceFromUserClick();
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

    $("#btnTestSpeechMikeLed").off("click.risSpeechMike").on("click.risSpeechMike", function () {
        if (typeof testSpeechMikeLed === "function") {
            testSpeechMikeLed();
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
        const ready = await ensureSpeechMikeManagerReady();
        if (!ready) {
            return;
        }

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

function setupSpeechMikeConnectDelegation() {
    if (window._risSpeechMikeConnectDelegated) {
        return;
    }
    window._risSpeechMikeConnectDelegated = true;

    document.addEventListener(
        "click",
        function (e) {
            const btn = e.target && e.target.closest ? e.target.closest("#btnConnectSpeechMike") : null;
            if (!btn) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            if (typeof connectSpeechMikeDeviceFromUserClick === "function") {
                connectSpeechMikeDeviceFromUserClick();
                return;
            }
            risNotifySpeechMike(
                "Módulo SpeechMike no cargado. Pulse Ctrl+F5 o vuelva a entrar en Radiólogo.",
                "warning",
                10000
            );
        },
        true
    );
}

setupSpeechMikeConnectDelegation();

window.connectSpeechMikeDevice = connectSpeechMikeDeviceFromUserClick;
window.connectSpeechMikeDeviceFromUserClick = connectSpeechMikeDeviceFromUserClick;
window.initSpeechMikeDictation = initSpeechMikeDictation;
window.setupSpeechMikeUiBindings = setupSpeechMikeUiBindings;
window.diagnoseSpeechMike = diagnoseSpeechMike;
window.testSpeechMikeLed = testSpeechMikeLed;
