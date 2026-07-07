/* =========================================
   ENRUTADOR PRINCIPAL (SPA) - router.js
   ========================================= */

const RIS_MODULE_SCRIPTS = {
    agenda: "/js/workflow/agenda.js",
    worklist: ["/js/workflow/atencionTecnica.js", "/js/workflow/worklist.js"],
    atencion: ["/js/workflow/atencionTecnica.js", "/js/workflow/atencion.js"],
    radiologist: [
        "/js/shared/workflow-inbox.js",
        "/js/workflow/browser-dictation.js",
        "/js/workflow/speechmike-dictation.js",
        "/js/workflow/radiologist.js",
    ],
    transcription: ["/js/shared/workflow-inbox.js", "/js/workflow/transcription.js"],
    validation: ["/js/shared/workflow-inbox.js", "/js/shared/reportDocument.js", "/js/workflow/validation.js"],
    entrega: ["/js/shared/reportDocument.js", "/js/workflow/entrega.js"],
    dashboard: "/js/dashboard/dashboard.js",
    admin: "/js/admin/admin.js",
};

const RIS_MODULE_INIT = {
    agenda: "initAgenda",
    worklist: "initWorklist",
    atencion: "initAtencion",
    radiologist: "initRadiologist",
    transcription: "initTranscription",
    validation: "initValidation",
    entrega: "initEntrega",
    dashboard: "initDashboard",
    admin: "initAdmin",
};

const _loadedScriptBases = new Set();
let _loadedScriptBuild = null;

function risScriptPath(path) {
    const clean = String(path || "").replace(/\?.*$/, "");
    return clean.startsWith("/") ? clean : `/${clean}`;
}

function risInvalidateModuleScriptsIfBuildChanged() {
    const build = String(window.RIS_BUILD || "");
    if (_loadedScriptBuild === build) return;

    const previousBuild = _loadedScriptBuild;
    _loadedScriptBuild = build;

    // Primera carga de la sesión: no borrar scripts ya incluidos en layout.html.
    if (previousBuild === null) return;

    _loadedScriptBases.clear();
    document.querySelectorAll("script[src]").forEach((el) => {
        const src = el.getAttribute("src") || "";
        if (/\/js\/(workflow|admin|dashboard)\//.test(src)) {
            el.remove();
        }
    });
}

function risAssetUrl(path) {
    const v = window.RIS_BUILD || Date.now();
    const normalized = risScriptPath(path);
    return `${normalized}?v=${encodeURIComponent(v)}`;
}

function scriptBasePath(src) {
    return risScriptPath(src);
}

function isBrowserDictationScriptReady() {
    return (
        typeof window.risStartBrowserDictationClick === "function"
        && window.__risBrowserDictationInstalled === true
    );
}

function purgeBrokenBrowserDictationScripts() {
    document.querySelectorAll('script[src*="browser-dictation"]').forEach((el) => el.remove());
    _loadedScriptBases.delete("/js/workflow/browser-dictation.js");
    window.__risBrowserDictationInstalled = false;
}

function scriptAlreadyInDom(base) {
    return Array.from(document.querySelectorAll("script[src]")).some((el) => {
        const attr = scriptBasePath(el.getAttribute("src") || "");
        return attr === base;
    });
}

function loadScriptOnce(src) {
    risInvalidateModuleScriptsIfBuildChanged();
    const base = scriptBasePath(src);
    const versionedSrc = risAssetUrl(base);
    const isDictation = base.includes("browser-dictation");

    return new Promise((resolve, reject) => {
        if (_loadedScriptBases.has(base) && scriptAlreadyInDom(base)) {
            if (isDictation && !isBrowserDictationScriptReady()) {
                purgeBrokenBrowserDictationScripts();
            } else {
                resolve();
                return;
            }
        } else if (_loadedScriptBases.has(base)) {
            _loadedScriptBases.delete(base);
        }

        if (scriptAlreadyInDom(base)) {
            if (isDictation && !isBrowserDictationScriptReady()) {
                purgeBrokenBrowserDictationScripts();
            } else {
                _loadedScriptBases.add(base);
                resolve();
                return;
            }
        }

        const script = document.createElement("script");
        script.src = versionedSrc;
        script.async = false;
        script.onload = () => {
            _loadedScriptBases.add(base);
            resolve();
        };
        script.onerror = () => {
            const abs = new URL(versionedSrc, window.location.href).href;
            reject(new Error(`No se pudo cargar ${abs}`));
        };
        document.body.appendChild(script);
    });
}

async function ensureModuleLoaded(page) {
    const spec = RIS_MODULE_SCRIPTS[page];
    const scripts = Array.isArray(spec) ? spec : (spec ? [spec] : []);
    for (const src of scripts) {
        try {
            await loadScriptOnce(src);
        } catch (err) {
            console.error(`[RIS] No se pudo cargar ${src}:`, err);
            if (scriptBasePath(src).includes("browser-dictation")) {
                continue;
            }
            throw err;
        }
    }
    if (page === "agenda") await loadScriptOnce("/js/workflow/payments.js");
}

let _risCurrentPage = null;

function loadPage(page) {
    console.log("Cargando página:", page);

    if (_risCurrentPage === "agenda" && page !== "agenda" && typeof destroyAgenda === "function") {
        destroyAgenda();
    }
    _risCurrentPage = page;
    sincronizarSidebar(page);

    if (typeof showLoader === "function") showLoader("Cargando módulo…");

    ensureModuleLoaded(page)
        .then(() => {
            $("#appContent").load(risAssetUrl("pages/" + page + ".html"), function (response, status) {
                if (typeof hideLoader === "function") hideLoader();
                localStorage.setItem("ris_last_page", page);

                if (status === "error") {
                    $("#appContent").html(
                        `<div class="alert alert-danger fw-bold m-4" role="alert">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Error 404: No se pudo cargar el módulo '${page}.html'
                        </div>`
                    );
                    return;
                }

                if (typeof applyLabProfileUI === "function") {
                    applyLabProfileUI(document.getElementById("appContent") || document);
                }

                const initFn = RIS_MODULE_INIT[page];
                if (initFn && typeof window[initFn] === "function") {
                    if (typeof risGuardConcreteLabForModule === "function" && !risGuardConcreteLabForModule(page)) {
                        $("#appContent").prepend(
                            `<div class="alert alert-warning m-4 fw-bold" role="alert">
                                <i class="bi bi-building me-2"></i>
                                Seleccione una sede específica en el selector superior para usar este módulo.
                            </div>`
                        );
                        return;
                    }
                    Promise.resolve(window[initFn]()).catch((err) => {
                        console.error(`Error en ${initFn}:`, err);
                        showToast(`Error al iniciar ${page}: ${err.message}`, "danger");
                    });
                }
            });
        })
        .catch((err) => {
            if (typeof hideLoader === "function") hideLoader();
            showToast(`Error cargando scripts: ${err.message}`, "danger");
        });
}

window.risLoadScript = loadScriptOnce;
window.risIsBrowserDictationScriptReady = isBrowserDictationScriptReady;

function sincronizarSidebar(paginaActiva) {
    document.querySelectorAll("#sidebar nav a").forEach((enlace) => {
        enlace.classList.remove("active");
    });
    const enlaceSeleccionado = document.querySelector(`#sidebar nav a[data-page="${paginaActiva}"]`);
    if (enlaceSeleccionado) enlaceSeleccionado.classList.add("active");
}
