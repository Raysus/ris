/* =========================================
   MÓDULO RADIÓLOGO (radiologist.js)
   ========================================= */

let currentReportingChain = null;
let currentRadioStudy = null;
let audioBlob = null;
let mediaRecorder;
let audioChunks = [];

function initRadiologist() {
    loadRISState();
    llenarFiltroRadiologos();
    renderRadiologistStudies();
    setupAudioEvents();
    setupSincronizacionRadiologo();
}

function llenarFiltroRadiologos() {
    const select = $("#filtroRadiologoActivo");
    select.find('option:not(:first)').remove();

    (window.RIS.radiologists || []).forEach(rad => {
        select.append(`<option value="${rad}">${rad}</option>`);
    });

    const savedRad = localStorage.getItem('ris_current_radiologist');
    if (savedRad) select.val(savedRad);

    select.on('change', function () {
        localStorage.setItem('ris_current_radiologist', $(this).val());
        limpiarPantallaRadiologo();
    });
}

function setupSincronizacionRadiologo() {
    window.addEventListener('storage', (e) => {
        if (e.key === 'ris_app_data') { loadRISState(); renderRadiologistStudies(); }
    });
    window.addEventListener('ris_updated', () => { renderRadiologistStudies(); });
}

function renderRadiologistStudies() {
    const lista = $("#radiologistStudies");
    if (!lista.length) return;
    lista.empty();

    const radActivo = $("#filtroRadiologoActivo").val();
    const cadenas = {};
    let pendientesCount = 0;

    (window.RIS.worklist || []).forEach(item => {
        if (item.status !== 'en_informe') return;

        if (radActivo !== 'ALL' && item.mDestinado && item.mDestinado !== radActivo) {
            return;
        }

        let hasPendingStudy = false;
        item.studies.forEach((s, idx) => {
            if (!s.studyUid) s.studyUid = `ST-${item.id}-${idx}`;
            if (!s.reportStatus || s.reportStatus === 'pendiente_radiologo') {
                s.reportStatus = 'pendiente_radiologo';
                hasPendingStudy = true;
            }
        });

        if (hasPendingStudy) {
            const acc = item.accessionNumber || item.id;
            if (!cadenas[acc]) {
                cadenas[acc] = { accessionNumber: acc, patient: item.patient, items: [], allExams: [] };
                pendientesCount++;
            }
            cadenas[acc].items.push(item);
            item.studies.forEach(s => {
                if (s.reportStatus === 'pendiente_radiologo') cadenas[acc].allExams.push(s.exam);
            });
        }
    });

    $("#badgePendientesInformar").text(pendientesCount);

    if (pendientesCount === 0) {
        lista.append('<div class="p-4 text-center text-muted"><i class="bi bi-check-circle fs-2 d-block mb-2 text-success"></i>Su bandeja está al día.</div>');
        return;
    }

    Object.values(cadenas).forEach(cadena => {
        const isActive = currentReportingChain && currentReportingChain.accessionNumber === cadena.accessionNumber ? 'active bg-primary text-white border-primary' : '';
        const textColor = isActive ? 'text-white' : 'text-primary';
        lista.append(`
            <button type="button" class="list-group-item list-group-item-action ${isActive} p-3 border-bottom" onclick="abrirInforme('${cadena.accessionNumber}')">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-truncate">${cadena.patient.lastName}, ${cadena.patient.name}</strong>
                </div>
                <div class="small fw-bold ${textColor} text-truncate"><i class="bi bi-file-medical me-1"></i>${cadena.allExams.join(" + ")}</div>
            </button>
        `);
    });
}

function abrirInforme(accessionNumber) {
    const itemsInChain = window.RIS.worklist.filter(w => w.accessionNumber === accessionNumber || w.id === accessionNumber);
    currentReportingChain = { accessionNumber, items: itemsInChain, patient: itemsInChain[0].patient, anamnesis: itemsInChain.find(i => i.anamnesis)?.anamnesis || 'Sin anamnesis.' };
    renderRadiologistStudies();

    $("#infoPacienteRadiologo").html(`
        <div class="row align-items-center">
            <div class="col-md-7">
                <h5 class="fw-bold mb-1 text-dark">${currentReportingChain.patient.name} ${currentReportingChain.patient.lastName}</h5>
                <div class="text-muted small">RUT: ${currentReportingChain.patient.rut} | Accession: ${currentReportingChain.accessionNumber}</div>
            </div>
        </div>
        <div class="bg-warning-subtle p-2 mt-2 rounded small border-start border-4 border-warning">
            <strong class="text-warning-emphasis"><i class="bi bi-chat-square-text me-1"></i>Anamnesis:</strong> ${currentReportingChain.anamnesis}
        </div>
    `);

    let tabsHtml = '<div class="d-flex gap-2 flex-wrap">';
    let firstPending = null;

    currentReportingChain.items.forEach(item => {
        item.studies.forEach(study => {
            const isPending = study.reportStatus === 'pendiente_radiologo';
            const btnClass = isPending ? 'btn-outline-primary' : 'btn-success disabled opacity-50';
            const icon = isPending ? 'bi-file-medical' : 'bi-check-circle';
            if (isPending && !firstPending) firstPending = { item, study };

            tabsHtml += `<button id="tab-${study.studyUid}" class="study-tab-btn btn btn-sm ${btnClass} fw-bold shadow-sm" ${isPending ? `onclick="cargarEstudioEnEditor('${item.id}', '${study.studyUid}')"` : ''}>
                <i class="bi ${icon} me-1"></i>${study.exam}</button>`;
        });
    });
    tabsHtml += '</div>';
    $("#listaExamenesRadiologo").html(tabsHtml);

    if (firstPending) cargarEstudioEnEditor(firstPending.item.id, firstPending.study.studyUid);
}

function cargarEstudioEnEditor(itemId, studyUid) {
    const item = currentReportingChain.items.find(i => i.id === itemId);
    const study = item.studies.find(s => s.studyUid === studyUid);
    currentRadioStudy = { item, study };

    $(".study-tab-btn").removeClass("bg-primary text-white").addClass("btn-outline-primary");
    $(`#tab-${studyUid}`).removeClass("btn-outline-primary").addClass("bg-primary text-white");

    const textoHeredado = study.informeTexto || item.informeTexto || "";
    $("#textoInforme").val(textoHeredado).prop("disabled", false);
    $("#btnPlantilla").prop("disabled", false);

    audioBlob = null;
    $("#audioPreview").addClass("d-none").attr("src", "");
    $("#btnRecord").removeClass("d-none").prop("disabled", false).html('<i class="bi bi-mic me-1"></i> DICTAR ESTUDIO');
    $("#btnStop").addClass("d-none");
    $("#btnDevolver, #btnGrabarAudio, #btnFirmarDirecto").prop("disabled", false);
}

function aplicarPlantilla(tipo) {
    if (!currentRadioStudy) {
        return showToast("Seleccione un estudio primero.", "warning");
    }

    const plantillas = {
        "normal_torax": "RADIOGRAFÍA DE TÓRAX AP Y LATERAL\n\nTécnica: Se adquieren proyecciones AP y lateral de tórax.\n\nHallazgos:\n- Silueta cardiovascular conservada.\n- Pulmones expandidos, sin condensaciones.\n- Senos costofrénicos libres.\n\nConclusión:\nRadiografía de tórax normal.",
        "normal_eco": "ECOGRAFÍA ABDOMINAL\n\nTécnica: Exploración ecográfica de abdomen.\n\nHallazgos:\n- Hígado de tamaño, forma y ecogenicidad conservada.\n- Vesícula biliar sin litiasis.\n- Riñones de características habituales.\n\nConclusión:\nEcografía abdominal normal.",
        "normal_tc_cerebro": "TC DE CEREBRO SIN CONTRASTE\n\nTécnica: Cortes axiales de encéfalo sin contraste.\n\nHallazgos:\n- Parénquima cerebral conservado.\n- Sistema ventricular normal.\n\nConclusión:\nEstudio tomográfico de cerebro dentro de límites normales."
    };

    if (!plantillas[tipo]) return;

    const textarea = $("#textoInforme");
    const textoActual = textarea.val();
    const separador = textoActual.trim() !== "" ? "\n\n---\n\n" : "";

    textarea.val(textoActual + separador + plantillas[tipo]);
    currentRadioStudy.study.informeTexto = textarea.val();
    showToast("Plantilla insertada con éxito.", "info");
}

function firmarDirecto() {
    if (!currentReportingChain) return;
    const texto = $("#textoInforme").val().trim();
    if (!texto) return showToast("El informe no puede estar vacío.", "warning");

    if (confirm("¿Firmar digitalmente este informe integral? Todos los estudios asociados quedarán listos para entrega.")) {

        const fechaFirma = new Date().toLocaleString();
        const radActivo = $("#filtroRadiologoActivo").val();
        const nombreFirma = radActivo !== 'ALL' ? radActivo : "Dr. Radiólogo Jefe";

        currentReportingChain.items.forEach(item => {
            const wlIdx = window.RIS.worklist.findIndex(w => w.id === item.id);
            if (wlIdx > -1) {
                window.RIS.worklist[wlIdx].informeTexto = texto;
                window.RIS.worklist[wlIdx].status = 'entregable';
                window.RIS.worklist[wlIdx].firmado = true;
                window.RIS.worklist[wlIdx].fechaFirma = fechaFirma;
                window.RIS.worklist[wlIdx].medicoFirmante = nombreFirma;

                window.RIS.worklist[wlIdx].studies.forEach(s => {
                    s.reportStatus = 'entregable';
                    s.informeTexto = texto;
                    s.firmado = true;
                    s.fechaFirma = fechaFirma;
                    s.medicoFirmante = nombreFirma;
                });
            }
            const agendaIdx = window.RIS.agenda.findIndex(a => a.id === item.id);
            if (agendaIdx > -1) window.RIS.agenda[agendaIdx].status = 'entregable';
        });

        saveRISState();
        limpiarPantallaRadiologo();
        showToast("✅ Informe Global firmado digitalmente por " + nombreFirma, "success");
    }
}

function enviarATranscripcion() {
    if (!currentReportingChain) return;

    const texto = $("#textoInforme").val().trim();
    if (!audioBlob && !texto) {
        return showToast("Debe grabar un audio o escribir un borrador.", "warning");
    }

    if (confirm("¿Enviar esta cadena de estudios a la bandeja de Transcripción?")) {

        currentReportingChain.items.forEach(item => {
            const wlIdx = window.RIS.worklist.findIndex(w => w.id === item.id);
            if (wlIdx > -1) {
                window.RIS.worklist[wlIdx].informeTexto = texto;
                window.RIS.worklist[wlIdx].hasAudio = audioBlob !== null;
                window.RIS.worklist[wlIdx].status = 'en_transcripcion';
                window.RIS.worklist[wlIdx].firmado = false;

                window.RIS.worklist[wlIdx].studies.forEach(s => {
                    s.reportStatus = 'pendiente_transcripcion';
                    s.informeTexto = texto;
                    s.hasAudio = audioBlob !== null;
                });
            }
            const agendaIdx = window.RIS.agenda.findIndex(a => a.id === item.id);
            if (agendaIdx > -1) window.RIS.agenda[agendaIdx].status = 'en_transcripcion';
        });

        saveRISState();
        limpiarPantallaRadiologo();
        showToast("🎙️ Estudio transferido a Transcripción.", "info");
    }
}

function devolverATecnologo() {
    if (!currentReportingChain) return;

    const motivo = prompt("Indique la justificación clínica para rechazar y devolver al Tecnólogo:");
    if (motivo) {
        currentReportingChain.items.forEach(item => {
            const wlIdx = window.RIS.worklist.findIndex(w => w.id === item.id);
            if (wlIdx > -1) {
                window.RIS.worklist[wlIdx].status = 'waiting';
                window.RIS.worklist[wlIdx].notasDevolucion = motivo;

                window.RIS.worklist[wlIdx].studies.forEach(s => {
                    s.reportStatus = 'pendiente_radiologo';
                });
            }
            const agendaIdx = window.RIS.agenda.findIndex(a => a.id === item.id);
            if (agendaIdx > -1) window.RIS.agenda[agendaIdx].status = 'waiting';
        });

        saveRISState();
        limpiarPantallaRadiologo();
        showToast("Cadena devuelta a la sala de espera técnica.", "danger");
    }
}

function setupAudioEvents() {
    $("#btnRecord").click(async function () {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(stream);
            audioChunks = [];
            mediaRecorder.ondataavailable = e => audioChunks.push(e.data);
            mediaRecorder.onstop = () => {
                audioBlob = new Blob(audioChunks, { type: "audio/webm" });
                $("#audioPreview").removeClass("d-none").attr("src", URL.createObjectURL(audioBlob));
                stream.getTracks().forEach(track => track.stop());
            };
            mediaRecorder.start();
            $(this).addClass("d-none"); $("#btnStop").removeClass("d-none"); $("#recordingPulse").removeClass("d-none");
        } catch (err) { showToast("Error de micrófono.", "danger"); }
    });
    $("#btnStop").click(function () {
        if (mediaRecorder && mediaRecorder.state !== "inactive") mediaRecorder.stop();
        $(this).addClass("d-none"); $("#btnRecord").removeClass("d-none").html('<i class="bi bi-mic me-1"></i> REGRABAR'); $("#recordingPulse").addClass("d-none");
    });
}

function limpiarPantallaRadiologo() {
    currentReportingChain = null;
    currentRadioStudy = null;
    $("#infoPacienteRadiologo").html('<div class="text-center text-muted p-5"><i class="bi bi-file-earmark-medical fs-1 d-block mb-3"></i>Seleccione paciente.</div>');
    $("#listaExamenesRadiologo").empty();
    $("#textoInforme").val("").prop("disabled", true);
    $("#btnPlantilla").prop("disabled", true);
    $("#btnDevolver, #btnGrabarAudio, #btnFirmarDirecto").prop("disabled", true);

    audioBlob = null;
    $("#audioPreview").addClass("d-none").attr("src", "");
    $("#btnRecord").removeClass("d-none").prop("disabled", true).html('<i class="bi bi-mic me-1"></i> INICIAR DICTADO');
    $("#btnStop").addClass("d-none");
    $("#recordingPulse").addClass("d-none");

    renderRadiologistStudies();
}

$(document).ready(function () {
    $(document).on("input", "#textoInforme", function () {
        if (currentRadioStudy) {
            currentRadioStudy.study.informeTexto = $(this).val();
        }
    });
});