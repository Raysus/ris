/* =========================================
   MÓDULO DE TRANSCRIPCIÓN (transcription.js)
   ========================================= */

let currentTranscriptionData = [];
let currentTranscriptionChain = null;
let currentTransStudy = null;
let colorInformeGlobal = "#333333";

function initTranscription() {
    cargarAjustesVisuales();
    cargarListaTranscripcion();
    cargarPlantillasTranscripcion();
    setupKeyboardShortcuts();
    setInterval(cargarListaTranscripcion, 30000);
}

async function cargarAjustesVisuales() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    try {
        const response = await fetch(`${API_URL}/settings`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();

        if (response.ok && data.success && data.data && data.data.settings && data.data.settings.colorInforme) {
            colorInformeGlobal = data.data.settings.colorInforme;
            $("#textoTranscripcion").css("color", colorInformeGlobal);
        }
    } catch (e) { console.error("Error cargando ajustes visuales:", e); }
}

async function cargarListaTranscripcion() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const lista = $("#listaTranscripcion");

    try {
        const response = await fetch(`${API_URL}/transcription/studies`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();

        if (response.ok && data.success) {
            currentTranscriptionData = data.data;
            renderListaTranscripcion();
        }
    } catch (e) {
        console.error("Error cargando transcripciones:", e);
        lista.html('<div class="p-4 text-center text-danger"><i class="bi bi-wifi-off fs-2 d-block mb-2"></i>Error de conexión</div>');
    }
}

function renderListaTranscripcion() {
    const lista = $("#listaTranscripcion");
    lista.empty();

    $("#contadorAudios").text(currentTranscriptionData.length);

    if (currentTranscriptionData.length === 0) {
        lista.append('<div class="text-center text-muted small mt-4"><i class="bi bi-check2-circle fs-3 d-block mb-2 text-success"></i>Bandeja al día. No hay dictados pendientes.</div>');
        return;
    }

    currentTranscriptionData.forEach(cadena => {
        const isActive = currentTranscriptionChain && currentTranscriptionChain.id === cadena.id ? 'active bg-primary text-white border-primary' : '';
        const textColor = isActive ? 'text-white' : 'text-primary';
        const mutedColor = isActive ? 'text-white-50' : 'text-muted';

        const examsStr = cadena.studies.map(s => s.exam).join(" + ");
        const radAsignado = cadena.destinationDoctorId ? `Médico ID: ${cadena.destinationDoctorId}` : 'Radiólogo Asignado';

        const alertIcon = cadena.needsReview
            ? '<i class="bi bi-exclamation-triangle-fill text-danger me-1" title="Devuelto con correcciones"></i>'
            : '';

        lista.append(`
            <button type="button" class="list-group-item list-group-item-action ${isActive} p-3 border-bottom" onclick="abrirTranscripcion('${cadena.id}')">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-truncate">${alertIcon} ${cadena.patient.lastName} ${cadena.patient.secondLastName || ''}, ${cadena.patient.name}</strong>
                    ${cadena.hasAudio ? '<i class="bi bi-mic-fill text-danger fs-5" title="Contiene audio dictado"></i>' : '<i class="bi bi-pencil-square text-warning fs-5" title="Solo borrador de texto"></i>'}
                </div>
                <div class="small ${mutedColor} mb-2">A.N.: ${cadena.accessionNumber} | <span class="fw-bold">${radAsignado}</span></div>
                <div class="small fw-bold ${textColor} text-truncate"><i class="bi bi-file-medical me-1"></i>${examsStr}</div>
            </button>
        `);
    });
}

function abrirTranscripcion(citaId) {
    currentTranscriptionChain = currentTranscriptionData.find(c => String(c.id) === String(citaId));
    if (!currentTranscriptionChain) return;

    renderListaTranscripcion();

    const radAsignado = currentTranscriptionChain.destinationDoctorId ? `Médico ID: ${currentTranscriptionChain.destinationDoctorId}` : 'Radiólogo Asignado';

    let alertaHtml = '';
    if (currentTranscriptionChain.needsReview && currentTranscriptionChain.returnReason) {
        alertaHtml = `
            <div class="alert border-danger bg-danger-subtle shadow-sm mt-3 mb-0 d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill text-danger fs-2 me-3"></i>
                <div>
                    <h6 class="fw-bold text-danger mb-1">CORRECCIÓN SOLICITADA POR EL MÉDICO</h6>
                    <p class="mb-0 text-dark small"><strong>Instrucciones:</strong> ${currentTranscriptionChain.returnReason}</p>
                </div>
            </div>
        `;
    }

    $("#infoPacienteTranscripcion").html(`
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5 class="fw-bold mb-1 text-dark">${currentTranscriptionChain.patient.name} ${currentTranscriptionChain.patient.lastName} ${currentTranscriptionChain.patient.secondLastName || ''}</h5>
                <div class="text-muted small">
                    RUT: ${currentTranscriptionChain.patient.rut} | A.N.: ${currentTranscriptionChain.accessionNumber} | 
                    <span class="text-primary fw-bold"><i class="bi bi-person-badge me-1"></i>${radAsignado}</span>
                </div>
            </div>
        </div>
        ${alertaHtml} `);

    let tabsHtml = '';
    currentTranscriptionChain.studies.forEach((study, index) => {
        tabsHtml += `<button id="tab-trans-${study.study_id}" class="study-tab-btn btn btn-sm btn-outline-primary fw-bold shadow-sm" onclick="cargarEstudioTranscripcion('${study.study_id}')">
            <i class="bi bi-file-medical me-1"></i>${study.exam}</button>`;
    });
    $("#listaExamenesTranscripcion").html(tabsHtml);

    if (currentTranscriptionChain.studies.length > 0) {
        cargarEstudioTranscripcion(currentTranscriptionChain.studies[0].study_id);
    }

    $("#btnEnviarValidacion").prop("disabled", false);
    $("#btnPlantillaTranscripcion").prop("disabled", false);
}

function cargarEstudioTranscripcion(studyId) {
    currentTransStudy = currentTranscriptionChain.studies.find(s => String(s.study_id) === String(studyId));

    $(".study-tab-btn").removeClass("bg-primary text-white").addClass("btn-outline-primary");
    $(`#tab-trans-${studyId}`).removeClass("btn-outline-primary").addClass("bg-primary text-white");

    $("#textoTranscripcion").val(currentTransStudy.reportText || "").prop("disabled", false);

    if (currentTransStudy.audioUrl) {
        $("#audioStatus").html('<span class="text-success fw-bold"><i class="bi bi-play-circle-fill me-1"></i>Audio de dictado disponible</span>');
        $("#btnPlayPause, .btn-group .btn, .dropdown-toggle").prop("disabled", false);
        $("#audioProgress").prop("disabled", false);

        initAudioPlayer(currentTransStudy.audioUrl);
    } else {
        $("#audioStatus").html('<span class="text-warning fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i>Sin audio adjunto (Solo borrador escrito)</span>');
        $("#btnPlayPause, .btn-group .btn, .dropdown-toggle").prop("disabled", true);
        $("#audioProgress").prop("disabled", true).val(0);
        $("#timeCurrent, #timeTotal").text("0:00");

        initAudioPlayer('');
    }

    iniciarAutoguardadoTrans();
    lastSavedTextTrans = currentTransStudy.reportText;
}

async function enviarAValidacion() {
    if (!currentTranscriptionChain) return;

    if (confirm("¿Enviar TODOS los informes de esta cita a Validación? El Radiólogo deberá revisarlos y firmarlos.")) {
        const token = localStorage.getItem('ris_token');
        const labId = localStorage.getItem('ris_lab_id');
        const btn = $("#btnEnviarValidacion");

        try {
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Enviando...');

            const paqueteInformes = currentTranscriptionChain.studies.map(s => ({
                id: s.study_id,
                text: s.reportText
            }));

            const response = await fetch(`${API_URL}/transcription/appointments/${currentTranscriptionChain.id}/complete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
                body: JSON.stringify({ reports: paqueteInformes })
            });

            if (response.ok) {
                showToast("✅ Informes enviados a la Bandeja de Firma del Radiólogo.", "success");
                limpiarPantallaTranscripcion();
                cargarListaTranscripcion();
            } else {
                throw new Error("Error en servidor");
            }
        } catch (e) {
            console.error(e);
            showToast("❌ Error al enviar la transcripción", "danger");
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> ENVIAR A FIRMA DEL RADIÓLOGO');
        }
    }
}

function limpiarPantallaTranscripcion() {
    currentTranscriptionChain = null;
    currentTransStudy = null;
    $("#infoPacienteTranscripcion").html('<div class="text-center text-muted p-4"><i class="bi bi-headphones fs-1 d-block mb-3"></i>Seleccione paciente de la lista izquierda para transcribir.</div>');
    $("#textoTranscripcion").val("").prop("disabled", true);
    $("#btnEnviarValidacion, #btnPlantillaTranscripcion").prop("disabled", true);

    if (typeof initAudioPlayer === 'function') initAudioPlayer('');
    $("#btnPlayPause, .btn-group .btn, .dropdown-toggle").prop("disabled", true);
    $("#audioProgress").prop("disabled", true).val(0);
    if (autoSaveIntervalTrans) clearInterval(autoSaveIntervalTrans);
    $("#btnPlantillaTrans, #btnDevolverAudio").prop("disabled", true);
    renderListaTranscripcion();
}

function aplicarPlantillaTranscripcion(tipo) {
    if (!currentTranscriptionChain || !currentTransStudy) {
        return showToast("Seleccione un paciente y estudio de la lista primero.", "warning");
    }

    const plantillas = {
        "normal_torax": "RADIOGRAFÍA DE TÓRAX AP Y LATERAL\n\nTécnica: Se adquieren proyecciones AP y lateral de tórax.\n\nHallazgos:\n- Silueta cardiovascular de tamaño y morfología conservada.\n- Pulmones expandidos, sin condensaciones.\n- Senos costofrénicos libres.\n\nConclusión:\nRadiografía de tórax dentro de límites normales.",
        "normal_eco": "ECOGRAFÍA ABDOMINAL\n\nTécnica: Exploración ecográfica de abdomen superior e inferior.\n\nHallazgos:\n- Hígado de tamaño, forma y ecogenicidad conservada.\n- Vesícula biliar de paredes finas, sin litiasis.\n- Riñones de características ecográficas habituales.\n\nConclusión:\nEcografía abdominal sin hallazgos patológicos."
    };

    if (!plantillas[tipo]) return;

    const textarea = $("#textoTranscripcion");
    const textoActual = textarea.val();
    const separador = textoActual.trim() !== "" ? "\n\n---\n\n" : "";

    textarea.val(textoActual + separador + plantillas[tipo]);
    currentTransStudy.reportText = textarea.val();
    showToast("Plantilla insertada con éxito.", "info");
}

let audioPlayer;
function initAudioPlayer(url) {
    audioPlayer = document.getElementById('audioDictado');
    const p = document.getElementById('audioProgress'), tc = document.getElementById('timeCurrent'), tt = document.getElementById('timeTotal');
    if (url) audioPlayer.src = url;
    p.value = 0;
    audioPlayer.addEventListener('loadedmetadata', () => { p.max = audioPlayer.duration; tt.textContent = formatTime(audioPlayer.duration); });
    audioPlayer.addEventListener('timeupdate', () => { p.value = audioPlayer.currentTime; tc.textContent = formatTime(audioPlayer.currentTime); });
}
function togglePlayPause() {
    if (!audioPlayer || !audioPlayer.src) return;
    const ic = document.getElementById('iconPlayPause');
    if (audioPlayer.paused) { audioPlayer.play(); ic.className = "bi bi-pause-fill fs-3"; ic.style.marginLeft = "0"; }
    else { audioPlayer.pause(); ic.className = "bi bi-play-fill fs-3"; ic.style.marginLeft = "4px"; }
}
function skipAudio(s) { if (audioPlayer) { let nt = audioPlayer.currentTime + s; audioPlayer.currentTime = Math.max(0, Math.min(nt, audioPlayer.duration)); } }
function seekAudio() { if (audioPlayer) audioPlayer.currentTime = document.getElementById('audioProgress').value; }
function setAudioSpeed(s) { if (audioPlayer) { audioPlayer.playbackRate = s; document.getElementById('speedIndicator').textContent = s.toFixed(1) + 'x'; } }
function formatTime(s) { if (isNaN(s)) return "0:00"; const m = Math.floor(s / 60), sc = Math.floor(s % 60); return `${m}:${sc < 10 ? '0' : ''}${sc}`; }

$(document).ready(function () {
    initTranscription();

    $(document).on("input", "#textoTranscripcion", function () {
        if (currentTransStudy) {
            currentTransStudy.reportText = $(this).val();
        }
    });
});

// === 1. ATAJOS DE TECLADO ===
function setupKeyboardShortcuts() {
    document.addEventListener('keydown', function (e) {
        if (!currentTransStudy || !audioPlayer) return;

        // F4: Play / Pausa
        if (e.key === 'F4') {
            e.preventDefault(); // Evita funciones por defecto del navegador
            togglePlayPause();
        }
        // F2: Retroceder 5 segundos
        if (e.key === 'F2') {
            e.preventDefault();
            skipAudio(-5);
        }
    });
}

// === 2. PLANTILLAS DINÁMICAS ===
async function cargarPlantillasTranscripcion() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    try {
        const response = await fetch(`${API_URL}/templates`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();
        if (response.ok && data.success) {
            allTemplatesTrans = data.data;
            const dropdown = $("#dropdownPlantillasTrans");
            dropdown.empty();
            if (allTemplatesTrans.length === 0) return dropdown.append('<li><span class="dropdown-item text-muted">No hay plantillas creadas</span></li>');

            allTemplatesTrans.forEach(tpl => {
                dropdown.append(`<li><a class="dropdown-item" href="javascript:void(0);" onclick="aplicarPlantillaIdTrans('${tpl.id}')"><b>[${tpl.group_code}]</b> ${tpl.title}</a></li>`);
            });
        }
    } catch (e) { console.error("Error cargando plantillas:", e); }
}

function aplicarPlantillaIdTrans(id) {
    if (!currentTransStudy) return;
    const tpl = allTemplatesTrans.find(t => String(t.id) === String(id));
    if (!tpl) return;

    const textarea = $("#textoTranscripcion");
    const separador = textarea.val().trim() !== "" ? "\n\n---\n\n" : "";
    textarea.val(textarea.val() + separador + tpl.content);
    currentTransStudy.reportText = textarea.val();
    ejecutarAutoguardadoTrans();
}

// === 3. AUTOGUARDADO ===
function iniciarAutoguardadoTrans() {
    if (autoSaveIntervalTrans) clearInterval(autoSaveIntervalTrans);
    autoSaveIntervalTrans = setInterval(ejecutarAutoguardadoTrans, 20000); // Cada 20s
}

async function ejecutarAutoguardadoTrans() {
    if (!currentTranscriptionChain || !currentTransStudy) return;

    const textoActual = $("#textoTranscripcion").val();
    if (textoActual === lastSavedTextTrans) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    $("#autoSaveIndTrans").html('<span class="spinner-border spinner-border-sm text-primary"></span>').fadeIn();

    try {
        const paqueteInformes = currentTranscriptionChain.studies.map(s => ({
            id: s.study_id,
            text: s.reportText
        }));

        const response = await fetch(`${API_URL}/transcription/appointments/${currentTranscriptionChain.id}/draft`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
            body: JSON.stringify({ reports: paqueteInformes })
        });

        if (response.ok) {
            lastSavedTextTrans = textoActual;
            $("#autoSaveIndTrans").html('<i class="bi bi-cloud-check-fill text-success me-1"></i>Guardado');
            setTimeout(() => $("#autoSaveIndTrans").fadeOut(), 3000);
        }
    } catch (e) { console.error("Error auto-save", e); }
}

// Escuchar cambios en el textarea para actualizar el objeto
$(document).on("input", "#textoTranscripcion", function () {
    if (currentTransStudy) currentTransStudy.reportText = $(this).val();
});

// === 4. DEVOLVER AL MÉDICO ===
async function devolverAudioAlMedico() {
    if (!currentTranscriptionChain) return;

    const motivo = prompt("Indique por qué devuelve este audio al Radiólogo (Ej: Audio inaudible, cortado, vacío):");
    if (!motivo) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const btn = $("#btnDevolverAudio");

    try {
        btn.prop('disabled', true).html('Devolviendo...');

        const response = await fetch(`${API_URL}/transcription/appointments/${currentTranscriptionChain.id}/return`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
            body: JSON.stringify({ reason: motivo })
        });

        if (response.ok) {
            showToast("Audio devuelto al Radiólogo.", "warning");
            limpiarPantallaTranscripcion();
            cargarListaTranscripcion();
        }
    } catch (e) {
        showToast("Error al devolver.", "danger");
    } finally {
        btn.prop('disabled', false).html('<i class="bi bi-exclamation-triangle me-1"></i> Reportar Audio');
    }
}  