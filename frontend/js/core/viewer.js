/* Configuración centralizada del visor DICOM (OHIF / bridge / URL custom) */

let _viewerConfigCache = null;
const _pacsStudyCache = new Map();

function looksLikeStudyInstanceUid(value) {
    const s = String(value || "").trim();
    if (!s || isAccessionNumber(s)) return false;
    return /^[\d.]+$/.test(s) && s.split(".").length >= 3;
}

function isAccessionNumber(value) {
    const s = String(value || "").trim();
    if (!s) return false;
    if (/^ACC-/i.test(s)) return true;
    return /[A-Za-z]/.test(s) && !looksLikeStudyInstanceUid(s);
}

function pacsStudyCacheKey(accessionNumber, appointmentId) {
    return `${appointmentId || ""}::${String(accessionNumber || "").trim()}`;
}

/**
 * Consulta Orthanc por accession (vía API RIS) y cachea StudyInstanceUID + metadatos.
 * Llamar al seleccionar un estudio en la bandeja.
 */
async function prefetchPacsStudy(accessionNumber, appointmentId = null) {
    const acc = String(accessionNumber || "").trim();
    if (!acc) return null;

    const key = pacsStudyCacheKey(acc, appointmentId);
    if (_pacsStudyCache.has(key)) {
        return _pacsStudyCache.get(key);
    }

    const token = localStorage.getItem("ris_token");
    const labId = localStorage.getItem("ris_lab_id") || "";

    let query = `accession=${encodeURIComponent(acc)}`;
    if (appointmentId) {
        query += `&appointment_id=${encodeURIComponent(appointmentId)}`;
    }

    try {
        const response = await fetch(`${API_URL}/viewer-study?${query}`, {
            headers: {
                Authorization: `Bearer ${token}`,
                "X-Lab-Id": labId,
                Accept: "application/json",
            },
        });
        const res = await response.json();

        if (response.ok && res.success && res.data?.study_instance_uid) {
            if (!looksLikeStudyInstanceUid(res.data.study_instance_uid)) {
                console.warn("PACS devolvió un UID inválido:", res.data);
                return null;
            }
            _pacsStudyCache.set(key, res.data);
            return res.data;
        }
    } catch (e) {
        console.warn(
            "prefetchPacsStudy:",
            e,
            "— Si ve CORS en consola, suele ser la API caída o sin cabeceras; no afecta SpeechMike."
        );
    }

    return null;
}

function applyPacsStudyToChain(chain, pacsStudy) {
    if (!chain || !pacsStudy?.study_instance_uid) return;
    chain.pacsStudy = pacsStudy;
    chain.studyInstanceUid = pacsStudy.study_instance_uid;
}

async function getViewerConfig() {
    if (_viewerConfigCache) return _viewerConfigCache;

    const token = localStorage.getItem("ris_token");
    const labId = localStorage.getItem("ris_lab_id") || "";

    const response = await fetch(`${API_URL}/viewer-config`, {
        headers: {
            Authorization: `Bearer ${token}`,
            "X-Lab-Id": labId,
            Accept: "application/json",
        },
    });

    const res = await response.json();
    if (!response.ok || !res.success) {
        throw new Error(res.message || "No se pudo cargar configuración del visor.");
    }

    _viewerConfigCache = res.data;
    return _viewerConfigCache;
}

function viewerOpenOptions(accessionNumber, extra = {}) {
    const chain = extra.chain || null;
    return {
        studyInstanceUid: extra.studyInstanceUid || chain?.studyInstanceUid || null,
        appointmentId: extra.appointmentId || chain?.id || null,
        pacsStudy: extra.pacsStudy || chain?.pacsStudy || null,
    };
}

/**
 * Obtiene StudyInstanceUID DICOM (nunca accession) para abrir OHIF.
 */
async function ensureStudyInstanceUidForViewer(accessionNumber, extra = {}) {
    const raw = String(accessionNumber || "").trim();
    if (!raw) return null;

    const opts = viewerOpenOptions(accessionNumber, extra);

    if (opts.studyInstanceUid && looksLikeStudyInstanceUid(opts.studyInstanceUid)) {
        return opts.studyInstanceUid;
    }

    if (opts.pacsStudy?.study_instance_uid && looksLikeStudyInstanceUid(opts.pacsStudy.study_instance_uid)) {
        return opts.pacsStudy.study_instance_uid;
    }

    const cached = _pacsStudyCache.get(pacsStudyCacheKey(raw, opts.appointmentId));
    if (cached?.study_instance_uid && looksLikeStudyInstanceUid(cached.study_instance_uid)) {
        if (extra.chain) applyPacsStudyToChain(extra.chain, cached);
        return cached.study_instance_uid;
    }

    if (looksLikeStudyInstanceUid(raw)) {
        return raw;
    }

    if (isAccessionNumber(raw)) {
        const pacs = await prefetchPacsStudy(raw, opts.appointmentId);
        if (pacs?.study_instance_uid) {
            if (extra.chain) applyPacsStudyToChain(extra.chain, pacs);
            return pacs.study_instance_uid;
        }
    }

    return null;
}

/**
 * URL OHIF: solo StudyInstanceUIDs (nunca AccessionNumber).
 */
function buildOhifViewerUrl(cfg, studyInstanceUid) {
    const uid = String(studyInstanceUid || "").trim();

    if (!looksLikeStudyInstanceUid(uid)) {
        throw new Error(
            `Refusing OHIF URL: "${uid}" no es un StudyInstanceUID (¿se envió accession?).`
        );
    }

    const base = (cfg.viewer_url || "https://viewer.healthticloud.cl").replace(/\/$/, "");
    const path = (cfg.viewer_path || "/viewer").replace(/^\/?/, "/");
    const param = cfg.viewer_query_param || "StudyInstanceUIDs";

    const url = new URL(`${base}${path}`);
    url.searchParams.set(param, uid);

    const authToken =
        (cfg.viewer_token || "").trim() || localStorage.getItem("ris_token") || "";
    if (authToken) {
        url.searchParams.set("token", authToken);
    }

    return url.toString();
}

function buildCustomViewerUrl(cfg, accessionNumber, studyInstanceUid) {
    const tpl = (cfg.viewer_custom_url || "").trim();
    if (!tpl) return null;

    const uid =
        studyInstanceUid && looksLikeStudyInstanceUid(studyInstanceUid)
            ? studyInstanceUid
            : "";

    const map = {
        "{accession}": encodeURIComponent(accessionNumber),
        "{accession_number}": encodeURIComponent(accessionNumber),
        "{study_uid}": encodeURIComponent(uid),
        "{pacs_ip}": encodeURIComponent(cfg.pacs_ip || ""),
        "{pacs_port}": encodeURIComponent(String(cfg.pacs_port ?? "")),
        "{pacs_aet}": encodeURIComponent(cfg.pacs_aet || ""),
    };

    let url = tpl;
    Object.entries(map).forEach(([key, val]) => {
        url = url.split(key).join(val);
        url = url.split(key.toUpperCase()).join(val);
    });

    return url;
}

function tryOpenCustomViewerUrl(url) {
    if (!url) return false;

    try {
        const anchor = document.createElement("a");
        anchor.href = url;
        anchor.target = "_blank";
        anchor.rel = "noopener noreferrer";
        anchor.style.display = "none";
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        return true;
    } catch (e) {
        console.warn("No se pudo abrir URL custom del visor:", e);
        return false;
    }
}

async function abrirVisorOHIF(accessionNumber, cfg, extra = {}) {
    const config = cfg || (await getViewerConfig());
    const opts = viewerOpenOptions(accessionNumber, extra);

    if (typeof showToast === "function") {
        showToast("Consultando PACS por accession…", "info");
    }

    const studyUid = await ensureStudyInstanceUidForViewer(accessionNumber, {
        ...extra,
        ...opts,
    });

    if (!studyUid) {
        if (typeof showToast === "function") {
            showToast(
                "No se obtuvo StudyInstanceUID en Orthanc para ese accession. ¿Ya hay imágenes en PACS?",
                "warning"
            );
        }
        return;
    }

    let urlWeb;
    try {
        urlWeb = buildOhifViewerUrl(config, studyUid);
    } catch (e) {
        console.error(e);
        if (typeof showToast === "function") showToast(e.message, "danger");
        return;
    }

    console.info("[OHIF]", urlWeb);
    window.open(urlWeb, "_blank");

    if (typeof showToast === "function") {
        showToast(`Visor OHIF: StudyInstanceUID ${studyUid}`, "success");
    }
}

async function abrirVisorSoloOHIF(accessionNumber, extra = {}) {
    if (!accessionNumber) {
        showToast("No hay número de acceso para abrir el visor.", "warning");
        return;
    }
    await abrirVisorOHIF(accessionNumber, null, extra);
}

async function abrirVisorPACS(accessionNumber, extra = {}) {
    if (!accessionNumber) {
        showToast("No hay número de acceso para abrir el visor.", "warning");
        return;
    }

    const cfg = await getViewerConfig();
    const opts = viewerOpenOptions(accessionNumber, extra);
    const pacsConfig = {
        accession_number: accessionNumber,
        pacs_ip: cfg.pacs_ip,
        pacs_port: cfg.pacs_port,
        pacs_aet: cfg.pacs_aet,
    };

    let bridgeOk = false;

    try {
        const res = await fetch(cfg.pacs_bridge_url, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(pacsConfig),
        });

        let data = {};
        try {
            data = await res.json();
        } catch (e) {
            data = {};
        }

        if (res.ok && data.success !== false) {
            bridgeOk = true;
            if (typeof showToast === "function") {
                showToast("Visor local abierto.", "success");
            }
        }
    } catch (error) {
        console.warn("Bridge local no detectado:", error);
    }

    if (bridgeOk) return;

    const studyUid = await ensureStudyInstanceUidForViewer(accessionNumber, {
        ...extra,
        ...opts,
    });

    const customUrl = buildCustomViewerUrl(cfg, accessionNumber, studyUid);
    if (customUrl && tryOpenCustomViewerUrl(customUrl)) {
        if (typeof showToast === "function") {
            showToast("Enlace al visor (URL custom) enviado. Si no abre, use OHIF.", "info");
        }
        return;
    }

    await abrirVisorOHIF(accessionNumber, cfg, {
        ...extra,
        studyInstanceUid: studyUid,
        pacsStudy: opts.pacsStudy,
    });
}

window.prefetchPacsStudy = prefetchPacsStudy;
window.applyPacsStudyToChain = applyPacsStudyToChain;
window.abrirVisorPACS = abrirVisorPACS;
window.abrirVisorOHIF = abrirVisorOHIF;
window.abrirVisorSoloOHIF = abrirVisorSoloOHIF;
