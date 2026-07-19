/* =========================================
   MÓDULO RADIÓLOGO (radiologist.js) - ENTERPRISE
   ========================================= */

let currentReportingChain = null;
let currentRadioStudy = null;
let currentRadiologistData = [];
let radiologistExamDateFilter = null;
let audioBlob = null;
let mediaRecorder;
let audioChunks = [];
let currentDictationMethod = 'teclado';
let dragonSyncInterval = null;

// === NUEVAS VARIABLES GLOBALES ===
let allTemplates = [];
let autoSaveInterval = null;
let lastSavedText = "";
let recordingInterval;
let recordingSeconds = 0;
let recordingPausedByUser = false;
const MAX_RECORDING_SECONDS = 600;
let isSplitScreen = false;
let radiologistRefreshInterval = null;

function risDictationModuleMissingFeedback() {
    const msg =
        "No se cargó el módulo de dictado. Pulse Ctrl+F5. En Brave: desactive Shields para este sitio.";
    const st = document.getElementById("browserDictationStatus");
    if (st) {
        st.className = "small text-danger fw-bold mb-0 mt-2";
        st.textContent = msg;
    }
    if (typeof showToast === "function") {
        showToast(msg, "danger");
    } else if (typeof showAlert === "function") {
        showAlert(msg, "Dictado por voz", "danger");
    }
}

async function ensureBrowserDictationModuleLoaded() {
    if (typeof window.risStartBrowserDictationClick === "function") {
        return true;
    }
    if (typeof window.risLoadScript === "function") {
        try {
            await window.risLoadScript("js/workflow/browser-dictation.js");
        } catch (err) {
            console.error("ensureBrowserDictationModuleLoaded:", err);
        }
    }
    return typeof window.risStartBrowserDictationClick === "function";
}

function wireBrowserDictationButton() {
    const btn = document.getElementById("btnBrowserDictation");
    if (!btn) {
        return;
    }
    if (btn.dataset.risWired === "1") {
        return;
    }
    btn.dataset.risWired = "1";
    btn.addEventListener(
        "click",
        async function (e) {
            e.preventDefault();
            e.stopPropagation();
            const st = document.getElementById("browserDictationStatus");
            if (st) {
                st.className = "small text-muted mb-0 mt-2";
                st.textContent = "Cargando módulo de dictado…";
            }
            const ok = await ensureBrowserDictationModuleLoaded();
            if (!ok) {
                risDictationModuleMissingFeedback();
                return;
            }
            window.risStartBrowserDictationClick(e);
        },
        false
    );
}
let recordingMediaStream = null;

const DICTATION_MODE_STORAGE = "ris_radiologist_dictation_mode_v1";
let radiologistAiStatus = null;

function getRadiologistDictationMode() {
    const $sel = $("#selRadiologistDictationMode");
    if ($sel.length) {
        const v = $sel.val() || "audio_tm";
        return v === "voice" ? "audio_tm" : v;
    }
    try {
        const saved = localStorage.getItem(DICTATION_MODE_STORAGE) || "audio_tm";
        return saved === "voice" ? "audio_tm" : saved;
    } catch (e) {
        return "audio_tm";
    }
}

function saveRadiologistDictationMode(mode) {
    try {
        localStorage.setItem(DICTATION_MODE_STORAGE, mode);
    } catch (e) { /* ignore */ }
}

function applyRadiologistDictationMode(mode) {
    const m = mode || getRadiologistDictationMode();
    const isAi = m === "ai";

    // Voz (navegador/Dragon) y micrófono siempre visibles; solo cambia el destino del audio.
    $("#panelDictationVoice, #panelDictationAudio").removeClass("d-none");

    if (isAi) {
        $("#audioPanelTitle").html('<i class="bi bi-stars me-1" aria-hidden="true"></i> Grabar para IA');
        $("#audioPanelSubtitle").text("Micrófono del PC → transcripción en la nube (requiere internet).");
        $("#btnAiTranscribe").removeClass("d-none");
        $("#radiologistDictationModeHint").text(
            "Dictado por voz y micrófono disponibles. Tras grabar, use «Transcribir con IA»."
        );
        refreshRadiologistAiStatus();
    } else {
        $("#audioPanelTitle").html('<i class="bi bi-mic-fill me-1" aria-hidden="true"></i> Grabar audio');
        $("#audioPanelSubtitle").text("Micrófono del PC para secretaría (máx. 10 min).");
        $("#btnAiTranscribe").addClass("d-none");
        $("#aiCloudStatus").addClass("d-none");
        $("#radiologistDictationModeHint").text(
            "Dictado por voz y micrófono disponibles. El audio se envía con «Enviar a Transcripción»."
        );
    }

    syncAiTranscribeButton();
}

function setupDictationModeUi() {
    const $sel = $("#selRadiologistDictationMode");
    if (!$sel.length) {
        return;
    }
    let saved = "audio_tm";
    try {
        saved = localStorage.getItem(DICTATION_MODE_STORAGE) || "audio_tm";
    } catch (e) { /* ignore */ }
    if (saved === "voice") {
        saved = "audio_tm";
    }
    if (["audio_tm", "ai"].includes(saved)) {
        $sel.val(saved);
    }
    $sel.off("change.risDictMode").on("change.risDictMode", function () {
        const mode = $(this).val();
        saveRadiologistDictationMode(mode);
        applyRadiologistDictationMode(mode);
    });
    applyRadiologistDictationMode($sel.val());
}

async function refreshRadiologistAiStatus() {
    const $box = $("#aiCloudStatus");
    if (!$box.length || getRadiologistDictationMode() !== "ai") {
        return;
    }
    $box.removeClass("d-none alert-success alert-warning alert-danger alert-light")
        .addClass("alert-light")
        .html('<span class="spinner-border spinner-border-sm me-1"></span> Comprobando conexión a la nube…');

    try {
        const response = await fetch(`${API_URL}/radiologist/ai-transcription/status`, {
            headers: typeof risBuildAuthHeaders === "function" ? risBuildAuthHeaders() : {},
        });
        const data = await response.json().catch(() => ({}));
        radiologistAiStatus = data;
        const available = !!data.available;
        const msg = data.message || (available ? "IA disponible." : "IA no disponible.");
        $box
            .removeClass("alert-light alert-success alert-warning alert-danger")
            .addClass(available ? "alert-success" : "alert-warning")
            .html(
                available
                    ? `<i class="bi bi-cloud-check me-1" aria-hidden="true"></i>${risEscapeHtml(msg)}`
                    : `<i class="bi bi-cloud-slash me-1" aria-hidden="true"></i>${risEscapeHtml(msg)}`
            );
        syncAiTranscribeButton();
    } catch (e) {
        radiologistAiStatus = { available: false };
        $box
            .removeClass("alert-light alert-success")
            .addClass("alert-danger")
            .html('<i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i> No se pudo comprobar el estado de la IA.');
        syncAiTranscribeButton();
    }
}

function syncAiTranscribeButton() {
    const mode = getRadiologistDictationMode();
    const $btn = $("#btnAiTranscribe");
    if (!$btn.length) {
        return;
    }
    if (mode !== "ai") {
        $btn.addClass("d-none").prop("disabled", true);
        return;
    }
    $btn.removeClass("d-none");
    const aiOk = radiologistAiStatus == null || radiologistAiStatus.available !== false;
    $btn.prop("disabled", !audioBlob || !currentRadioStudy || !aiOk);
}

async function enviarATranscripcionIa() {
    if (!currentRadioStudy || !audioBlob) {
        if (typeof showToast === "function") {
            showToast("Grabe un audio primero para transcribir con IA.", "warning");
        }
        return;
    }
    if (radiologistAiStatus && radiologistAiStatus.available === false) {
        if (typeof showToast === "function") {
            showToast(radiologistAiStatus.message || "IA no disponible (sin conexión a la nube).", "warning");
        }
        await refreshRadiologistAiStatus();
        return;
    }

    const $btn = $("#btnAiTranscribe");
    const prevHtml = $btn.html();
    try {
        $btn.prop("disabled", true).html('<span class="spinner-border spinner-border-sm"></span> Transcribiendo…');
        const formData = new FormData();
        formData.append("audio", audioBlob, `dictado_${currentRadioStudy.study_id}.webm`);
        formData.append("study_id", currentRadioStudy.study_id);
        formData.append("language", "es");

        const token = localStorage.getItem("ris_token");
        const labId = localStorage.getItem("ris_lab_id");
        const response = await fetch(`${API_URL}/radiologist/ai-transcription`, {
            method: "POST",
            headers: { Authorization: `Bearer ${token}`, "X-Lab-Id": labId },
            body: formData,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success === false) {
            throw new Error(data.message || `Error ${response.status}`);
        }
        const text = (data.text || "").trim();
        if (!text) {
            throw new Error("La IA no devolvió texto.");
        }
        const $ta = $("#textoInforme");
        const existing = ($ta.val() || "").trim();
        const merged = existing ? `${existing}\n\n${text}` : text;
        $ta.val(merged).prop("disabled", false).trigger("input");
        if (currentRadioStudy) {
            currentRadioStudy.reportText = merged;
        }
        currentDictationMethod = "ai";
        if (typeof showToast === "function") {
            showToast("Texto transcrito con IA. Revíselo antes de firmar.", "success");
        }
    } catch (e) {
        if (typeof showToast === "function") {
            showToast(e.message || "Error al transcribir con IA", "danger");
        }
        await refreshRadiologistAiStatus();
    } finally {
        $btn.html(prevHtml);
        syncAiTranscribeButton();
    }
}

function initRadiologist() {
    const profile = (localStorage.getItem('ris_user_profile') || '').toLowerCase();
    const esAdminFiltro = profile === 'admin' || profile === 'sis_admin'
        || (typeof risIsClinicAdmin === 'function' && risIsClinicAdmin());

    if (esAdminFiltro) {
        $("#filtroAdminContainer").removeClass("d-none");
    }

    if (typeof risInitWorkflowExamDateFilter === "function") {
        radiologistExamDateFilter = risInitWorkflowExamDateFilter({
            moduleKey: "radiologist",
            rootSelector: "[data-ris-exam-date-filter]",
            onChange: () => cargarEstudiosRadiologo(),
        });
    }

    cargarPlantillasRadiologo();
    cargarEstudiosRadiologo();
    setupAudioEvents();
    setupDictationModeUi();
    wireBrowserDictationButton();
    if (typeof risEnableTextFilePaste === "function") {
        risEnableTextFilePaste(["#textoInforme", "#txtAnamnesis"]);
    }
    if (typeof setupBrowserDictationUi === "function") {
        setupBrowserDictationUi();
    } else if (typeof showToast === "function") {
        showToast("Dictado por voz: módulo no cargado. Pulse Ctrl+F5.", "warning");
    }
    if (typeof setupSpeechMikeUiBindings === "function") {
        setupSpeechMikeUiBindings();
    }
    if (typeof initSpeechMikeDictation === "function") {
        initSpeechMikeDictation();
    }
    $("#btnDragon").off("click.risDragon").on("click.risDragon", activarDragon);
    $(document)
        .off("click.risRadiologistSign", "#btnFirmarDirecto")
        .on("click.risRadiologistSign", "#btnFirmarDirecto", function (e) {
            e.preventDefault();
            firmarDirecto();
        });
    $(document)
        .off("click.risRadiologistTranscribe", "#btnEnviarTranscripcion")
        .on("click.risRadiologistTranscribe", "#btnEnviarTranscripcion", function (e) {
            e.preventDefault();
            enviarATranscripcion();
        });
    $(document)
        .off("click.risAiTranscribe", "#btnAiTranscribe")
        .on("click.risAiTranscribe", "#btnAiTranscribe", function (e) {
            e.preventDefault();
            enviarATranscripcionIa();
        });

    if (radiologistRefreshInterval) clearInterval(radiologistRefreshInterval);

    radiologistRefreshInterval = setInterval(() => {
        if (currentRadioStudy || currentReportingChain || $("#modalInforme").is(":visible")) return;
        cargarEstudiosRadiologo();
    }, 30000);
}

async function cargarPlantillasRadiologo() {
    try {
        const response = await fetch(`${API_URL}/templates`, {
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        const data = await response.json();
        if (response.ok && data.success) {
            allTemplates = data.data;
            const dropdown = $("#dropdownPlantillas");
            dropdown.empty();

            if (allTemplates.length === 0) {
                dropdown.append('<li><span class="dropdown-item text-muted">No hay plantillas creadas</span></li>');
                return;
            }

            allTemplates.forEach(tpl => {
                dropdown.append(`<li><a class="dropdown-item" href="javascript:void(0);" onclick="aplicarPlantillaId('${tpl.id}')"><b>[${risEscapeHtml(tpl.group_code)}]</b> ${risEscapeHtml(tpl.title)}</a></li>`);
            });
        }
    } catch (e) {
        console.error("Error cargando plantillas:", e);
    }
}

function aplicarPlantillaId(id) {
    if (!currentRadioStudy) return showToast("Seleccione un estudio primero.", "warning");
    const tpl = allTemplates.find(t => String(t.id) === String(id));
    if (!tpl) return;

    const textarea = $("#textoInforme");
    const separador = textarea.val().trim() !== "" ? "\n\n---\n\n" : "";
    textarea.val(textarea.val() + separador + tpl.content);

    currentRadioStudy.reportText = textarea.val();
    showToast(`Plantilla "${tpl.title}" insertada.`, "info");
    ejecutarAutoguardado(); // Guardar inmediatamente
}

// === 2. AUTOGUARDADO (DRAFT) ===
function iniciarAutoguardado() {
    if (autoSaveInterval) clearInterval(autoSaveInterval);
    autoSaveInterval = setInterval(ejecutarAutoguardado, 20000); // Cada 20 segundos
}

async function ejecutarAutoguardado() {
    if (!currentReportingChain || !currentRadioStudy) return;

    const textoActual = $("#textoInforme").val();
    if (textoActual === lastSavedText) return; // No hay cambios, no hacer request

    $("#autoSaveIndicator").html('<span class="spinner-border spinner-border-sm text-primary"></span>').fadeIn();

    try {
        const paqueteInformes = currentReportingChain.studies.map(s => ({
            id: s.study_id,
            text: s.reportText
        }));

        const response = await fetch(`${API_URL}/radiologist/appointments/${currentReportingChain.id}/draft`, {
            method: 'POST',
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders({ 'Content-Type': 'application/json' }) : {},
            body: JSON.stringify({ reports: paqueteInformes })
        });

        if (response.ok) {
            lastSavedText = textoActual;
            $("#autoSaveIndicator").html('<i class="bi bi-cloud-check-fill text-success me-1"></i>Guardado');
            setTimeout(() => $("#autoSaveIndicator").fadeOut(), 3000);
        }
    } catch (e) {
        console.error("Fallo el autoguardado en background", e);
        $("#autoSaveIndicator").html('<i class="bi bi-cloud-slash-fill text-danger me-1"></i>Error al guardar').fadeOut(4000);
    }
}

// === 3. ADENDAS MÉDICAS ===
function insertarAdenda() {
    if (!currentRadioStudy) return;
    const textarea = $("#textoInforme");
    const fechaActual = new Date().toLocaleString('es-CL');
    const bloqueAdenda = `\n\n========================================\nADENDA AÑADIDA EL: ${fechaActual}\n========================================\n`;

    textarea.val(textarea.val() + bloqueAdenda);
    currentRadioStudy.reportText = textarea.val();
    textarea.focus();
    ejecutarAutoguardado();
}

// === 4. PANTALLA DIVIDIDA (SPLIT SCREEN) ===
function toggleHistorialPanel() {
    const colEditor = $("#colEditor");
    const colHistorial = $("#colHistorial");

    if (isSplitScreen) {
        // Cerrar Split
        colHistorial.removeClass("d-flex").addClass("d-none");
        colEditor.css("width", "100%");
        isSplitScreen = false;
        $("#btnHistorialPaciente").removeClass("bg-primary").addClass("btn-info text-white");
    } else {
        // Abrir Split
        if (!currentReportingChain) return showToast("Seleccione un paciente primero", "warning");
        colHistorial.removeClass("d-none").addClass("d-flex");
        colEditor.css("width", "50%");
        isSplitScreen = true;
        $("#btnHistorialPaciente").removeClass("btn-info text-white").addClass("bg-primary text-white");
        cargarHistorialSplit();
    }
}

async function cargarHistorialSplit() {
    const p = currentReportingChain.patient;
    const contenedor = $("#contenedorHistorialSplit");
    contenedor.html('<div class="text-center p-4"><span class="spinner-border text-primary"></span> Buscando informes...</div>');

    try {
        const response = await fetch(`${API_URL}/patients/${p.rut}/history`, {
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        const data = await response.json();

        if (response.ok && data.success) {
            contenedor.empty();
            if (data.data.length === 0) {
                return contenedor.html('<div class="alert alert-info shadow-sm small"><i class="bi bi-info-circle me-2"></i>No hay historial previo para comparar.</div>');
            }

            data.data.forEach(informe => {
                contenedor.append(`
                    <div class="card shadow-sm border-0 mb-3 ris-font-sm">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center py-2 border-0">
                            <strong class="text-dark">${risEscapeHtml(informe.exam_name)}</strong>
                            <span class="badge bg-secondary">${new Date(informe.date).toLocaleDateString('es-CL')}</span>
                        </div>
                        <div class="card-body py-2">
                            <p class="text-muted mb-2 ris-font-075">Dr(a). ${risEscapeHtml(informe.doctor_name)}</p>
                            <div class="p-2 bg-white border rounded text-dark ris-pre-wrap-select">${risEscapeHtml(informe.report_text)}</div>
                        </div>
                    </div>
                `);
            });
        }
    } catch (error) {
        contenedor.html('<div class="text-danger p-3"><i class="bi bi-wifi-off me-2"></i>Error de conexión.</div>');
    }
}

async function cargarEstudiosRadiologo() {
    const viewMode = $("#filtroRadiologoActivo").val() || 'ME';
    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId(false)) {
        $("#radiologistStudies").html('<div class="alert alert-warning m-3">Seleccione una sede específica.</div>');
        return;
    }

    try {
        const dateQuery = typeof risWorkflowExamDateQueryParam === "function" && radiologistExamDateFilter
            ? risWorkflowExamDateQueryParam(radiologistExamDateFilter.getState())
            : "";
        const url = `${API_URL}/radiologist/studies?view=${viewMode}${dateQuery ? `&${dateQuery}` : ""}`;
        const response = await fetch(url, {
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        
        const data = await response.json();
        if (response.ok && data.success) {
            currentRadiologistData = data.data;
            renderRadiologistStudies(); 
        }
    } catch (e) {
        console.error("Error al cargar estudios:", e);
    }
}

function prefetchPacsStudyForAppointment(app) {
    if (!app?.accessionNumber || typeof prefetchPacsStudy !== "function") return;
    prefetchPacsStudy(app.accessionNumber, app.id).then((pacs) => {
        if (pacs && currentReportingChain?.id === app.id) {
            applyPacsStudyToChain(currentReportingChain, pacs);
        }
    });
}

function risHtmlRetornoTranscripcionRadiologo(app) {
    if (!app?.needsReview || !app.returnReason) return '';
    return `
        <div class="alert alert-warning py-2 px-3 mb-3 small shadow-sm d-flex align-items-center gap-2 border-warning border-2">
            <i class="bi bi-exclamation-octagon-fill fs-4"></i>
            <div><strong class="d-block">Audio Devuelto por Transcripción:</strong> ${risEscapeHtml(app.returnReason)}</div>
        </div>
    `;
}

function risHtmlAnamnesisRadiologo(app) {
    const raw = String(app?.anamnesis || '').trim();
    const vacio = !raw || /^sin anamnesis/i.test(raw);
    const texto = vacio ? 'Sin anamnesis registrada por el tecnólogo.' : raw;
    const clase = vacio ? 'alert-light border text-muted' : 'alert-info border-info';
    return `
        <div class="alert ${clase} py-2 px-3 mb-3 small shadow-sm">
            <strong class="d-block mb-1"><i class="bi bi-chat-left-text me-1"></i>Anamnesis / notas técnicas</strong>
            <div class="ris-anamnesis-radiologo-text">${risEscapeHtml(texto)}</div>
        </div>
    `;
}

function renderEncabezadoPacienteRadiologo(app) {
    const nombreCompleto = `${app.patient.name} ${app.patient.lastName} ${app.patient.secondLastName || ''}`.trim();
    return `
        ${risHtmlRetornoTranscripcionRadiologo(app)}
        ${risHtmlAnamnesisRadiologo(app)}
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-1 fw-bold text-dark"><i class="bi bi-person-bounding-box me-2 text-secondary"></i>${risEscapeHtml(nombreCompleto)}</h5>
                <span class="badge bg-dark font-monospace fs-6">${risEscapeHtml(app.accessionNumber)}</span>
                <span class="text-muted small ms-3"><b>RUT:</b> ${risEscapeHtml(app.patient.rut)}</span>
            </div>
        </div>
    `;
}

function abrirRadiologo(id) {
    const app = currentRadiologistData.find(x => String(x.id) === String(id));
    if (!app) return;

    currentReportingChain = app;
    renderRadiologistStudies();
    prefetchPacsStudyForAppointment(app);

    $("#infoPacienteRadiologo").html(renderEncabezadoPacienteRadiologo(app));

    let tabsHtml = '<div class="d-flex gap-2 flex-wrap">';
    currentReportingChain.studies.forEach((study, index) => {
        const btnClass = index === 0 ? 'bg-primary text-white' : 'btn-outline-primary';
        tabsHtml += `<button id="tab-${study.study_id}" class="study-tab-btn btn btn-sm ${btnClass} fw-bold shadow-sm" onclick="cargarEstudioEnEditor('${study.study_id}')">
            <i class="bi bi-file-medical me-1"></i>${risEscapeHtml(study.exam)}</button>`;
    });
    tabsHtml += '</div>';
    $("#listaExamenesRadiologo").html(tabsHtml);

    if (currentReportingChain.studies.length > 0) {
        cargarEstudioEnEditor(currentReportingChain.studies[0].study_id);
    }

    if (isSplitScreen) cargarHistorialSplit();
}

function renderRadiologistStudies() {
    const contenedor = $("#radiologistStudies");
    const emptyHtml = typeof risEmptyStateHtml === 'function'
        ? risEmptyStateHtml({ icon: 'bi-check2-circle', title: 'Bandeja al día', message: 'No tienes pacientes pendientes.' })
        : '<div class="p-4 text-center text-muted small">Bandeja al día.</div>';

    if (currentRadiologistData.length === 0) {
        contenedor.empty().append(emptyHtml);
        $("#badgePendientesInformar").text(0);
        return;
    }

    $("#badgePendientesInformar").text(currentRadiologistData.length);

    const renderItem = (app) => {
        const selectedClass = (currentReportingChain && currentReportingChain.id === app.id) ? 'active bg-primary text-white' : '';

        let badgeEstado = '';
        let claseBorde = 'border-start border-4 border-info';

        if (app.needsReview && app.returnReason !== '') {
            claseBorde = 'border-start border-4 border-warning bg-warning-subtle';
            badgeEstado = `
                <div class="mt-2 small text-warning-emphasis fw-bold">
                    <i class="bi bi-exclamation-triangle-fill"></i> Secretaria reporta: ${risEscapeHtml(app.returnReason)}
                </div>`;
        }

        const nombreCompleto = `${app.patient.name} ${app.patient.lastName} ${app.patient.secondLastName || ''}`.trim();
        const fechaBadge = typeof risWorkflowExamDateBadgeHtml === "function"
            ? risWorkflowExamDateBadgeHtml(app)
            : "";

        return `
            <button type="button" class="list-group-item list-group-item-action p-3 d-flex flex-column align-items-start gap-1 ${selectedClass} ${claseBorde}" onclick="abrirRadiologo('${app.id}')">
                <div class="d-flex w-100 justify-content-between align-items-center gap-1">
                    <h6 class="mb-0 fw-bold font-monospace text-truncate ris-truncate-150">${risEscapeHtml(app.accessionNumber)}</h6>
                    ${fechaBadge}
                </div>
                <strong class="m-0 text-truncate w-100 ris-font-095">${risEscapeHtml(nombreCompleto)}</strong>
                <div class="d-flex w-100 justify-content-between align-items-center mt-1 opacity-75 small">
                    <span>RUT: ${risEscapeHtml(app.patient.rut)}</span>
                    <span>Edad: ${app.patient.age}</span>
                </div>
                ${badgeEstado}
            </button>
        `;
    };

    if (typeof risRenderWorkflowInboxGrouped === "function") {
        risRenderWorkflowInboxGrouped(contenedor, currentRadiologistData, renderItem, emptyHtml);
        return;
    }

    contenedor.empty();
    currentRadiologistData.forEach((app) => contenedor.append(renderItem(app)));
}

function abrirInforme(citaId) {
    const app = currentRadiologistData.find(x => String(x.id) === String(citaId));
    if (!app) return;

    currentReportingChain = app;
    renderRadiologistStudies();
    prefetchPacsStudyForAppointment(app);

    $("#infoPacienteRadiologo").html(renderEncabezadoPacienteRadiologo(app));

    let tabsHtml = '<div class="d-flex gap-2 flex-wrap">';
    currentReportingChain.studies.forEach((study, index) => {
        const btnClass = index === 0 ? 'bg-primary text-white' : 'btn-outline-primary';
        tabsHtml += `<button id="tab-${study.study_id}" class="study-tab-btn btn btn-sm ${btnClass} fw-bold shadow-sm" onclick="cargarEstudioEnEditor('${study.study_id}')">
            <i class="bi bi-file-medical me-1"></i>${risEscapeHtml(study.exam)}</button>`;
    });
    tabsHtml += '</div>';
    $("#listaExamenesRadiologo").html(tabsHtml);

    if (currentReportingChain.studies.length > 0) {
        cargarEstudioEnEditor(currentReportingChain.studies[0].study_id);
    }

    // Si la pantalla dividida estaba abierta para otro paciente, la actualizamos
    if (isSplitScreen) cargarHistorialSplit();
}

function cargarEstudioEnEditor(studyId) {
    currentDictationMethod = "teclado";
    currentRadioStudy = currentReportingChain.studies.find(s => String(s.study_id) === String(studyId));

    $(".study-tab-btn").removeClass("bg-primary text-white").addClass("btn-outline-primary");
    $(`#tab-${studyId}`).removeClass("btn-outline-primary").addClass("bg-primary text-white");

    $("#textoInforme").val(currentRadioStudy.reportText || "").prop("disabled", false);
    lastSavedText = currentRadioStudy.reportText || ""; // Resetear comparador de autoguardado

    $("#btnPlantilla, #btnDevolver, #btnEnviarTranscripcion, #btnFirmarDirecto, #btnHistorialPaciente, #btnAdenda, #btnDragon, #btnBrowserDictation")
        .prop("disabled", false);
    $("#selRadiologistDictationMode").prop("disabled", false);

    audioBlob = null;
    $("#audioPreview").attr("src", "");
    $("#audioPreviewWrap").addClass("d-none");
    updateAudioPreviewControlsUi();
    $("#btnRecord").removeClass("d-none").prop("disabled", false).html('<i class="bi bi-mic me-1" aria-hidden="true"></i> Grabar audio');
    $("#btnStop").addClass("d-none");
    $("#recordingPulse").addClass("d-none");
    syncAiTranscribeButton();
    applyRadiologistDictationMode();

    $("#btnDragon")
        .removeClass("btn-success")
        .addClass("btn-outline-success")
        .html('<i class="bi bi-cursor-text me-1" aria-hidden="true"></i> Activar Dragon');
    if (dragonSyncInterval) {
        clearInterval(dragonSyncInterval);
        dragonSyncInterval = null;
    }
    if (typeof stopBrowserDictation === "function") {
        stopBrowserDictation(true);
    }
    if (typeof refreshBrowserDictationButtonState === "function") {
        refreshBrowserDictationButtonState();
    }
    wireBrowserDictationButton();
}

async function firmarDirecto() {
    if (!currentReportingChain) return;

    if (!(await showConfirm("¿Firmar digitalmente TODOS los informes de esta cita? El paciente podrá descargarlos inmediatamente.", { title: "Firmar informes", confirmText: "Firmar" }))) return;

        const btn = $("#btnFirmarDirecto");

        try {
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Firmando...');

            if (currentRadioStudy && $("#textoInforme").length) {
                currentRadioStudy.reportText = $("#textoInforme").val();
            }

            const paqueteInformes = (currentReportingChain.studies || [])
                .filter(s => s && s.study_id)
                .map(s => ({
                    id: String(s.study_id),
                    text: s.reportText ?? ''
                }));

            if (paqueteInformes.length === 0) {
                showToast("No hay exámenes válidos para firmar. Vuelva a abrir la cita.", "warning");
                return;
            }

            const response = await fetch(`${API_URL}/radiologist/appointments/${currentReportingChain.id}/sign`, {
                method: 'POST',
                headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders({ 'Content-Type': 'application/json' }) : {},
                body: JSON.stringify({
                    reports: paqueteInformes,
                    dictation_method: currentDictationMethod || 'teclado'
                })
            });

            const data = await response.json().catch(() => ({}));

            if (response.ok && data.success !== false) {
                showToast("✅ Informes firmados digitalmente y liberados.", "success");
                limpiarPantallaRadiologo();
                cargarEstudiosRadiologo();
            } else {
                const detalle = data.message
                    || (data.errors ? Object.values(data.errors).flat().join(' ') : '')
                    || `Error en el servidor (${response.status})`;
                showToast(`❌ Error al firmar: ${detalle}`, "danger");
            }
        } catch (e) {
            showToast("❌ Error al firmar: no se pudo contactar al servidor.", "danger");
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-pen me-1"></i> Firmar y Liberar');
        }
}

async function devolverATecnologo() {
    if (!currentReportingChain) return;

    const motivo = await showPrompt(
        "Indique el motivo médico/técnico para rechazar la imagen y devolver al Tecnólogo:",
        { title: "Devolver a Tecnólogo" }
    );
    if (!motivo) return;

    const btn = $("#btnDevolver");

    try {
        btn.prop('disabled', true).html('Devolviendo...');

        const response = await fetch(`${API_URL}/radiologist/appointments/${currentReportingChain.id}/return`, {
            method: 'POST',
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders({ 'Content-Type': 'application/json' }) : {},
            body: JSON.stringify({ reason: motivo })
        });

        if (response.ok) {
            showToast("Cadena devuelta a la lista de trabajo del T.M.", "warning");
            limpiarPantallaRadiologo();
            cargarEstudiosRadiologo();
        }
    } catch (e) {
        showToast("Error al devolver", "danger");
    } finally {
        btn.prop('disabled', false).html('<i class="bi bi-arrow-return-left me-1"></i> Devolver a T.M.');
    }
}

async function enviarATranscripcion() {
    if (!currentReportingChain || !currentRadioStudy) return;

    const texto = $("#textoInforme").val().trim();
    const tieneAudio = !!audioBlob;
    const mensajeConfirm = tieneAudio
        ? `¿Enviar el audio grabado para el examen "${currentRadioStudy.exam}" a la bandeja de transcripción?`
        : `¿Enviar el examen "${currentRadioStudy.exam}" a transcripción sin audio adjunto? El dictado puede entregarse después (p. ej. tarjeta SD).`;

    if (!(await showConfirm(mensajeConfirm, { title: "Enviar a transcripción", confirmText: "Enviar" }))) return;

        const token = localStorage.getItem('ris_token');
        const labId = localStorage.getItem('ris_lab_id');
        const btn = $("#btnEnviarTranscripcion");

        try {
            btn.prop('disabled', true).html(`<span class="spinner-border spinner-border-sm"></span> ${tieneAudio ? 'Subiendo...' : 'Enviando...'}`);

            const formData = new FormData();
            formData.append('study_id', currentRadioStudy.study_id);
            formData.append('report_text', texto);
            if (tieneAudio) {
                formData.append('audio', audioBlob, `dictado_${currentRadioStudy.study_id}.webm`);
            }

            const response = await fetch(`${API_URL}/radiologist/appointments/${currentReportingChain.id}/transcribe`, {
                method: 'POST',
                headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
                body: formData
            });

            if (response.ok) {
                showToast(
                    tieneAudio
                        ? "🎙️ Audio subido y transferido a Transcripción exitosamente."
                        : "✅ Examen enviado a Transcripción. Puede adjuntar el audio más tarde.",
                    "success"
                );
                limpiarPantallaRadiologo();
                cargarEstudiosRadiologo();
            } else {
                throw new Error("Error en el servidor");
            }
        } catch (e) {
            showToast(tieneAudio ? "❌ Error al subir el archivo de audio" : "❌ Error al enviar a transcripción", "danger");
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-headphones me-1"></i> Enviar a Transcripción');
        }
}

// === 5. AUDIO (MEDIA RECORDER) ===

function canControlAudioRecording() {
    return !!currentRadioStudy && !$("#btnRecord").prop("disabled");
}

function isRecordingActive() {
    return !!mediaRecorder
        && mediaRecorder.state === "recording"
        && !recordingPausedByUser;
}

function isRecordingPaused() {
    return recordingPausedByUser && isRecordingSessionActive();
}

function isRecordingSessionActive() {
    return !!mediaRecorder && mediaRecorder.state !== "inactive";
}

function setRecordingTracksEnabled(enabled) {
    if (!recordingMediaStream) {
        return;
    }
    recordingMediaStream.getAudioTracks().forEach((track) => {
        track.enabled = enabled;
    });
}

const AUDIO_PREVIEW_SEEK_STEP = 5;
const AUDIO_PREVIEW_SEEK_STEP_LONG = 15;

function getAudioPreviewElement() {
    return document.getElementById("audioPreview");
}

function hasAudioPreviewSource() {
    const el = getAudioPreviewElement();
    return !!(audioBlob || (el && el.src && el.src !== "" && el.src !== window.location.href));
}

function isAudioPreviewListeningMode() {
    if (isRecordingSessionActive()) {
        return false;
    }
    if (!hasAudioPreviewSource()) {
        return false;
    }
    if ($("#audioPreviewWrap").length) {
        return !$("#audioPreviewWrap").hasClass("d-none");
    }
    return !$("#audioPreview").hasClass("d-none");
}

function syncAudioPlayPauseIcon() {
    const el = getAudioPreviewElement();
    const playing = !!(el && !el.paused && !el.ended);
    $("#iconAudioPlayPause")
        .removeClass("bi-play-fill bi-pause-fill")
        .addClass(playing ? "bi-pause-fill" : "bi-play-fill");
    $("#btnAudioPlayPause").attr("title", playing ? "Pausar" : "Reproducir");
}

function updateAudioPreviewControlsUi() {
    const show = hasAudioPreviewSource() && !isRecordingSessionActive();
    if ($("#audioPreviewWrap").length) {
        $("#audioPreviewWrap").toggleClass("d-none", !show);
    } else {
        $("#audioPreview").toggleClass("d-none", !show);
    }
    $("#btnAudioRewind, #btnAudioForward, #btnAudioPlayPause").prop("disabled", !show);
    if (show) {
        syncAudioPlayPauseIcon();
    }
}

function clampAudioPreviewTime(el, seconds) {
    const max = Number.isFinite(el.duration) ? el.duration : 0;
    if (max > 0) {
        return Math.max(0, Math.min(seconds, max));
    }
    return Math.max(0, seconds);
}

function seekAudioPreview(deltaSeconds) {
    const el = getAudioPreviewElement();
    if (!el || !hasAudioPreviewSource()) {
        return false;
    }

    const applySeek = () => {
        el.currentTime = clampAudioPreviewTime(el, (el.currentTime || 0) + deltaSeconds);
    };

    if (!Number.isFinite(el.duration) && el.readyState < 1) {
        el.addEventListener("loadedmetadata", applySeek, { once: true });
        return true;
    }

    applySeek();
    return true;
}

function toggleAudioPreviewPlayback() {
    const el = getAudioPreviewElement();
    if (!el || !hasAudioPreviewSource()) {
        return false;
    }

    if (el.paused || el.ended) {
        el.play().catch(() => {});
    } else {
        el.pause();
    }
    syncAudioPlayPauseIcon();
    return true;
}

function pauseAudioPreview() {
    const el = getAudioPreviewElement();
    if (!el) {
        return false;
    }
    el.pause();
    syncAudioPlayPauseIcon();
    return true;
}

function executeSpeechMikeAudioAction(action) {
    switch (action) {
        case "seek_back":
            return seekAudioPreview(-AUDIO_PREVIEW_SEEK_STEP);
        case "seek_back_long":
            return seekAudioPreview(-AUDIO_PREVIEW_SEEK_STEP_LONG);
        case "seek_forward":
            return seekAudioPreview(AUDIO_PREVIEW_SEEK_STEP);
        case "seek_forward_long":
            return seekAudioPreview(AUDIO_PREVIEW_SEEK_STEP_LONG);
        case "toggle_play":
            return toggleAudioPreviewPlayback();
        case "pause":
            return pauseAudioPreview();
        case "restart": {
            const el = getAudioPreviewElement();
            if (!el) {
                return false;
            }
            el.currentTime = 0;
            el.pause();
            syncAudioPlayPauseIcon();
            return true;
        }
        default:
            return false;
    }
}

function updateRecordingUi(mode) {
    const recording = mode === true || mode === "recording";
    const paused = mode === "paused";

    if (recording || paused) {
        $("#btnRecord").addClass("d-none");
        $("#btnStop").removeClass("d-none");
        $("#recordingPulse").removeClass("d-none");
        $("#audioPreviewWrap").addClass("d-none");
        $("#audioPreview").addClass("d-none");

        if (paused) {
            $("#recordingPulse").html(
                '<span class="text-warning fw-bold small"><i class="bi bi-pause-circle me-1"></i> Pausado <span id="audioTimer">'
                + ($("#audioTimer").text() || "00:00")
                + "</span></span>"
            );
        } else {
            $("#recordingPulse").html(
                '<span class="spinner-grow spinner-grow-sm me-1 text-danger"></span> Grabando <span id="audioTimer">'
                + ($("#audioTimer").text() || "00:00")
                + "</span>"
            );
        }
    } else {
        $("#btnStop").addClass("d-none");
        $("#btnRecord").removeClass("d-none");
        $("#recordingPulse").addClass("d-none");
        updateAudioPreviewControlsUi();
        syncAiTranscribeButton();
    }
}

function startRecordingTimer() {
    clearInterval(recordingInterval);
    recordingInterval = setInterval(() => {
        recordingSeconds++;
        const min = String(Math.floor(recordingSeconds / 60)).padStart(2, "0");
        const sec = String(recordingSeconds % 60).padStart(2, "0");
        $("#audioTimer").text(`${min}:${sec}`);

        if (recordingSeconds >= MAX_RECORDING_SECONDS) {
            stopAudioRecording();
            if (typeof showToast === "function") {
                showToast(
                    "Límite máximo de 10 minutos alcanzado. Grabación detenida.",
                    "warning"
                );
            }
        }
    }, 1000);
}

function releaseRecordingStream() {
    if (recordingMediaStream) {
        recordingMediaStream.getTracks().forEach((track) => track.stop());
        recordingMediaStream = null;
    }
}

function pickAudioRecorderMimeType() {
    if (typeof MediaRecorder === "undefined") {
        return "";
    }
    const candidates = [
        "audio/webm;codecs=opus",
        "audio/webm",
        "audio/ogg;codecs=opus",
    ];
    for (const mimeType of candidates) {
        if (MediaRecorder.isTypeSupported(mimeType)) {
            return mimeType;
        }
    }
    return "";
}

async function buildAudioBlobFromChunks(chunks, mimeType, durationMs) {
    let blob = new Blob(chunks, { type: mimeType || "audio/webm" });
    if (durationMs > 0 && typeof ysFixWebmDuration === "function") {
        try {
            blob = await ysFixWebmDuration(blob, durationMs);
        } catch (err) {
            console.warn("buildAudioBlobFromChunks:", err);
        }
    }
    return blob;
}

async function startAudioRecording() {
    if (!canControlAudioRecording()) {
        if (typeof showToast === "function") {
            showToast("Seleccione un examen en la bandeja antes de dictar.", "warning");
        }
        return false;
    }

    if (isRecordingPaused()) {
        return resumeAudioRecording();
    }

    if (isRecordingActive()) {
        return true;
    }

    if (typeof stopBrowserDictation === "function") {
        stopBrowserDictation(true);
    }

    try {
        releaseRecordingStream();
        recordingMediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const mimeType = pickAudioRecorderMimeType();
        mediaRecorder = mimeType
            ? new MediaRecorder(recordingMediaStream, { mimeType })
            : new MediaRecorder(recordingMediaStream);
        audioChunks = [];
        recordingSeconds = 0;
        recordingPausedByUser = false;

        mediaRecorder.ondataavailable = (e) => {
            if (e.data && e.data.size > 0) {
                audioChunks.push(e.data);
            }
        };

        mediaRecorder.onstop = async () => {
            clearInterval(recordingInterval);
            recordingPausedByUser = false;
            const recorderMime = mediaRecorder.mimeType || mimeType || "audio/webm";
            const durationMs = recordingSeconds * 1000;
            audioBlob = await buildAudioBlobFromChunks(audioChunks, recorderMime, durationMs);
            $("#audioPreview")
                .attr("src", URL.createObjectURL(audioBlob));
            releaseRecordingStream();
            updateRecordingUi(false);
            updateAudioPreviewControlsUi();
            $("#btnRecord").html('<i class="bi bi-mic me-1" aria-hidden="true"></i> Regrabar (sobrescribe)');
            syncAiTranscribeButton();
        };

        // Sin timeslice: los chunks WebM con intervalo solo reproducen el tramo final (~5s).
        mediaRecorder.start();
        updateRecordingUi("recording");
        $("#audioTimer").text("00:00");
        startRecordingTimer();

        return true;
    } catch (err) {
        console.error("startAudioRecording:", err);
        releaseRecordingStream();
        if (typeof showToast === "function") {
            showToast("Error al acceder al micrófono.", "danger");
        }
        return false;
    }
}

function stopAudioRecording() {
    if (!mediaRecorder || mediaRecorder.state === "inactive") {
        updateRecordingUi(false);
        return false;
    }

    if (!isRecordingSessionActive()) {
        updateRecordingUi(false);
        return false;
    }

    if (recordingPausedByUser) {
        setRecordingTracksEnabled(true);
        recordingPausedByUser = false;
    }

    if (mediaRecorder.state === "recording") {
        try {
            mediaRecorder.requestData();
        } catch (err) {
            console.warn("requestData:", err);
        }
    }

    mediaRecorder.stop();
    return true;
}

function pauseAudioRecording() {
    if (!isRecordingActive()) {
        return false;
    }

    // Silenciar el micrófono sin pausar MediaRecorder: evita WebM truncado
    // al reanudar (MediaRecorder.pause() suele dejar solo el último segmento).
    setRecordingTracksEnabled(false);
    recordingPausedByUser = true;
    clearInterval(recordingInterval);
    updateRecordingUi("paused");
    return true;
}

function resumeAudioRecording() {
    if (!isRecordingPaused()) {
        return false;
    }

    setRecordingTracksEnabled(true);
    recordingPausedByUser = false;
    updateRecordingUi("recording");
    startRecordingTimer();
    return true;
}

function toggleAudioRecording() {
    if (isRecordingActive()) {
        return stopAudioRecording();
    }
    if (isRecordingPaused()) {
        return resumeAudioRecording();
    }
    return startAudioRecording();
}

function toggleAudioRecordingPause() {
    if (isRecordingActive()) {
        return pauseAudioRecording();
    }
    if (isRecordingPaused()) {
        return resumeAudioRecording();
    }
    return startAudioRecording();
}

function setupAudioEvents() {
    $("#btnRecord").click(async function () {
        if (isRecordingPaused()) {
            resumeAudioRecording();
            return;
        }
        if (isRecordingSessionActive()) {
            return;
        }
        await startAudioRecording();
    });

    $("#btnStop").click(function () {
        stopAudioRecording();
    });

    $("#btnAudioRewind").click(function () {
        seekAudioPreview(-AUDIO_PREVIEW_SEEK_STEP);
    });

    $("#btnAudioForward").click(function () {
        seekAudioPreview(AUDIO_PREVIEW_SEEK_STEP);
    });

    $("#btnAudioPlayPause").click(function () {
        toggleAudioPreviewPlayback();
    });

    const previewEl = getAudioPreviewElement();
    if (previewEl && !previewEl.dataset.risPreviewBound) {
        previewEl.dataset.risPreviewBound = "1";
        previewEl.addEventListener("play", syncAudioPlayPauseIcon);
        previewEl.addEventListener("pause", syncAudioPlayPauseIcon);
        previewEl.addEventListener("ended", syncAudioPlayPauseIcon);
    }
}

function limpiarPantallaRadiologo() {
    if (typeof stopBrowserDictation === "function") {
        stopBrowserDictation(true);
    }
    if (isRecordingSessionActive()) {
        stopAudioRecording();
    }
    releaseRecordingStream();

    currentReportingChain = null;
    currentRadioStudy = null;
    if (autoSaveInterval) clearInterval(autoSaveInterval);
    $("#infoPacienteRadiologo").html('<div class="text-center text-muted p-5"><i class="bi bi-file-earmark-medical fs-1 d-block mb-3"></i>Seleccione paciente.</div>');
    $("#listaExamenesRadiologo").empty();
    $("#textoInforme").val("").prop("disabled", true);
    $("#btnPlantilla, #btnDevolver, #btnEnviarTranscripcion, #btnFirmarDirecto, #btnHistorialPaciente, #btnAdenda, #btnDragon, #btnBrowserDictation").prop("disabled", true);
    $("#selRadiologistDictationMode").prop("disabled", true);
    audioBlob = null;
    $("#audioPreview").attr("src", "");
    $("#audioPreviewWrap").addClass("d-none");
    updateAudioPreviewControlsUi();
    $("#btnRecord").removeClass("d-none").prop("disabled", true).html('<i class="bi bi-mic me-1" aria-hidden="true"></i> Grabar audio');
    $("#btnStop").addClass("d-none");
    $("#recordingPulse").addClass("d-none");
    syncAiTranscribeButton();

    if (isSplitScreen) toggleHistorialPanel(); // Cerrar split screen
}

$(document).ready(function () {
    $(document).on("input", "#textoInforme", function () {
        if (currentRadioStudy) {
            currentRadioStudy.reportText = $(this).val();
        }
    });
});

function activarDragon() {
    if (!currentRadioStudy) {
        if (typeof showToast === "function") {
            showToast("Seleccione un paciente y un examen en la bandeja izquierda primero.", "warning");
        }
        return;
    }

    if (typeof stopBrowserDictation === "function") {
        stopBrowserDictation(true);
    }

    currentDictationMethod = "dragon";

    const txt = $("#textoInforme");
    txt.prop("disabled", false).focus();
    txt.addClass("border border-success border-2 shadow").removeClass("border-0");

    $("#btnDragon")
        .removeClass("btn-outline-success")
        .addClass("btn-success")
        .html('<i class="bi bi-check-circle me-1"></i> DRAGON ACTIVO');

    if (isRecordingActive()) {
        stopAudioRecording();
    }

    if (typeof showToast === "function") {
        showToast(
            "Dragon: haga clic en el cuadro del informe y encienda el micrófono de Dragon. El texto aparecerá aquí.",
            "success",
            12000
        );
    }

    if (dragonSyncInterval) {
        clearInterval(dragonSyncInterval);
    }

    dragonSyncInterval = setInterval(() => {
        if (currentDictationMethod === "dragon" && currentRadioStudy) {
            const dragonText = txt.val();
            if (currentRadioStudy.reportText !== dragonText) {
                currentRadioStudy.reportText = dragonText;
            }
        } else {
            clearInterval(dragonSyncInterval);
        }
    }, 1000);
}

function abrirVisorDicom() {
    if (!currentReportingChain) return;
    abrirVisorPACS(currentReportingChain.accessionNumber, {
        chain: currentReportingChain,
    });
}

function abrirVisorDicomSoloOhif() {
    if (!currentReportingChain) return;
    abrirVisorSoloOHIF(currentReportingChain.accessionNumber, {
        chain: currentReportingChain,
        preferPrimaryScreen: true,
        windowName: "ris_ohif_radiologo",
    });
}

/* abrirVisorPACS / abrirVisorSoloOHIF en js/core/viewer.js */

window.activarDragon = activarDragon;
window.firmarDirecto = firmarDirecto;
window.enviarATranscripcion = enviarATranscripcion;
window.isAudioPreviewListeningMode = isAudioPreviewListeningMode;
window.executeSpeechMikeAudioAction = executeSpeechMikeAudioAction;
window.seekAudioPreview = seekAudioPreview;
window.toggleAudioPreviewPlayback = toggleAudioPreviewPlayback;