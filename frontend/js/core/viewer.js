/* Configuración centralizada del visor DICOM (OHIF) / PACS */

let _viewerConfigCache = null;

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

function buildOhifViewerUrl(cfg, accessionNumber) {
    const base = (cfg.viewer_url || "https://viewer.healthticloud.cl").replace(/\/$/, "");
    const param = cfg.viewer_accession_param || "AccessionNumber";
    const url = new URL(base);

    url.searchParams.set(param, accessionNumber);

    const authToken = localStorage.getItem("ris_token") || cfg.viewer_token || "";
    if (authToken) {
        url.searchParams.set("token", authToken);
    }

    return url.toString();
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

    try {
        await fetch(cfg.pacs_bridge_url, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(pacsConfig),
        });
    } catch (error) {
        const urlWeb = buildOhifViewerUrl(cfg, accessionNumber);
        window.open(urlWeb, "_blank");
    }
}
