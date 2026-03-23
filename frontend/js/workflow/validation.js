/* =========================================
   MÓDULO DE VALIDACIÓN (validation.js)
   ========================================= */

let currentValidationChain = null;
let currentValStudy = null;

function initValidation() {
    loadRISState();
    llenarFiltroRadiologos();
    renderValidationStudies();
    setupSincronizacionValidacion();
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
        limpiarPantallaValidacion();
    });
}

function setupSincronizacionValidacion() {
    window.addEventListener('storage', (e) => {
        if (e.key === 'ris_app_data') { loadRISState(); renderValidationStudies(); }
    });
    window.addEventListener('ris_updated', () => { renderValidationStudies(); });
}

function renderValidationStudies() {
    const lista = $("#validationStudies");
    if (!lista.length) return;
    lista.empty();

    const radActivo = $("#filtroRadiologoActivo").val();
    const cadenas = {};
    let firmasCount = 0;

    (window.RIS.worklist || []).forEach(item => {
        if (radActivo !== 'ALL' && item.mDestinado && item.mDestinado !== radActivo) {
            return;
        }

        let hasPending = false;
        item.studies.forEach(s => {
            if (s.reportStatus === 'para_firma') hasPending = true;
        });

        if (hasPending) {
            const acc = item.accessionNumber || item.id;
            if (!cadenas[acc]) {
                cadenas[acc] = { accessionNumber: acc, patient: item.patient, items: [], allExams: [] };
                firmasCount++;
            }
            cadenas[acc].items.push(item);
            item.studies.forEach(s => {
                if (s.reportStatus === 'para_firma') cadenas[acc].allExams.push(s.exam);
            });
        }
    });

    $("#badgeParaFirma").text(firmasCount);
    if (firmasCount === 0) return lista.append('<div class="p-4 text-center text-muted"><i class="bi bi-check2-all fs-2 d-block mb-2 text-success"></i>Bandeja vacía.</div>');

    Object.values(cadenas).forEach(cadena => {
        const isActive = currentValidationChain && currentValidationChain.accessionNumber === cadena.accessionNumber ? 'active bg-primary text-white border-primary' : '';
        lista.append(`
            <button type="button" class="list-group-item list-group-item-action ${isActive} p-3 border-bottom" onclick="cargarValidacion('${cadena.accessionNumber}')">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-truncate">${cadena.patient.lastName}, ${cadena.patient.name}</strong>
                </div>
                <div class="small fw-bold ${isActive ? 'text-white' : 'text-primary'} text-truncate"><i class="bi bi-file-text me-1"></i>${cadena.allExams.join(" + ")}</div>
            </button>
        `);
    });
}

function cargarValidacion(accessionNumber) {
    const itemsInChain = window.RIS.worklist.filter(w => w.accessionNumber === accessionNumber || w.id === accessionNumber);
    if (itemsInChain.length === 0) return;

    currentValidationChain = {
        accessionNumber: accessionNumber,
        items: itemsInChain,
        patient: itemsInChain[0].patient,
        mTratante: itemsInChain[0].mTratante,
        fechaAten: itemsInChain[0].start,
        informeTexto: itemsInChain.find(i => i.informeTexto)?.informeTexto || "",
        allExams: [],
        machines: new Set()
    };

    itemsInChain.forEach(item => {
        item.studies.forEach(s => currentValidationChain.allExams.push(s.exam));
        currentValidationChain.machines.add(item.machine);
    });

    renderValidationStudies();

    $("#infoPacienteValidacion").addClass("d-none");
    $("#docHeader, #firmaFalsa").removeClass("d-none");

    const clinicaNombre = (window.RIS.config && window.RIS.config.clinicName) ? window.RIS.config.clinicName : "Centro de Diagnóstico Integral RIS PRO";
    const clinicaDireccion = (window.RIS.config && window.RIS.config.clinicAddress) ? window.RIS.config.clinicAddress : "Av. Las Araucarias 1020, Temuco, Chile";

    $("#docClinicaNombre").text(clinicaNombre.toUpperCase());
    $("#docClinicaDireccion").html(`<i class="bi bi-geo-alt-fill me-1"></i>${clinicaDireccion}`);

    const p = currentValidationChain.patient;
    $("#docPaciente").text(`${p.name} ${p.lastName} ${p.secondLastName || ''}`);
    $("#docRut").text(p.rut);
    $("#docEdad").text(p.age ? `${p.age} años` : 'No especificada');

    let fechaTexto = 'No registrada';
    if (currentValidationChain.fechaAten) {
        const fechaAtencionObj = new Date(currentValidationChain.fechaAten);
        if (!isNaN(fechaAtencionObj)) fechaTexto = fechaAtencionObj.toLocaleDateString('es-CL');
    }

    $("#docFecha").text(fechaTexto);
    $("#docDerivante").text(currentValidationChain.mTratante || 'No indicado');
    $("#docIdCita").text(`Accession Global: ${currentValidationChain.accessionNumber}`);

    const examenesStr = currentValidationChain.allExams.join(" + ");
    $("#docExamen").text(examenesStr);
    $("#examenesValidacion").html(`<i class="bi bi-eye me-1"></i> Revisando Cadena: ${examenesStr}`);

    const radActivo = $("#filtroRadiologoActivo").val();
    const nombreFirma = radActivo !== 'ALL' ? radActivo : (currentValidationChain.items[0].mDestinado || "Dr. Radiólogo General");
    $("#firmaNombre").text(nombreFirma);

    $("#finalReportText").val(currentValidationChain.informeTexto).prop("disabled", false);
    $("#btnRechazar, #btnAprobar, #btnPreview").prop("disabled", false);
}

function firmarInforme() {
    if (!currentValidationChain) return;

    const textoFinal = $("#finalReportText").val().trim();
    if (!textoFinal) return showToast("El informe no puede estar vacío.", "danger");

    if (confirm("¿Confirmas que el informe global está correcto y procedes a firmarlo digitalmente?")) {

        const fechaFirma = new Date().toLocaleString();

        const radActivo = $("#filtroRadiologoActivo").val();
        const nombreFirma = radActivo !== 'ALL' ? radActivo : (currentValidationChain.items[0].mDestinado || "Dr. Radiólogo General");

        currentValidationChain.items.forEach(item => {
            const wlIdx = window.RIS.worklist.findIndex(w => w.id === item.id);
            if (wlIdx > -1) {
                window.RIS.worklist[wlIdx].informeTexto = textoFinal;
                window.RIS.worklist[wlIdx].status = 'entregable';
                window.RIS.worklist[wlIdx].firmado = true;
                window.RIS.worklist[wlIdx].fechaFirma = fechaFirma;
                window.RIS.worklist[wlIdx].medicoFirmante = nombreFirma;

                window.RIS.worklist[wlIdx].studies.forEach(s => {
                    s.reportStatus = 'entregable';
                    s.informeTexto = textoFinal;
                    s.firmado = true;
                    s.fechaFirma = fechaFirma;
                    s.medicoFirmante = nombreFirma;
                });
            }

            const agendaIdx = window.RIS.agenda.findIndex(a => a.id === item.id);
            if (agendaIdx > -1) {
                window.RIS.agenda[agendaIdx].status = 'entregable';
                if (window.RIS.agenda[agendaIdx].studies) {
                    window.RIS.agenda[agendaIdx].studies.forEach(s => s.reportStatus = 'entregable');
                }
            }
        });

        saveRISState();
        limpiarPantallaValidacion();
        showToast("✅ Informe Global firmado digitalmente por " + nombreFirma, "success");
    }
}

function rechazarInforme() {
    if (!currentValidationChain) return;

    const motivo = prompt("Indique a la secretaria las correcciones que debe realizar al informe global:");

    if (motivo) {
        currentValidationChain.items.forEach(item => {
            const wlIdx = window.RIS.worklist.findIndex(w => w.id === item.id);
            if (wlIdx > -1) {
                window.RIS.worklist[wlIdx].informeTexto = $("#finalReportText").val().trim();
                window.RIS.worklist[wlIdx].notasCorreccion = motivo;
                window.RIS.worklist[wlIdx].status = 'en_transcripcion';
                window.RIS.worklist[wlIdx].firmado = false;

                window.RIS.worklist[wlIdx].studies.forEach(s => s.reportStatus = 'pendiente_transcripcion');
            }
            const agendaIdx = window.RIS.agenda.findIndex(a => a.id === item.id);
            if (agendaIdx > -1) window.RIS.agenda[agendaIdx].status = 'en_transcripcion';
        });

        saveRISState();
        limpiarPantallaValidacion();
        showToast("Estudio Global devuelto a la Bandeja de Transcripción", "warning");
    }
}

function limpiarPantallaValidacion() {
    currentValidationChain = null;
    currentValStudy = null;
    $("#infoPacienteValidacion").removeClass("d-none");
    $("#docHeader, #firmaFalsa").addClass("d-none");
    $("#examenesValidacion").empty();
    $("#finalReportText").val("").prop("disabled", true);
    $("#btnRechazar, #btnAprobar, #btnPreview").prop("disabled", true);

    renderValidationStudies();
}

function generarVistaPrevia() {
    if (typeof showLoader === 'function') showLoader();
    $("#finalReportText").css({ "resize": "none", "overflow": "hidden", "height": "auto" });
    $("#finalReportText")[0].style.height = $("#finalReportText")[0].scrollHeight + "px";

    if (typeof html2pdf !== 'undefined') {
        html2pdf().set({ margin: [15, 15, 15, 15], filename: 'preview.pdf' }).from(document.getElementById('papelInforme')).save().then(() => {
            if (typeof hideLoader === 'function') hideLoader();
            $("#finalReportText").css({ "height": "100%" });
        });
    } else {
        if (typeof hideLoader === 'function') hideLoader();
        alert("Librería PDF no cargada.");
    }
}