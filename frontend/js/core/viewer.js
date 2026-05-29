/* Configuración centralizada del visor DICOM (OHIF / bridge / URL custom) */

let _viewerConfigCache = null;

function looksLikeStudyInstanceUid(value) {
    const s = String(value || "").trim();
    return /^[\d.]+$/.test(s) && s.split(".").length >= 3;
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

/**
 * Resuelve StudyInstanceUID en PACS antes de abrir OHIF (visor en otro servidor).
 * @param {string} accessionOrUid
 * @param {object} [cfg]
 * @param {{ studyInstanceUid?: string, appointmentId?: string }} [options]
 */
async function resolveStudyInstanceUid(accessionOrUid, cfg, options = {}) {
    const raw = String(accessionOrUid || "").trim();
    if (!raw) return null;

    const presetUid = String(options.studyInstanceUid || "").trim();
    if (presetUid && looksLikeStudyInstanceUid(presetUid)) {
        return presetUid;
    }

    if (looksLikeStudyInstanceUid(raw)) {
        return raw;
    }

    const token = localStorage.getItem("ris_token");
    const labId = localStorage.getItem("ris_lab_id") || "";

    let query = `accession=${encodeURIComponent(raw)}`;
    if (options.appointmentId) {
        query += `&appointment_id=${encodeURIComponent(options.appointmentId)}`;
    }

    try {
        if (typeof showToast === "function") {
            showToast("Buscando estudio en PACS…", "info");
        }

        const response = await fetch(`${API_URL}/viewer-study-uid?${query}`, {
            headers: {
                Authorization: `Bearer ${token}`,
                "X-Lab-Id": labId,
                Accept: "application/json",
            },
        });
        const res = await response.json();
        if (response.ok && res.success && res.data?.study_instance_uid) {
            return res.data.study_instance_uid;
        }

        const msg =
            res.message ||
            "No se encontró el estudio en PACS para ese número de acceso.";
        if (typeof showToast === "function") {
            showToast(msg, "warning");
        }
    } catch (e) {
        console.warn("No se pudo resolver StudyInstanceUID:", e);
        if (typeof showToast === "function") {
            showToast(
                "No se pudo consultar el PACS para abrir el visor. Revise la conexión.",
                "danger"
            );
        }
    }

    return null;
}

function viewerOpenOptions(accessionNumber, extra = {}) {
    const chain = extra.chain || null;
    return {
        studyInstanceUid: extra.studyInstanceUid || chain?.studyInstanceUid || null,
        appointmentId: extra.appointmentId || chain?.id || null,
    };
}

/**
 * URL OHIF (servidor externo): solo StudyInstanceUID, p. ej.
 * https://viewer.healthticloud.cl/viewer?StudyInstanceUIDs=3.1.7&token=...
 */
function buildOhifViewerUrl(cfg, studyInstanceUid) {
    const base = (cfg.viewer_url || "https://viewer.healthticloud.cl").replace(/\/$/, "");
    const path = (cfg.viewer_path || "/viewer").replace(/^\/?/, "/");
    const param = cfg.viewer_query_param || "StudyInstanceUIDs";

    const url = new URL(`${base}${path}`);

    url.searchParams.set(param, studyInstanceUid);

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

    const uid = studyInstanceUid || accessionNumber;

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
    const studyUid = await resolveStudyInstanceUid(accessionNumber, config, opts);
    if (!studyUid) {
        if (typeof showToast === "function") {
            showToast(
                "No hay StudyInstanceUID para abrir OHIF. El estudio debe existir en PACS.",
                "warning"
            );
        }
        return;
    }
    const urlWeb = buildOhifViewerUrl(config, studyUid);
    window.open(urlWeb, "_blank");
    if (typeof showToast === "function") {
        showToast("Visor OHIF abierto con StudyInstanceUID.", "info");
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

    const studyUid =
        opts.studyInstanceUid && looksLikeStudyInstanceUid(opts.studyInstanceUid)
            ? opts.studyInstanceUid
            : await resolveStudyInstanceUid(accessionNumber, cfg, opts);

    const customUrl = buildCustomViewerUrl(cfg, accessionNumber, studyUid);
    if (customUrl && tryOpenCustomViewerUrl(customUrl)) {
        if (typeof showToast === "function") {
            showToast("Enlace al visor (URL custom) enviado. Si no abre, use OHIF.", "info");
        }
        return;
    }

    await abrirVisorOHIF(accessionNumber, cfg, { ...extra, studyInstanceUid: studyUid });
}

window.abrirVisorPACS = abrirVisorPACS;
window.abrirVisorOHIF = abrirVisorOHIF;
window.abrirVisorSoloOHIF = abrirVisorSoloOHIF;
