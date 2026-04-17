/* =========================================
   MÓDULO RADIÓLOGO (radiologist.js)
   ========================================= */

let currentReportingChain = null;
let currentRadioStudy = null;
let currentRadiologistData = [];
let audioBlob = null;
let mediaRecorder;
let audioChunks = [];
let currentDictationMethod = 'teclado';

function initRadiologist() {
    cargarEstudiosRadiologo();
    setupAudioEvents();

    setInterval(cargarEstudiosRadiologo, 30000);
}

async function cargarEstudiosRadiologo() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const lista = $("#radiologistStudies");

    try {
        const response = await fetch(`${API_URL}/radiologist/studies`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();

        if (response.ok && data.success) {
            currentRadiologistData = data.data;
            renderRadiologistStudies();
        }
    } catch (e) {
        console.error("Error al cargar bandeja del radiólogo:", e);
        lista.html('<div class="p-4 text-center text-danger"><i class="bi bi-wifi-off fs-2 d-block mb-2"></i>Error de conexión</div>');
    }
}

function renderRadiologistStudies() {
    const lista = $("#radiologistStudies");
    lista.empty();

    $("#badgePendientesInformar").text(currentRadiologistData.length);

    if (currentRadiologistData.length === 0) {
        lista.append('<div class="p-4 text-center text-muted"><i class="bi bi-check-circle fs-2 d-block mb-2 text-success"></i>Su bandeja está al día.</div>');
        return;
    }

    currentRadiologistData.forEach(cadena => {
        const isActive = currentReportingChain && currentReportingChain.id === cadena.id ? 'active bg-primary text-white border-primary' : '';
        const textColor = isActive ? 'text-white' : 'text-primary';

        const nombresExamenes = cadena.studies.map(s => s.exam).join(" + ");

        lista.append(`
            <button type="button" class="list-group-item list-group-item-action ${isActive} p-3 border-bottom" onclick="abrirInforme('${cadena.id}')">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-truncate">${cadena.patient.lastName}, ${cadena.patient.name}</strong>
                </div>
                <div class="small fw-bold ${textColor} text-truncate"><i class="bi bi-file-medical me-1"></i>${nombresExamenes}</div>
            </button>
        `);
    });
}

function abrirInforme(citaId) {
    currentReportingChain = currentRadiologistData.find(c => String(c.id) === String(citaId));
    if (!currentReportingChain) return;

    renderRadiologistStudies();

    $("#infoPacienteRadiologo").html(`
        <div class="row align-items-center">
            <div class="col-md-7">
                <h5 class="fw-bold mb-1 text-dark">${currentReportingChain.patient.name} ${currentReportingChain.patient.lastName}</h5>
                <div class="text-muted small">RUT: ${currentReportingChain.patient.rut} | Accession: ${currentReportingChain.accessionNumber}</div>
            </div>
        </div>
        <div class="bg-warning-subtle p-2 mt-2 rounded small border-start border-4 border-warning">
            <strong class="text-warning-emphasis"><i class="bi bi-chat-square-text me-1"></i>Anamnesis (T.M):</strong> ${currentReportingChain.anamnesis}
        </div>
    `);

    let tabsHtml = '<div class="d-flex gap-2 flex-wrap">';
    currentReportingChain.studies.forEach((study, index) => {
        const btnClass = index === 0 ? 'bg-primary text-white' : 'btn-outline-primary';
        tabsHtml += `<button id="tab-${study.study_id}" class="study-tab-btn btn btn-sm ${btnClass} fw-bold shadow-sm" onclick="cargarEstudioEnEditor('${study.study_id}')">
            <i class="bi bi-file-medical me-1"></i>${study.exam}</button>`;
    });
    tabsHtml += '</div>';
    $("#listaExamenesRadiologo").html(tabsHtml);

    if (currentReportingChain.studies.length > 0) {
        cargarEstudioEnEditor(currentReportingChain.studies[0].study_id);
    }
}

function cargarEstudioEnEditor(studyId) {
    currentDictationMethod = 'teclado';
    currentRadioStudy = currentReportingChain.studies.find(s => String(s.study_id) === String(studyId));

    $(".study-tab-btn").removeClass("bg-primary text-white").addClass("btn-outline-primary");
    $(`#tab-${studyId}`).removeClass("btn-outline-primary").addClass("bg-primary text-white");

    $("#textoInforme").val(currentRadioStudy.reportText || "").prop("disabled", false);
    $("#btnPlantilla, #btnDevolver, #btnGrabarAudio, #btnFirmarDirecto, #btnHistorialPaciente").prop("disabled", false);
    audioBlob = null;
    $("#audioPreview").addClass("d-none").attr("src", "");
    $("#btnRecord").removeClass("d-none").prop("disabled", false).html('<i class="bi bi-mic me-1"></i> INICIAR DICTADO');
    $("#btnStop").addClass("d-none");
    $("#recordingPulse").addClass("d-none");
}

function aplicarPlantilla(tipo) {
    if (!currentRadioStudy) return showToast("Seleccione un estudio primero.", "warning");

    const plantillas = {
        "normal_torax": "RADIOGRAFÍA DE TÓRAX AP Y LATERAL\n\nTécnica: Se adquieren proyecciones AP y lateral de tórax.\n\nHallazgos:\n- Silueta cardiovascular conservada.\n- Pulmones expandidos, sin condensaciones.\n- Senos costofrénicos libres.\n\nConclusión:\nRadiografía de tórax normal.",
        "normal_eco": "ECOGRAFÍA ABDOMINAL\n\nTécnica: Exploración ecográfica de abdomen.\n\nHallazgos:\n- Hígado de tamaño, forma y ecogenicidad conservada.\n- Vesícula biliar sin litiasis.\n- Riñones de características habituales.\n\nConclusión:\nEcografía abdominal normal.",
        "normal_tc_cerebro": "TC DE CEREBRO SIN CONTRASTE\n\nTécnica: Cortes axiales de encéfalo sin contraste.\n\nHallazgos:\n- Parénquima cerebral conservado.\n- Sistema ventricular normal.\n\nConclusión:\nEstudio tomográfico de cerebro dentro de límites normales."
    };

    if (!plantillas[tipo]) return;

    const textarea = $("#textoInforme");
    const separador = textarea.val().trim() !== "" ? "\n\n---\n\n" : "";
    textarea.val(textarea.val() + separador + plantillas[tipo]);

    currentRadioStudy.reportText = textarea.val();
    showToast("Plantilla insertada.", "info");
}

async function firmarDirecto() {
    if (!currentReportingChain) return;

    if (confirm("¿Firmar digitalmente TODOS los informes de esta cita? El paciente podrá descargarlos inmediatamente.")) {
        const token = localStorage.getItem('ris_token');
        const labId = localStorage.getItem('ris_lab_id');
        const btn = $("#btnFirmarDirecto");

        try {
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Firmando...');

            const paqueteInformes = currentReportingChain.studies.map(s => ({
                id: s.study_id,
                text: s.reportText
            }));

            const response = await fetch(`${API_URL}/radiologist/appointments/${currentReportingChain.id}/sign`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
                body: JSON.stringify({
                    reports: paqueteInformes,
                    dictation_method: currentDictationMethod
                })
            });

            if (response.ok) {
                showToast("✅ Informes firmados digitalmente y liberados.", "success");
                limpiarPantallaRadiologo();
                cargarEstudiosRadiologo();
            } else {
                throw new Error("Error en el servidor");
            }
        } catch (e) {
            showToast("❌ Error al firmar", "danger");
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-pen me-1"></i> Firmar y Liberar');
        }
    }
}

async function devolverATecnologo() {
    if (!currentReportingChain) return;

    const motivo = prompt("Indique el motivo médico/técnico para rechazar la imagen y devolver al Tecnólogo:");
    if (!motivo) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const btn = $("#btnDevolver");

    try {
        btn.prop('disabled', true).html('Devolviendo...');

        const response = await fetch(`${API_URL}/radiologist/appointments/${currentReportingChain.id}/return`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
            body: JSON.stringify({ reason: motivo })
        });

        if (response.ok) {
            showToast("⚠️ Cadena devuelta a la Worklist del T.M.", "warning");
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

    if (!audioBlob) {
        return showToast("⚠️ Operación cancelada: Debe grabar un audio antes de enviar a transcripción.", "warning");
    }

    const texto = $("#textoInforme").val().trim();

    if (confirm(`¿Enviar el audio grabado para el examen "${currentRadioStudy.exam}" a la bandeja de la secretaria?`)) {
        const token = localStorage.getItem('ris_token');
        const labId = localStorage.getItem('ris_lab_id');
        const btn = $("#btnGrabarAudio");

        try {
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Subiendo audio...');

            const formData = new FormData();
            formData.append('study_id', currentRadioStudy.study_id);
            formData.append('report_text', texto);

            formData.append('audio', audioBlob, `dictado_${currentRadioStudy.study_id}.webm`);

            const response = await fetch(`${API_URL}/radiologist/appointments/${currentReportingChain.id}/transcribe`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'X-Lab-Id': labId
                },
                body: formData
            });

            if (response.ok) {
                showToast("🎙️ Audio subido y transferido a Transcripción exitosamente.", "success");
                limpiarPantallaRadiologo();
                cargarEstudiosRadiologo();
            } else {
                const errorData = await response.json();
                throw new Error(errorData.message || "Error en el servidor");
            }
        } catch (e) {
            console.error(e);
            showToast("❌ Error al subir el archivo de audio", "danger");
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-headphones me-1"></i> Enviar a Transcripción');
        }
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
    $("#btnPlantilla, #btnDevolver, #btnGrabarAudio, #btnFirmarDirecto, #btnHistorialPaciente").prop("disabled", true);
    audioBlob = null;
    $("#audioPreview").addClass("d-none").attr("src", "");
    $("#btnRecord").removeClass("d-none").prop("disabled", true).html('<i class="bi bi-mic me-1"></i> INICIAR DICTADO');
    $("#btnStop").addClass("d-none");
    $("#recordingPulse").addClass("d-none");
}

$(document).ready(function () {
    $(document).on("input", "#textoInforme", function () {
        if (currentRadioStudy) {
            currentRadioStudy.reportText = $(this).val();
        }
    });
});

function activarDragon() {
    if (!currentRadioStudy) return;

    currentDictationMethod = 'dragon';

    const txt = $("#textoInforme");
    txt.prop("disabled", false).focus();
    txt.addClass("border border-success border-2 shadow").removeClass("border-0");

    if (mediaRecorder && mediaRecorder.state !== "inactive") {
        $("#btnStop").click();
    }
    if (typeof showToast === 'function') showToast("🟢 Dragon Medical listo.", "success");
}

function abrirHistorialSeleccionado() {
    if (!currentReportingChain) return;
    const p = currentReportingChain.patient;
    verHistorialPaciente(p.rut, `${p.name} ${p.lastName}`);
}

async function verHistorialPaciente(rut, nombreCompleto) {
    $("#historialNombrePaciente").text(nombreCompleto);
    const contenedor = $("#contenedorHistorial");
    contenedor.html('<div class="text-center p-4"><span class="spinner-border text-primary"></span> Buscando informes previos...</div>');

    $("#modalHistorialPaciente").modal('show');

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/patients/${rut}/history`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();

        if (response.ok && data.success) {
            contenedor.empty();
            if (data.data.length === 0) {
                return contenedor.html('<div class="alert alert-info shadow-sm"><i class="bi bi-info-circle me-2"></i>No existen exámenes anteriores firmados para este paciente.</div>');
            }

            data.data.forEach(informe => {
                contenedor.append(`
                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center pb-0 border-0">
                            <h6 class="fw-bold text-dark mb-0">${informe.exam_name}</h6>
                            <span class="badge bg-secondary">${new Date(informe.date).toLocaleDateString('es-CL')}</span>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-2"><b>Radiólogo:</b> Dr(a). ${informe.doctor_name}</p>
                            <div class="p-3 bg-light rounded text-dark" style="font-size: 0.9rem; white-space: pre-wrap; border-left: 3px solid #0d6efd;">${informe.report_text}</div>
                        </div>
                    </div>
                `);
            });
        }
    } catch (error) {
        contenedor.html('<div class="text-danger p-3"><i class="bi bi-wifi-off me-2"></i>Error de conexión.</div>');
    }
}
function abrirVisorDicom() {
    if (!currentReportingChain) return;
    abrirVisorPACS(currentReportingChain.accessionNumber);
}

async function abrirVisorPACS(accessionNumber) {
    const pacsConfig = {
        accession_number: accessionNumber,
        pacs_ip: "170.246.172.83",
        pacs_port: 4242,
        pacs_aet: "HealthTICloud"
    };

    try {
        const response = await fetch(`http://localhost:8181/open-dicom`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(pacsConfig)
        });
    } catch (error) {
        const urlWeb = `http://170.246.172.83:8042/osimis-viewer/app/index.html?accession=${accessionNumber}`;
        window.open(urlWeb, '_blank');
    }
}