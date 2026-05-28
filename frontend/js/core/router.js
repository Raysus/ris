/* =========================================
   ENRUTADOR PRINCIPAL (SPA) - router.js
   ========================================= */

const RIS_MODULE_SCRIPTS = {
    agenda: "js/workflow/agenda.js",
    worklist: ["js/workflow/atencionTecnica.js", "js/workflow/worklist.js"],
    atencion: ["js/workflow/atencionTecnica.js", "js/workflow/atencion.js"],
    radiologist: "js/workflow/radiologist.js",
    transcription: "js/workflow/transcription.js",
    validation: "js/workflow/validation.js",
    entrega: "js/workflow/entrega.js",
    dashboard: "js/dashboard/dashboard.js",
    admin: "js/admin/admin.js",
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

const _loadedModules = new Set();

function loadScriptOnce(src) {
    return new Promise((resolve, reject) => {
        if (_loadedModules.has(src)) {
            resolve();
            return;
        }
        const existing = document.querySelector(`script[src="${src}"]`);
        if (existing) {
            _loadedModules.add(src);
            resolve();
            return;
        }
        const script = document.createElement("script");
        script.src = src;
        script.async = false;
        script.onload = () => {
            _loadedModules.add(src);
            resolve();
        };
        script.onerror = () => reject(new Error(`No se pudo cargar ${src}`));
        document.body.appendChild(script);
    });
}

async function ensureModuleLoaded(page) {
    const spec = RIS_MODULE_SCRIPTS[page];
    const scripts = Array.isArray(spec) ? spec : (spec ? [spec] : []);
    for (const src of scripts) {
        await loadScriptOnce(src);
    }
    if (page === "agenda") await loadScriptOnce("js/workflow/payments.js");
}

function loadPage(page) {
    console.log("Cargando página:", page);
    sincronizarSidebar(page);

    if (typeof showLoader === "function") showLoader("Cargando módulo…");

    ensureModuleLoaded(page)
        .then(() => {
            $("#appContent").load("pages/" + page + ".html", function (response, status) {
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

                if (typeof applyLabProfileUI === 'function') {
                    applyLabProfileUI(document.getElementById('appContent') || document);
                }

                const initFn = RIS_MODULE_INIT[page];
                if (initFn && typeof window[initFn] === "function") {
                    if (typeof risGuardConcreteLabForModule === 'function' && !risGuardConcreteLabForModule(page)) {
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
                        showToast(`Error al iniciar ${page}: ${err.message}`, 'danger');
                    });
                }
            });
        })
        .catch((err) => {
            if (typeof hideLoader === "function") hideLoader();
            showToast(`Error cargando scripts: ${err.message}`, "danger");
        });
}

function sincronizarSidebar(paginaActiva) {
    document.querySelectorAll("#sidebar nav a").forEach((enlace) => {
        enlace.classList.remove("active");
    });
    const enlaceSeleccionado = document.querySelector(`#sidebar nav a[data-page="${paginaActiva}"]`);
    if (enlaceSeleccionado) enlaceSeleccionado.classList.add("active");
}
