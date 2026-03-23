/* =========================================
   MÓDULO DE TRANSCRIPCIÓN (transcription.js)
   ========================================= */

let currentTranscriptionChain = null;
let currentTransStudy = null;

function initTranscription() {
    loadRISState();
    renderListaTranscripcion();
    setupSincronizacionTranscripcion();
}

function setupSincronizacionTranscripcion() {
    window.addEventListener('storage', (e) => {
        if (e.key === 'ris_app_data') { loadRISState(); renderListaTranscripcion(); }
    });
    window.addEventListener('ris_updated', () => { renderListaTranscripcion(); });
}

function renderListaTranscripcion() {
    const lista = $("#listaTranscripcion");
    if (!lista.length) return;
    lista.empty();

    const cadenas = {};
    (window.RIS.worklist || []).forEach(item => {
        if (item.status !== 'en_transcripcion') return;

        const acc = item.accessionNumber || item.id;
        if (!cadenas[acc]) {
            cadenas[acc] = {
                accessionNumber: acc,
                patient: item.patient,
                hasAudio: item.hasAudio,
                mDestinado: item.mDestinado, // <-- Capturamos al Médico
                items: [],
                allExams: [],
                machines: new Set()
            };
        }
        cadenas[acc].items.push(item);
        item.studies.forEach(s => cadenas[acc].allExams.push(s.exam));
        cadenas[acc].machines.add(item.machine);
    });

    const pendientes = Object.values(cadenas);
    $("#contadorAudios").text(pendientes.length);

    if (pendientes.length === 0) {
        lista.append('<div class="text-center text-muted small mt-4"><i class="bi bi-check2-circle fs-3 d-block mb-2 text-success"></i>Bandeja al día. No hay audios pendientes.</div>');
        return;
    }

    pendientes.forEach(cadena => {
        const isActive = currentTranscriptionChain && currentTranscriptionChain.accessionNumber === cadena.accessionNumber ? 'active bg-primary text-white border-primary' : '';
        const textColor = isActive ? 'text-white' : 'text-primary';
        const mutedColor = isActive ? 'text-white-50' : 'text-muted';

        const examsStr = cadena.allExams.join(" + ");
        const radAsignado = cadena.mDestinado || 'Dr. General'; // <-- Nombre del Médico

        lista.append(`
            <button type="button" class="list-group-item list-group-item-action ${isActive} p-3 border-bottom" onclick="abrirTranscripcion('${cadena.accessionNumber}')">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-truncate">${cadena.patient.lastName} ${cadena.patient.secondLastName || ''}, ${cadena.patient.name}</strong>
                    ${cadena.hasAudio ? '<i class="bi bi-mic-fill text-danger fs-5" title="Contiene audio dictado"></i>' : '<i class="bi bi-pencil-square text-warning fs-5" title="Solo borrador de texto"></i>'}
                </div>
                <div class="small ${mutedColor} mb-2">A.N.: ${cadena.accessionNumber} | Rad: <span class="fw-bold">${radAsignado}</span></div>
                <div class="small fw-bold ${textColor} text-truncate"><i class="bi bi-file-medical me-1"></i>${examsStr}</div>
            </button>
        `);
    });
}

function abrirTranscripcion(accessionNumber) {
    const itemsInChain = window.RIS.worklist.filter(w => w.accessionNumber === accessionNumber || w.id === accessionNumber);
    if (itemsInChain.length === 0) return;

    currentTranscriptionChain = {
        accessionNumber: accessionNumber,
        items: itemsInChain,
        patient: itemsInChain[0].patient,
        hasAudio: itemsInChain[0].hasAudio,
        mDestinado: itemsInChain[0].mDestinado,
        informeTexto: itemsInChain.find(i => i.informeTexto)?.informeTexto || "",
        allExams: [],
        machines: new Set()
    };

    itemsInChain.forEach(item => {
        item.studies.forEach(s => currentTranscriptionChain.allExams.push(s.exam));
        currentTranscriptionChain.machines.add(item.machine);
    });

    renderListaTranscripcion();

    const radAsignado = currentTranscriptionChain.mDestinado || 'Dr. General';

    $("#infoPacienteTranscripcion").html(`
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5 class="fw-bold mb-1 text-dark">${currentTranscriptionChain.patient.name} ${currentTranscriptionChain.patient.lastName} ${currentTranscriptionChain.patient.secondLastName || ''}</h5>
                <div class="text-muted small">
                    RUT: ${currentTranscriptionChain.patient.rut} | A.N.: ${currentTranscriptionChain.accessionNumber} | 
                    <span class="text-primary fw-bold"><i class="bi bi-person-badge me-1"></i>Rad: ${radAsignado}</span>
                </div>
            </div>
            <div class="text-end">
                <span class="badge bg-light text-dark border fs-6 mb-1">${Array.from(currentTranscriptionChain.machines).join(" + ")}</span>
            </div>
        </div>
        <hr class="my-2">
        <div class="small fw-bold text-primary">
            Exámenes a Transcribir: ${currentTranscriptionChain.allExams.join(" + ")}
        </div>
    `);

    if (currentTranscriptionChain.hasAudio) {
        $("#audioStatus").html('<span class="text-success fw-bold"><i class="bi bi-play-circle-fill me-1"></i>Audio de dictado global disponible</span>');
        $("#btnPlayPause, .btn-group .btn, .dropdown-toggle").prop("disabled", false);
        $("#audioProgress").prop("disabled", false);
        initAudioPlayer('https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3');
    } else {
        $("#audioStatus").html('<span class="text-warning fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i>Sin audio adjunto (Solo borrador escrito)</span>');
        $("#btnPlayPause, .btn-group .btn, .dropdown-toggle").prop("disabled", true);
        $("#audioProgress").prop("disabled", true).val(0);
        $("#timeCurrent, #timeTotal").text("0:00");
        initAudioPlayer('');
    }

    $("#textoTranscripcion").val(currentTranscriptionChain.informeTexto).prop("disabled", false);
    $("#btnEnviarValidacion").prop("disabled", false);
    $("#btnPlantilla").prop("disabled", false);
}

function cargarEstudioTranscripcion(itemId, studyUid) {
    const item = currentTranscriptionChain.items.find(i => i.id === itemId);
    const study = item.studies.find(s => s.studyUid === studyUid);
    currentTransStudy = { item, study };

    $(".study-tab-btn").removeClass("bg-primary text-white").addClass("btn-outline-primary");
    $(`#tab-${studyUid}`).removeClass("btn-outline-primary").addClass("bg-primary text-white");

    const textoA_Cargar = study.informeTexto || item.informeTexto || "";
    $("#textoTranscripcion").val(textoA_Cargar).prop("disabled", false);
    $("#btnEnviarValidacion, #btnPlantillaTranscripcion").prop("disabled", false);

    if (study.hasAudio) {
        $("#audioStatus").html('<span class="text-success fw-bold"><i class="bi bi-play-circle-fill me-1"></i>Audio de dictado disponible</span>');
        $("#btnPlayPause, .btn-group .btn, .dropdown-toggle").prop("disabled", false);
        $("#audioProgress").prop("disabled", false);
        initAudioPlayer('https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3');
    } else {
        $("#audioStatus").html('<span class="text-warning fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i>Solo borrador de texto</span>');
        $("#btnPlayPause, .btn-group .btn, .dropdown-toggle").prop("disabled", true);
        $("#audioProgress").prop("disabled", true).val(0);
        initAudioPlayer('');
    }
}

function enviarAValidacion() {
    if (!currentTranscriptionChain) return;

    const texto = $("#textoTranscripcion").val().trim();
    if (!texto) return showToast("El informe no puede estar vacío.", "warning");

    if (confirm("¿Enviar este informe corregido a Validación? El Radiólogo deberá revisarlo nuevamente.")) {

        currentTranscriptionChain.items.forEach(item => {
            const wlIdx = window.RIS.worklist.findIndex(w => w.id === item.id);
            if (wlIdx > -1) {
                window.RIS.worklist[wlIdx].informeTexto = texto;
                window.RIS.worklist[wlIdx].status = 'para_firma';
                window.RIS.worklist[wlIdx].firmado = false;
                window.RIS.worklist[wlIdx].fechaTranscripcion = new Date().toLocaleString();
                window.RIS.worklist[wlIdx].transcriptor = "Secretaria General";

                delete window.RIS.worklist[wlIdx].notasCorreccion;

                window.RIS.worklist[wlIdx].studies.forEach(s => {
                    s.reportStatus = 'para_firma';
                    s.informeTexto = texto;
                });
            }

            const agendaIdx = window.RIS.agenda.findIndex(a => a.id === item.id);
            if (agendaIdx > -1) window.RIS.agenda[agendaIdx].status = 'para_firma';
        });

        saveRISState();
        limpiarPantallaTranscripcion();
        showToast("✅ Informe devuelto a la Bandeja de Firma.", "success");
    }
}

function verificarSiQuedanEstudiosTrans(estado, mensajeExito) {
    showToast(mensajeExito, "success");
    let quedan = false;
    currentTranscriptionChain.items.forEach(i => i.studies.forEach(s => { if (s.reportStatus === estado) quedan = true; }));

    if (!quedan) limpiarPantallaTranscripcion();
    else abrirTranscripcion(currentTranscriptionChain.accessionNumber);
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

    renderListaTranscripcion();
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
    currentTransStudy.study.informeTexto = textarea.val();
    showToast("Plantilla insertada con éxito.", "info");
}

$(document).ready(function () {
    $(document).on("input", "#textoTranscripcion", function () {
        if (currentTransStudy) {
            currentTransStudy.study.informeTexto = $(this).val();
        }
    });
});