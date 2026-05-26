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

async function resolveStudyInstanceUid(accessionOrUid, cfg) {
    const raw = String(accessionOrUid || "").trim();
    if (!raw) return null;

    if (looksLikeStudyInstanceUid(raw)) {
        return raw;
    }

    const token = localStorage.getItem("ris_token");
    const labId = localStorage.getItem("ris_lab_id") || "";

    try {
        const response = await fetch(
            `${API_URL}/viewer-study-uid?accession=${encodeURIComponent(raw)}`,
            {
                headers: {
                    Authorization: `Bearer ${token}`,
                    "X-Lab-Id": labId,
                    Accept: "application/json",
                },
            }
        );
        const res = await response.json();
        if (response.ok && res.success && res.data?.study_instance_uid) {
            return res.data.study_instance_uid;
        }
    } catch (e) {
        console.warn("No se pudo resolver StudyInstanceUID:", e);
    }

    return raw;
}

/**
 * URL OHIF: https://viewer.healthticloud.cl/viewer?StudyInstanceUIDs=1.2.xxx
 */
function buildOhifViewerUrl(cfg, studyInstanceUid) {
    const base = (cfg.viewer_url || "https://viewer.healthticloud.cl").replace(/\/$/, "");
    const path = (cfg.viewer_path || "/viewer").replace(/^\/?/, "/");
    const param = cfg.viewer_query_param || cfg.viewer_accession_param || "StudyInstanceUIDs";

    const url = new URL(`${base}${path}`);

    url.searchParams.set(param, studyInstanceUid);

    const authToken = localStorage.getItem("ris_token") || cfg.viewer_token || "";
    if (authToken) {
        url.searchParams.set("token", authToken);
    }

    return url.toString();
}

function buildCustomViewerUrl(cfg, accessionNumber) {
    const tpl = (cfg.viewer_custom_url || "").trim();
    if (!tpl) return null;

    const map = {
        "{accession}": encodeURIComponent(accessionNumber),
        "{accession_number}": encodeURIComponent(accessionNumber),
        "{study_uid}": encodeURIComponent(accessionNumber),
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

async function abrirVisorOHIF(accessionNumber, cfg) {
    const config = cfg || (await getViewerConfig());
    const studyUid = await resolveStudyInstanceUid(accessionNumber, config);
    if (!studyUid) {
        showToast("No hay identificador de estudio para abrir el visor.", "warning");
        return;
    }
    const urlWeb = buildOhifViewerUrl(config, studyUid);
    window.open(urlWeb, "_blank");
    if (typeof showToast === "function") {
        showToast("Visor web (OHIF) abierto en nueva pestaña.", "info");
    }
}

async function abrirVisorSoloOHIF(accessionNumber) {
    if (!accessionNumber) {
        showToast("No hay número de acceso para abrir el visor.", "warning");
        return;
    }
    await abrirVisorOHIF(accessionNumber);
}

async function abrirVisorPACS(accessionNumber) {
    if (!accessionNumber) {
        showToast("No hay número de acceso para abrir el visor.", "warning");
        return;
    }

    const cfg = await getViewerConfig();
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

    const customUrl = buildCustomViewerUrl(cfg, accessionNumber);
    if (customUrl && tryOpenCustomViewerUrl(customUrl)) {
        if (typeof showToast === "function") {
            showToast("Enlace al visor (URL custom) enviado. Si no abre, use OHIF.", "info");
        }
        return;
    }

    await abrirVisorOHIF(accessionNumber, cfg);
}

window.abrirVisorPACS = abrirVisorPACS;
window.abrirVisorOHIF = abrirVisorOHIF;
window.abrirVisorSoloOHIF = abrirVisorSoloOHIF;
