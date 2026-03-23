/* =========================================
   MÓDULO WORKLIST (worklist.js) - Tecnólogo Médico
   ========================================= */

let currentAtencionChain = null;

function initWorklist() {
    loadRISState();
    llenarFiltroSalas();
    renderWorklist();
    renderAlertasInsumos();
}

function llenarFiltroSalas() {
    const select = $("#filterMachine");
    select.empty().append('<option value="">Todas las Salas</option>');
    (window.RIS.resources || []).forEach(res => {
        select.append(`<option value="${res.id}">${res.title}</option>`);
    });
}

function renderWorklist() {
    const tbody = $("#worklistTable tbody");
    const filter = $("#filterMachine").val();
    const search = $("#searchPatient").val() ? $("#searchPatient").val().toLowerCase() : "";

    tbody.empty();

    const cadenas = {};
    (window.RIS.worklist || []).forEach(item => {
        if (!['waiting', 'dicom_enviado'].includes(item.status)) return;

        const rut = item.patient.rut;
        const dateStr = item.start ? item.start.split('T')[0] : 'nodate';
        const chainId = rut + "_" + dateStr;

        if (!cadenas[chainId]) {
            cadenas[chainId] = {
                chainId: chainId,
                patient: item.patient,
                items: [],
                machines: new Set(),
                allStudies: [],
                priority: item.priority || 'Normal',
                status: 'waiting',
                globalAccession: null,
                referenceId: item.id,
                notasDevolucion: null
            };
        }

        cadenas[chainId].items.push(item);
        cadenas[chainId].machines.add(item.machine);
        item.studies.forEach(s => cadenas[chainId].allStudies.push({ ...s, _machine: item.machine }));

        if (item.status === 'dicom_enviado') cadenas[chainId].status = 'dicom_enviado';
        if (item.priority === 'Urgencia') cadenas[chainId].priority = 'Urgencia';
        else if (item.priority === 'Alta' && cadenas[chainId].priority !== 'Urgencia') cadenas[chainId].priority = 'Alta';
        if (item.accessionNumber) cadenas[chainId].globalAccession = item.accessionNumber;
        if (item.notasDevolucion) cadenas[chainId].notasDevolucion = item.notasDevolucion;
    });

    const filtered = Object.values(cadenas).filter(cadena => {
        const matchesMachine = filter === "" || Array.from(cadena.machines).includes(filter);
        const p = cadena.patient;
        const pName = p.name ? p.name.toLowerCase() : "";
        const pLast = p.lastName ? p.lastName.toLowerCase() : "";
        const pRut = p.rut ? p.rut.toLowerCase() : "";
        const matchesSearch = pName.includes(search) || pLast.includes(search) || pRut.includes(search);
        return matchesMachine && matchesSearch;
    });

    if (filtered.length === 0) {
        tbody.append('<tr><td colspan="6" class="text-center p-4 text-muted">No hay pacientes en espera</td></tr>');
        return;
    }

    filtered.forEach(cadena => {
        const statusConfig = getStatusBadge(cadena.status);
        const machinesBadges = Array.from(cadena.machines).map(m => `<span class="badge bg-light text-dark border me-1">${m}</span>`).join('');
        const studiesBadges = cadena.allStudies.map(s => `<span class="badge bg-primary-subtle text-primary me-1 mb-1">${s.exam}</span>`).join('');

        const alertIcon = cadena.notasDevolucion
            ? `<span class="blink-icon shadow-sm me-2" title="Devuelto por Radiólogo: ${cadena.notasDevolucion}" 
                     style="display: inline-flex; align-items: center; justify-content: center; 
                            width: 18px; height: 18px; background-color: red; color: white; 
                            border-radius: 50%; font-weight: 900; font-size: 13px; 
                            border: 1px solid white; flex-shrink: 0; box-shadow: 0 0 5px rgba(255,0,0,0.8);">!</span>`
            : '';

        const accLabel = cadena.globalAccession
            ? `<div class="small fw-bold text-primary mt-1" style="${cadena.notasDevolucion ? 'margin-left: 26px;' : ''}"><i class="bi bi-upc-scan me-1"></i>A.N: ${cadena.globalAccession}</div>`
            : '';

        tbody.append(`
            <tr class="worklist-row align-middle">
                <td>
                    <div class="fw-bold text-dark d-flex align-items-center">
                        ${alertIcon}
                        <span class="text-truncate">${cadena.patient.lastName || ''} ${cadena.patient.secondLastName || ''}, ${cadena.patient.name || ''}</span>
                    </div>
                    <small class="text-muted d-block" style="${cadena.notasDevolucion ? 'margin-left: 26px;' : ''}">${cadena.patient.rut}</small>
                    ${accLabel}
                </td>
                <td>${studiesBadges}</td>
                <td>${machinesBadges}</td>
                <div class="small text-muted mt-1"><i class="bi bi-person-fill"></i> ${cadena.items[0].mDestinado || 'Dr. General'}</div>
                <td><span class="text-${cadena.priority === 'Alta' || cadena.priority === 'Urgencia' ? 'danger fw-bold' : 'muted'}">${cadena.priority}</span></td>
                <td>${statusConfig}</td>
                <td class="text-center">
                    <button onclick="abrirAtencion('${cadena.chainId}')" class="btn btn-sm btn-primary px-3 shadow-sm">
                        <i class="bi bi-play-fill me-1"></i>Atender
                    </button>
                </td>
            </tr>
        `);
    });
}

function abrirAtencion(chainId) {
    const parts = chainId.split('_');
    const rut = parts[0];
    const dateStr = parts[1];

    const itemsInChain = window.RIS.worklist.filter(item => {
        if (!['waiting', 'dicom_enviado'].includes(item.status)) return false;
        const itemDate = item.start ? item.start.split('T')[0] : 'nodate';
        return item.patient.rut === rut && itemDate === dateStr;
    });

    if (itemsInChain.length === 0) return;

    currentAtencionChain = {
        items: itemsInChain,
        patient: itemsInChain[0].patient,
        allStudies: [],
        machines: new Set(),
        status: itemsInChain.some(i => i.status === 'dicom_enviado') ? 'dicom_enviado' : 'waiting',
        globalAccession: itemsInChain[0].accessionNumber || null,
        notasDevolucion: itemsInChain.find(i => i.notasDevolucion)?.notasDevolucion || null
    };

    itemsInChain.forEach(item => {
        currentAtencionChain.machines.add(item.machine);
        item.studies.forEach(s => currentAtencionChain.allStudies.push({ ...s, _machine: item.machine }));
    });

    $("#atencionNombre").text(`${currentAtencionChain.patient.name} ${currentAtencionChain.patient.lastName}`);
    $("#atencionRut").text(currentAtencionChain.patient.rut);
    $("#atencionSala").text(Array.from(currentAtencionChain.machines).join(" + "));
    $("#atencionId").text(chainId);

    const existingAnamnesis = itemsInChain.find(i => i.anamnesis)?.anamnesis || "";
    $("#txtAnamnesis").val(existingAnamnesis);

    const studiesDiv = $("#atencionEstudios");
    studiesDiv.empty();
    currentAtencionChain.allStudies.forEach((s) => {
        studiesDiv.append(`
            <div class="list-group-item d-flex justify-content-between align-items-center bg-white border-primary border-start border-4 mb-1">
                <div>
                    <strong class="text-dark">${s.exam}</strong>
                    <small class="d-block text-muted">Sub-Examen: ${s.subExam || 'N/A'}</small>
                </div>
                <span class="badge bg-secondary">${s._machine}</span>
            </div>
        `);
    });

    $("#alertDevolucion").remove();
    if (currentAtencionChain.notasDevolucion) {
        studiesDiv.before(`
            <div id="alertDevolucion" class="alert border-danger bg-danger-subtle shadow-sm mb-3">
                <h6 class="fw-bold text-danger mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> ATENCIÓN: Estudio Devuelto por Radiólogo</h6>
                <p class="mb-0 text-dark small"><strong>Motivo del rechazo:</strong> ${currentAtencionChain.notasDevolucion}</p>
            </div>
        `);
    }

    setDicomUI(currentAtencionChain.status === 'dicom_enviado', currentAtencionChain.globalAccession);

    $("#tablaInsumosAsignados tbody").empty();
    $("#insumoTipo").val("");
    $("#insumoItem").empty().append('<option value="">Seleccione Insumo...</option>');

    $("#modalAtencion").modal('show');
}

function setDicomUI(enviado, acc = null) {
    if (acc) {
        $("#globalAccessionDisplay").removeClass("d-none").text("Accession Global: " + acc);

        if (enviado) {
            $("#btnDicom").attr("disabled", true).removeClass("btn-info").addClass("btn-secondary")
                .html('<i class="bi bi-check-circle"></i> ENVIADO A MODALIDADES');
            $("#dicomStatus").html('<span class="text-success fw-bold">● PACIENTE LISTO EN EQUIPOS</span>');
        } else {
            $("#btnDicom").attr("disabled", false).addClass("btn-info text-white").removeClass("btn-secondary")
                .html('<i class="bi bi-broadcast"></i> RE-ENVIAR A EQUIPOS');
            $("#dicomStatus").html('<span class="text-warning fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i>Ya posee A.N. ¿Re-enviar DICOM?</span>');
        }
    } else {
        $("#btnDicom").attr("disabled", false).addClass("btn-info text-white").removeClass("btn-secondary")
            .html('<i class="bi bi-broadcast"></i> CREAR A.N. Y ENVIAR A EQUIPOS');
        $("#dicomStatus").html('<span class="text-muted">Esperando envío DICOM...</span>');
        $("#globalAccessionDisplay").addClass("d-none").text("");
    }
}

function actualizarListaInsumos() {
    const tipo = $("#insumoTipo").val();
    const itemSelect = $("#insumoItem");
    itemSelect.empty().append('<option value="">Seleccione Insumo...</option>');

    if (tipo && window.RIS.inventoryZero[tipo]) {
        window.RIS.inventoryZero[tipo].forEach(ins => {
            const lowStockTag = (ins.stock <= ins.total * 0.10) ? ' ⚠️ (Crítico)' : '';
            itemSelect.append(`<option value="${ins.id}" data-stock="${ins.stock}">${ins.nombre} - Disp: ${ins.stock}${lowStockTag}</option>`);
        });
    }
}

function agregarInsumo() {
    const tipo = $("#insumoTipo").val();
    const itemSelect = $("#insumoItem option:selected");
    const itemId = itemSelect.val();
    const currentStock = parseInt(itemSelect.data("stock"));

    if (!itemId) return;
    if (currentStock <= 0) return showToast("Stock agotado para este insumo", "danger");

    const insumo = window.RIS.inventoryZero[tipo].find(i => i.id === itemId);
    insumo.stock -= 1;

    saveRISState();
    evaluarUmbralInsumo(insumo);
    renderAlertasInsumos();
    actualizarListaInsumos();

    const rowId = 'insrow-' + Date.now();
    const row = `
        <tr id="${rowId}" class="align-middle">
            <td class="fw-bold">${insumo.nombre}</td>
            <td style="width: 100px;">
                <input type="number" class="form-control form-control-sm text-center ins-qty" 
                value="1" min="1" data-id="${insumo.id}" data-tipo="${tipo}" data-prev="1" 
                onchange="modificarCantidadInsumo('${rowId}')">
            </td>
            <td class="text-center">
                <button onclick="removerInsumo('${rowId}', '${tipo}', '${insumo.id}')" class="btn btn-sm btn-outline-danger p-1">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>`;
    $("#tablaInsumosAsignados tbody").append(row);
}

function modificarCantidadInsumo(rowId) {
    const input = $(`#${rowId} .ins-qty`);
    const newVal = parseInt(input.val()) || 1;
    const prevVal = parseInt(input.data("prev"));
    const id = input.data("id");
    const tipo = input.data("tipo");

    const insumo = window.RIS.inventoryZero[tipo].find(i => i.id === id);
    const diff = newVal - prevVal;

    if (insumo.stock < diff) {
        showToast(`Solo quedan ${insumo.stock} unidades de ${insumo.nombre}`, "danger");
        input.val(prevVal);
        return;
    }

    insumo.stock -= diff;
    input.data("prev", newVal);

    saveRISState();
    evaluarUmbralInsumo(insumo);
    renderAlertasInsumos();
    actualizarListaInsumos();
}

function removerInsumo(rowId, tipo, insumoId) {
    const qty = parseInt($(`#${rowId} .ins-qty`).val()) || 1;
    const insumo = window.RIS.inventoryZero[tipo].find(i => i.id === insumoId);

    insumo.stock += qty;
    saveRISState();
    renderAlertasInsumos();
    actualizarListaInsumos();

    $(`#${rowId}`).remove();
    showToast(`${qty} ${insumo.nombre} devuelto(s) al stock`, "info");
}

function evaluarUmbralInsumo(insumo) {
    const threshold = insumo.total * 0.10;
    if (insumo.stock <= threshold) {
        showToast(`⚠️ STOCK CRÍTICO: Quedan solo ${insumo.stock} unidades de ${insumo.nombre}`, "warning");
    }
}

function renderAlertasInsumos() {
    let container = $("#alertasInsumosContainer");
    if (container.length === 0) {
        $("#worklistTable").closest('.table-responsive').before('<div id="alertasInsumosContainer" class="mb-3"></div>');
        container = $("#alertasInsumosContainer");
    }

    container.empty();
    let alertasHtml = '';
    let hayAlertas = false;

    for (const tipo in window.RIS.inventoryZero) {
        window.RIS.inventoryZero[tipo].forEach(insumo => {
            const pct = (insumo.stock / insumo.total) * 100;
            if (pct <= 10) {
                hayAlertas = true;
                const progressColor = pct <= 5 ? 'bg-danger' : 'bg-warning';
                alertasHtml += `
                    <span class="badge ${progressColor} text-dark me-2 mb-2 p-2 shadow-sm border border-dark">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        ${insumo.nombre}: ${insumo.stock} Disp. (${Math.round(pct)}%)
                    </span>`;
            }
        });
    }

    if (hayAlertas) {
        container.html(`
            <div class="alert border-warning shadow-sm py-2 mb-0 d-flex align-items-center" style="background-color: #fffbeb;">
                <i class="bi bi-boxes fs-3 me-3 text-danger pulse-icon"></i>
                <div>
                    <strong class="d-block text-danger mb-1"><i class="bi bi-arrow-down-right"></i> Panel de Abastecimiento Clínico</strong>
                    <div>${alertasHtml}</div>
                </div>
            </div>
            <style>.pulse-icon { animation: pulse 1.5s infinite; } @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }</style>
        `);
    }
}

function finalizarAtencion() {
    if (!currentAtencionChain || currentAtencionChain.items.length === 0) return;

    const anamnesis = $("#txtAnamnesis").val();
    if (!anamnesis) return showToast("⚠️ La anamnesis clínica es obligatoria para el Radiólogo.", "warning");

    currentAtencionChain.items.forEach(item => {
        const wlIdx = window.RIS.worklist.findIndex(w => w.id === item.id);
        if (wlIdx > -1) {
            window.RIS.worklist[wlIdx].status = 'en_informe';
            window.RIS.worklist[wlIdx].anamnesis = anamnesis;
            window.RIS.worklist[wlIdx].atendidoEl = new Date().toLocaleString();

            delete window.RIS.worklist[wlIdx].notasDevolucion;

            window.RIS.worklist[wlIdx].studies.forEach((study, sIdx) => {
                study.studyUid = study.studyUid || `ST-${item.id}-${sIdx}`;
                if (!study.reportStatus) {
                    study.reportStatus = 'pendiente_radiologo';
                    study.reportText = '';
                    study.reportAudio = null;
                    study.informadoPor = '';
                }
            });
        }

        const agendaIdx = window.RIS.agenda.findIndex(a => a.id === item.id);
        if (agendaIdx > -1) {
            window.RIS.agenda[agendaIdx].status = 'en_informe';
            if (wlIdx > -1) {
                window.RIS.agenda[agendaIdx].studies = JSON.parse(JSON.stringify(window.RIS.worklist[wlIdx].studies));
            }
        }
    });

    saveRISState();
    $("#modalAtencion").modal('hide');
    renderWorklist();
    window.dispatchEvent(new Event('ris_updated'));
    showToast("✅ Cadena de estudios derivada exitosamente al Radiólogo", "success");
}

function enviarADicom() {
    if (!currentAtencionChain || currentAtencionChain.items.length === 0) return;

    const globalAcc = currentAtencionChain.globalAccession || "ACC-" + Date.now();

    currentAtencionChain.items.forEach(item => {
        const wlIdx = window.RIS.worklist.findIndex(w => w.id === item.id);
        if (wlIdx > -1) {
            window.RIS.worklist[wlIdx].accessionNumber = globalAcc;
            window.RIS.worklist[wlIdx].status = 'dicom_enviado';
        }

        const agendaIdx = window.RIS.agenda.findIndex(a => a.id === item.id);
        if (agendaIdx > -1) window.RIS.agenda[agendaIdx].status = 'dicom_enviado';
    });

    currentAtencionChain.status = 'dicom_enviado';
    currentAtencionChain.globalAccession = globalAcc;

    saveRISState();
    setDicomUI(true, globalAcc);
    renderWorklist();
    window.dispatchEvent(new Event('ris_updated'));
    showToast("📡 Accession Number Global generado y enviado a modalidades.", "info");
}

function deshacerEstado() {
    if (!currentAtencionChain) return;

    if (currentAtencionChain.status === 'dicom_enviado') {
        currentAtencionChain.items.forEach(item => {
            const wlIdx = window.RIS.worklist.findIndex(w => w.id === item.id);
            if (wlIdx > -1) window.RIS.worklist[wlIdx].status = 'waiting';

            const agendaIdx = window.RIS.agenda.findIndex(a => a.id === item.id);
            if (agendaIdx > -1) window.RIS.agenda[agendaIdx].status = 'waiting';
        });

        currentAtencionChain.status = 'waiting';
        saveRISState();
        setDicomUI(false);
        renderWorklist();
        window.dispatchEvent(new Event('ris_updated'));
        showToast("Cadena revertida a Sala de Espera.", "info");
    } else {
        showToast("No hay un estado técnico previo para deshacer.", "secondary");
    }
}

function getStatusBadge(status) {
    switch (status) {
        case 'waiting': return '<span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>En Espera</span>';
        case 'dicom_enviado': return '<span class="badge bg-info text-white"><i class=\"bi bi-cpu me-1\"></i>En Modalidad</span>';
        default: return '<span class="badge bg-secondary">Pendiente</span>';
    }
}

function devolverAAgenda() {
    if (!currentAtencionChain) return;

    if (confirm("¿Confirmas que deseas devolver al paciente a Recepción? Toda la cadena de estudios se eliminará de la lista técnica.")) {
        currentAtencionChain.items.forEach(item => {
            const agendaIdx = window.RIS.agenda.findIndex(a => a.id === item.id);
            if (agendaIdx > -1) {
                window.RIS.agenda[agendaIdx].status = 'confirmado';
                window.RIS.agenda[agendaIdx].needsReview = true;
            }
            window.RIS.worklist = window.RIS.worklist.filter(w => w.id !== item.id);
        });

        saveRISState();
        $("#modalAtencion").modal('hide');
        renderWorklist();
        window.dispatchEvent(new Event('ris_updated'));
        showToast("Paciente devuelto a Recepción.", "warning");
    }
}

window.addEventListener('storage', (e) => {
    if (e.key === 'ris_app_data') {
        loadRISState();
        renderWorklist();
        renderAlertasInsumos();
    }
});

window.addEventListener('ris_updated', () => {
    if ($("#worklistTable").length) {
        renderWorklist();
        renderAlertasInsumos();
    }
});