/* =========================================
   MÓDULO DE AGENDA Y RECEPCIÓN (agenda.js)
   ========================================= */

let calendar;
let currentInsumos = [];
window.currentInsumosTotal = 0;

function calcularDuracionCita(machineId, cantidadExamenes) {
    const sala = (window.RIS.resources || []).find(r => r.id === machineId);
    const minutosBase = (sala && window.RIS.tiemposPorGrupo[sala.group]) ? window.RIS.tiemposPorGrupo[sala.group] : 15;
    return minutosBase * cantidadExamenes;
}

function toLocalISOString(date) {
    if (!date) return "";
    const pad = n => (n < 10 ? '0' + n : n);
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:00`;
}

function validarRut(rut) {
    let valor = rut.replace(/\./g, '');
    if (!/^[0-9]+[-|‐][0-9kK]{1}$/.test(valor)) return false;
    let tmp = valor.split('-');
    let digv = tmp[1].toLowerCase();
    let rutCuerpo = tmp[0];
    let suma = 0;
    let multiplo = 2;
    for (let i = 1; i <= rutCuerpo.length; i++) {
        let indexValue = multiplo * valor.charAt(rutCuerpo.length - i);
        suma = suma + indexValue;
        if (multiplo < 7) multiplo = multiplo + 1; else multiplo = 2;
    }
    let res = 11 - (suma % 11);
    let vlp = (res == 11) ? 0 : (res == 10) ? 'k' : res;
    return vlp == digv;
}

function initAgenda() {
    loadRISState();
    const calendarEl = document.getElementById('calendar');
    if (!calendarEl) return;
    setupCalendar(calendarEl);
    loadProMasterData();
    setupProEventListeners();
}

function setupCalendar(el) {
    if (calendar) calendar.destroy();

    const configRIS = window.RIS.config || { horaInicio: '08:00:00', horaFin: '20:00:00', intervalo: '00:15:00' };
    const recursosData = (window.RIS && window.RIS.resources) ? window.RIS.resources : [];

    calendar = new FullCalendar.Calendar(el, {
        schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source',
        locale: 'es',
        timeZone: 'local',
        initialView: 'resourceTimelineDay',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'resourceTimelineDay,resourceTimelineWeek,dayGridMonth' },
        resourceGroupField: 'group',
        resourceAreaWidth: '15%',
        resourceAreaHeaderContent: 'Salas',
        allDaySlot: false,
        slotMinTime: configRIS.horaInicio,
        slotMaxTime: configRIS.horaFin,
        slotDuration: configRIS.intervalo,
        eventOverlap: false,
        selectOverlap: false,
        resources: recursosData,
        events: function (info, successCallback, failureCallback) {
            const eventosParaMostrar = (window.RIS.agenda || []).map(cita => {
                const estadosIniciales = ['agendado', 'confirmado', 'espera'];
                const isLocked = !estadosIniciales.includes(cita.status);

                const sala = window.RIS.resources.find(r => r.id === cita.machine);
                const colorOriginal = sala ? sala.eventColor : '#3788d8';

                return {
                    id: cita.id,
                    resourceId: cita.machine,
                    start: cita.start,
                    end: cita.end,
                    title: cita.patient ? `${cita.patient.name} ${cita.patient.lastName}` : 'Paciente',
                    backgroundColor: isLocked ? '#6c757d' : colorOriginal,
                    borderColor: isLocked ? '#495057' : colorOriginal,
                    extendedProps: {
                        ...cita,
                        isLocked: isLocked
                    }
                };
            });
            successCallback(eventosParaMostrar);
        },
        selectable: true,
        editable: true,
        eventResourceEditable: true,
        droppable: true,
        slotMinWidth: 120,
        select: function (info) { abrirModalCita({ start: info.startStr, machine: info.resource ? info.resource.id : null }); },
        eventClick: function (info) {
            const appointment = window.RIS.agenda.find(a => a.id === info.event.id);
            if (appointment) abrirModalCita(appointment);
        },
        eventDrop: function (info) {
            const id = info.event.id;
            const idx = window.RIS.agenda.findIndex(a => a.id === id);
            if (idx > -1) {
                if (confirm(`¿Confirmas re-agendar la cita de ${info.event.title}?`)) {
                    const newStart = info.event.start;
                    const newMachine = info.newResource ? info.newResource.id : info.event.getResources()[0].id;
                    const estudios = window.RIS.agenda[idx].studies || [];
                    const duracion = calcularDuracionCita(newMachine, estudios.length);
                    const newEnd = new Date(newStart.getTime() + (duracion * 60000));

                    window.RIS.agenda[idx].start = toLocalISOString(newStart);
                    window.RIS.agenda[idx].end = toLocalISOString(newEnd);
                    window.RIS.agenda[idx].machine = newMachine;

                    if (window.RIS.worklist) {
                        const wlIdx = window.RIS.worklist.findIndex(w => w.id === id);
                        if (wlIdx > -1) {
                            window.RIS.worklist[wlIdx].start = window.RIS.agenda[idx].start;
                            window.RIS.worklist[wlIdx].end = window.RIS.agenda[idx].end;
                            window.RIS.worklist[wlIdx].machine = newMachine;
                        }
                    }

                    saveRISState();
                    showToast("Cita re-agendada correctamente", "success");
                    info.event.setEnd(newEnd);
                } else {
                    info.revert();
                }
            } else {
                info.revert();
            }
        },
        eventContent: function (arg) {
            const isLocked = arg.event.extendedProps.isLocked;
            const lockIcon = isLocked ? '<i class="bi bi-lock-fill text-white me-1"></i>' : '';

            return {
                html: `<div class="p-1 overflow-hidden text-truncate text-white" style="font-size: 0.85em;">
                          ${lockIcon}<strong>${arg.event.title}</strong><br>
                          <small>${arg.timeText}</small>
                       </div>`
            };
        },
        eventClick: function (info) {
            const isLocked = info.event.extendedProps.isLocked;

            if (isLocked) {
                showToast("🔒 Esta cita ya ingresó al flujo clínico y no puede ser modificada desde Recepción.", "warning");
                return;
            }
            abrirModalCita(info.event.extendedProps);
        },
        eventContent: function (arg) {
            const patient = arg.event.extendedProps.patient;
            const needsReview = arg.event.extendedProps.needsReview;

            if (!patient) return { html: `<div class="p-1">${arg.event.title}</div>` };
            const alertIcon = needsReview
                ? `<span class="blink-icon me-2 shadow-sm" title="Devuelto por Tecnólogo - Revisar" 
                         style="display: inline-flex; align-items: center; justify-content: center; 
                                width: 18px; height: 18px; background-color: red; color: white; 
                                border-radius: 50%; font-weight: 900; font-size: 13px; 
                                border: 1px solid white; flex-shrink: 0; box-shadow: 0 0 5px rgba(255,0,0,0.8);">!</span>`
                : `<i class="bi bi-person-fill me-1"></i>`;

            return {
                html: `
                <div class="d-flex flex-column justify-content-center h-100 p-2 shadow-sm text-white" style="line-height: 1.2; border-radius: 4px; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);">
                    <div class="fw-bold text-truncate text-uppercase d-flex align-items-center" style="font-size: 0.85rem;">
                        ${alertIcon} <span class="text-truncate">${arg.event.title}</span>
                    </div>
                    <div class="text-truncate opacity-100 small fw-bold mt-1">
                        <i class="bi bi-person-vcard me-1"></i>${patient.rut}
                    </div>
                </div>`
            };
        }
    });
    calendar.render();
}

function getEventsFromRIS() {
    if (!window.RIS || !window.RIS.agenda) return [];

    const colors = {
        'pre-agendado': '#8b5cf6',
        'agendado': '#10b981',
        'confirmado': '#3b82f6',
        'espera': '#f59e0b',
        'anulado': '#ef4444'
    };

    return window.RIS.agenda.map(a => {
        let estadoRaw = String(a.status).trim().toLowerCase();
        let estadoLimpio = 'agendado'; // Default

        if (estadoRaw.includes('pre')) estadoLimpio = 'pre-agendado';
        else if (estadoRaw.includes('espera')) estadoLimpio = 'espera';
        else if (estadoRaw.includes('confirmado')) estadoLimpio = 'confirmado';
        else if (estadoRaw.includes('anulado')) estadoLimpio = 'anulado';
        else if (estadoRaw.includes('agendado')) estadoLimpio = 'agendado';

        return {
            id: a.id,
            resourceId: a.machine,
            title: `${a.patient.lastName}, ${a.patient.name}`,
            start: a.start,
            end: a.end,
            backgroundColor: colors[estadoLimpio],
            borderColor: colors[estadoLimpio],
            textColor: '#ffffff',
            extendedProps: { patient: a.patient, status: estadoLimpio, needsReview: a.needsReview }
        };
    });
}

function actualizarCalendarioEnVivo() {
    if (typeof calendar !== 'undefined' && calendar) {
        loadRISState();
        calendar.refetchEvents();
    }
}

function abrirModalCita(data) {
    const $form = $("#formCita");
    if ($form.length) $form[0].reset();

    $("#studyBody").empty();
    currentInsumos = [];
    window.currentInsumosTotal = 0;
    $("#appointmentId").val(data.id || "");

    if (data.start && data.start.includes('T')) {
        $("#selectedStart").val(data.start.substring(0, 16));
    }

    if (data.id) {
        const p = data.patient || {};

        setTimeout(() => {
            $("#pRut").val(p.rut || "");
            $("#pName").val(p.name || "");
            $("#pLastName").val(p.lastName || "");
            $("#pSecondLastName").val(p.secondLastName || "");
            $("#pSex").val(p.sex || "M");
            $("#pBirthDate").val(p.birthDate || "");
            $("#pAge").val(p.age || "");
            $("#pEmail").val(p.email || "");
            $("#pPhone").val(p.phone || "");

            $("#pInsurance").val(p.insurance || "FONASA");
            $("#pPlan").val(p.plan || "");

            $("#mTratante").val(data.mTratante || "");
            $("#mDestinado").val(data.mDestinado || "");
            $("#mPriority").val(data.priority || "Normal");
            $("#mProcedencia").val(data.procedencia || "Ambulatorio");
            $("#agendaStatus").val(data.status || "agendado");

            $("#pTipoBono").val(data.tipoBono || "Sin Bono");
            $("#payMethod").val(data.payMethod || "Efectivo").trigger("change");
            $("#pEntidadPagadora").val(data.entidadPagadora || "");
            $("#pTransactionCode").val(data.transactionCode || "");

            colorSelectorEstado();
        }, 10);

        if (data.insumos) { currentInsumos = [...data.insumos]; renderInsumos(); }

        if (data.studies && data.studies.length > 0) {
            data.studies.forEach(s => {
                s.machine = s.machine || data.machine;
                addStudyRow('primo', s);
            });
        } else {
            addStudyRow('principal', { machine: data.machine });
        }

        $("#modalTitle").text("Editar Cita Médica");
        $("#btnEliminarCita").show();
    } else {
        $("#modalTitle").text("Nueva Cita Médica");
        $("#btnEliminarCita").hide();
        $("#agendaStatus").val("pre-agendado");
        addStudyRow('principal', { machine: data.machine });
        colorSelectorEstado();
    }

    $("#appointmentModal").modal('show');
}

function guardarCita() {
    const idOriginal = $("#appointmentId").val();
    const rut = $("#pRut").val();
    const statusSeleccionado = $("#agendaStatus").val();

    if (!rut || !$("#pName").val() || !$("#pLastName").val()) {
        return showToast("Faltan datos obligatorios (RUT y Apellidos)", "danger");
    }

    const estudiosPorSala = {};
    $(".study-entry").each(function () {
        const machine = $(this).find(".eMachine").val();
        if (!machine) return;
        estudiosPorSala[machine] = estudiosPorSala[machine] || [];
        estudiosPorSala[machine].push({
            machine: machine,
            exam: $(this).find(".eExam").val(),
            subExam: $(this).find(".eSubExam").val(),
            qty: parseInt($(this).find(".eQty").val()) || 1,
            code: $(this).find(".eCode").val(),
            price: parseFloat($(this).find(".ePrice").val()) || 0
        });
    });

    const personaData = {
        rut: rut, nombres: $("#pName").val(), apellidoPaterno: $("#pLastName").val(),
        apellidoMaterno: $("#pSecondLastName").val(), fechaNacimiento: $("#pBirthDate").val(),
        sexo: $("#pSex").val(), email: $("#pEmail").val(), telefono: $("#pPhone").val()
    };

    window.RIS.personas = window.RIS.personas || [];
    const pIdx = window.RIS.personas.findIndex(p => p.rut === rut);
    if (pIdx > -1) window.RIS.personas[pIdx] = { ...window.RIS.personas[pIdx], ...personaData };
    else window.RIS.personas.push(personaData);

    const snapshotPaciente = {
        rut: rut, name: $("#pName").val(), lastName: $("#pLastName").val(),
        secondLastName: $("#pSecondLastName").val(), sex: $("#pSex").val(),
        birthDate: $("#pBirthDate").val(), age: $("#pAge").val(),
        email: $("#pEmail").val(), phone: $("#pPhone").val(),
        insurance: $("#pInsurance").val(), plan: $("#pPlan").val()
    };

    const baseStart = new Date($("#selectedStart").val());
    let offsetMinutes = 0;

    let baseId = idOriginal;
    if (idOriginal && idOriginal.startsWith("APP-")) {
        const parts = idOriginal.split('-');
        baseId = `${parts[0]}-${parts[1]}`;
    } else if (!idOriginal) {
        baseId = `APP-${Date.now()}`;
    }

    const citasParaGuardar = [];
    let colisionDetectada = null;

    const salas = Object.keys(estudiosPorSala);
    for (let i = 0; i < salas.length; i++) {
        const machineId = salas[i];

        let cantEx = 0;
        estudiosPorSala[machineId].forEach(s => cantEx += s.qty);
        const duracion = calcularDuracionCita(machineId, cantEx);

        const citaStart = new Date(baseStart.getTime() + (offsetMinutes * 60000));
        const citaEnd = new Date(citaStart.getTime() + (duracion * 60000));

        const sTime = citaStart.getTime();
        const eTime = citaEnd.getTime();

        const conflicto = window.RIS.agenda.find(a => {
            if (idOriginal && a.id.startsWith(baseId)) return false;

            if (a.machine !== machineId) return false;

            const aStart = new Date(a.start).getTime();
            const aEnd = new Date(a.end).getTime();

            return (sTime < aEnd && eTime > aStart);
        });

        if (conflicto) {
            colisionDetectada = {
                sala: window.RIS.resources.find(r => r.id === machineId)?.title || machineId,
                hora: new Date(conflicto.start).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            };
            break;
        }

        const citaId = `${baseId}-${i}`;
        citasParaGuardar.push({
            id: citaId,
            start: toLocalISOString(citaStart),
            end: toLocalISOString(citaEnd),
            machine: machineId,
            status: statusSeleccionado,
            needsReview: false,
            patient: snapshotPaciente,
            studies: estudiosPorSala[machineId],
            insumos: currentInsumos || [],
            mTratante: $("#mTratante").val(),
            mDestinado: $("#mDestinado").val(),
            priority: $("#mPriority").val(),
            procedencia: $("#mProcedencia").val(),
            tipoBono: $("#pTipoBono").val(),
            payMethod: $("#payMethod").val(),
            entidadPagadora: $("#pEntidadPagadora").val(),
            transactionCode: $("#pTransactionCode").val()
        });

        offsetMinutes += duracion;
    }

    if (colisionDetectada) {
        return showToast(`Choque de horario detectado: La "${colisionDetectada.sala}" ya está ocupada alrededor de las ${colisionDetectada.hora}.`, "danger");
    }

    if (idOriginal) {
        window.RIS.agenda = window.RIS.agenda.filter(a => !a.id.startsWith(baseId));
        if (window.RIS.worklist) {
            window.RIS.worklist = window.RIS.worklist.filter(w => !w.id.startsWith(baseId));
        }
    }

    citasParaGuardar.forEach(citaData => {
        window.RIS.agenda.push(citaData);
        if (['confirmado', 'espera'].includes(statusSeleccionado)) {
            window.RIS.worklist = window.RIS.worklist || [];
            window.RIS.worklist.push({ ...citaData, status: 'waiting' });
        }
    });

    saveRISState();
    $("#appointmentId").val("");
    actualizarCalendarioEnVivo();
    $("#appointmentModal").modal('hide');
    showToast(`✅ Cita guardada como: ${statusSeleccionado.toUpperCase()}`, "success");
}

function eliminarCita() {
    const id = $("#appointmentId").val();
    if (!id) return;
    const citaActual = window.RIS.agenda.find(a => a.id === id);
    if (!citaActual) return;

    if (confirm("⚠️ ¿Estás seguro de anular esta cita?")) {
        const rutPaciente = citaActual.patient.rut;
        const fechaCita = citaActual.start.split('T')[0];

        window.RIS.agenda = window.RIS.agenda.filter(a => !(a.patient.rut === rutPaciente && a.start.split('T')[0] === fechaCita));
        if (window.RIS.worklist) {
            window.RIS.worklist = window.RIS.worklist.filter(w => !(w.patient.rut === rutPaciente && w.start.split('T')[0] === fechaCita));
        }

        saveRISState();
        actualizarCalendarioEnVivo();
        $("#appointmentModal").modal('hide');
        showToast("Cita eliminada.", "warning");
    }
}

function colorSelectorEstado() {
    const sel = $("#agendaStatus");
    const val = sel.val();
    sel.removeClass("text-success text-primary text-warning text-danger text-info border-success border-primary border-warning border-danger border-info");

    const exactColors = {
        'pre-agendado': '#8b5cf6', // Morado
        'agendado': '#10b981',     // Verde
        'confirmado': '#3b82f6',   // Azul
        'espera': '#f59e0b',       // Naranja
        'anulado': '#ef4444'       // Rojo
    };

    const color = exactColors[val] || '#64748b';

    sel.css({
        "color": color,
        "border-color": color,
        "font-weight": "bold"
    });
}
function calculateTotal() {
    let total = 0;
    $(".study-entry").each(function () {
        const p = parseFloat($(this).find(".ePrice").val()) || 0;
        const q = parseInt($(this).find(".eQty").val()) || 1;
        total += (p * q);
    });
    $("#totalCopay").text(`$${(total + (window.currentInsumosTotal || 0)).toLocaleString('es-CL')}`);
}

function renderInsumos() {
    const tbody = $("#insumosListBody");
    tbody.empty();
    window.currentInsumosTotal = 0;
    if (currentInsumos.length === 0) {
        tbody.append('<tr><td class="text-muted fst-italic py-2">Sin insumos adicionales</td></tr>');
    }
    currentInsumos.forEach((ins, idx) => {
        window.currentInsumosTotal += ins.price;
        tbody.append(`
            <tr>
                <td><i class="bi bi-dot"></i> ${ins.name}</td>
                <td class="text-end text-primary fw-bold">$${ins.price.toLocaleString()}</td>
                <td style="width:30px;" class="text-end"><button type="button" class="btn btn-sm text-danger p-0" onclick="quitarInsumo(${idx})"><i class="bi bi-x-circle-fill"></i></button></td>
            </tr>`);
    });
    calculateTotal();
}

function agregarPack(packName) {
    const pack = window.RIS.supplyPacks.find(p => p.name === packName);
    if (!pack) return;
    pack.items.forEach(itemId => {
        const supply = window.RIS.supplies.find(s => s.id === itemId);
        if (supply && supply.price > 0) currentInsumos.push({ ...supply });
    });
    renderInsumos();
}

function quitarInsumo(index) {
    currentInsumos.splice(index, 1);
    renderInsumos();
}

function escanearDocumento(tipo) {
    const isEncuesta = tipo === 'encuesta';
    const btn = isEncuesta ? $("#btnEncuesta") : $("#btnScan");
    btn.html('<span class="spinner-border spinner-border-sm me-2"></span>...').addClass("disabled");
    setTimeout(() => {
        if (isEncuesta) {
            $("#docEncuesta").val("pdf_encuesta_" + Date.now());
            showToast("📄 Encuesta anexada.", "info");
            btn.removeClass("disabled btn-light").addClass("btn-info text-white").html('<i class="bi bi-check2"></i> OK');
        } else {
            $("#docOrdenMedica").val("pdf_orden_" + Date.now());
            showToast("📄 Orden vinculada.", "success");
            btn.removeClass("disabled btn-light").addClass("btn-success").html('<i class="bi bi-check2"></i> OK');
        }
    }, 1500);
}

function addStudyRow(relationType = 'primo', existingData = null) {
    const rowId = 'row-' + Date.now();
    const machineOptions = window.RIS.resources.map(res =>
        `<option value="${res.id}" ${(existingData && existingData.machine === res.id) ? 'selected' : ''}>${res.title}</option>`
    ).join('');

    const html = `
        <tr id="${rowId}" class="study-entry align-middle">
            <td><select class="form-select form-select-sm eMachine"><option value="">Sala...</option>${machineOptions}</select></td>
            <td><select class="form-select form-select-sm eExam"><option value="">--</option></select></td>
            <td><select class="form-select form-select-sm eSubExam"><option value="">--</option></select></td>
            <td><input type="number" class="form-control form-control-sm eQty text-center" value="${existingData ? existingData.qty || 1 : 1}" min="1"></td>
            <td><input type="text" class="form-control form-control-sm eCode text-center bg-white" value="${existingData ? existingData.code || '' : ''}" readonly tabindex="-1"></td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">$</span>
                    <input type="number" class="form-control form-control-sm ePrice" value="${existingData ? existingData.price || 0 : 0}">
                </div>
            </td>
            <td class="text-center"><button type="button" onclick="$('#${rowId}').remove(); calculateTotal();" class="btn btn-sm text-danger p-0"><i class="bi bi-trash fs-5"></i></button></td>
        </tr>`;
    $("#studyBody").append(html);

    const newRow = $(`#${rowId}`);
    if (existingData) {
        newRow.find(".eMachine").trigger("change");
        setTimeout(() => {
            newRow.find(".eExam").val(existingData.exam).trigger("change");
            setTimeout(() => { newRow.find(".eSubExam").val(existingData.subExam); }, 50);
        }, 50);
    }
}

function loadProMasterData() {
    $("#mTratante, #mDestinado").empty().append('<option value="">- Seleccionar Médico -</option>');
    (window.RIS.doctors || ["Dr. Arriagada", "Dra. Sánchez", "Dr. Pérez"]).forEach(d => {
        $("#mTratante").append(`<option value="${d}">${d}</option>`);
        $("#mDestinado").append(`<option value="${d}">${d}</option>`);
    });

    const insumosCobrados = (window.RIS.supplies || []).filter(s => s.price > 0);
    const select = $("#insumoIndividualSelect");
    select.empty().append('<option value="">+ Agregar insumo individual...</option>');
    insumosCobrados.forEach(s => select.append(`<option value="${s.id}">${s.name} ($${s.price.toLocaleString()})</option>`));

    select.on("change", function () {
        if (!this.value) return;
        const sup = window.RIS.supplies.find(s => s.id == this.value);
        if (sup) { currentInsumos.push({ ...sup }); renderInsumos(); }
        $(this).val("");
    });

    const btnContainer = $("#insumosButtons");
    btnContainer.empty();
    (window.RIS.supplyPacks || []).forEach(p => {
        btnContainer.append(`<button type="button" class="btn btn-sm btn-outline-secondary shadow-sm me-1 mb-1" onclick="agregarPack('${p.name}')"><i class="bi bi-box-seam"></i> ${p.name}</button>`);
    });
}

function setupProEventListeners() {
    $("#agendaStatus").on("change", colorSelectorEstado);
    $(document).on("input", ".eQty", calculateTotal);
    window.addEventListener('ris_updated', actualizarCalendarioEnVivo);
    window.addEventListener('storage', (e) => { if (e.key === 'ris_app_data') actualizarCalendarioEnVivo(); });

    $("#pBirthDate").on("change", function () {
        const bd = new Date($(this).val());
        if (isNaN(bd)) return;
        const today = new Date();
        let age = today.getFullYear() - bd.getFullYear();
        if (today.getMonth() < bd.getMonth() || (today.getMonth() === bd.getMonth() && today.getDate() < bd.getDate())) age--;
        $("#pAge").val(age);
    });

    $("#payMethod").on("change", function () {
        const val = $(this).val();
        let lbl = "Cód. Transacción";
        if (val === "Cheque") lbl = "N° Cheque";
        if (val === "Tarjeta") lbl = "Cód. ISWITCH";
        if (val === "Transbank") lbl = "Número de Operación";
        $("#lblTransactionCode").text(lbl);
    });

    $(document).on("change", ".ePrice", function () {
        const row = $(this).closest("tr");
        const originalPrice = parseFloat(row.data("original-price")) || 0;
        const newPrice = parseFloat($(this).val()) || 0;
        if (originalPrice > 0 && newPrice !== originalPrice) {
            const motivo = prompt("Justifique el cambio de precio arancelario:");
            if (motivo) {
                $(this).addClass("bg-success text-white border-success").attr("title", "Cambio justificado: " + motivo);
                row.data("motivo-cambio", motivo);
            } else {
                $(this).val(originalPrice).removeClass("bg-success text-white border-success").removeAttr("title");
                row.data("motivo-cambio", "");
            }
        }
        calculateTotal();
    });

    $("#searchAgenda").on("input", function () {
        const term = $(this).val().toLowerCase().replace(/[^a-z0-9k]/g, '');
        const allEvents = getEventsFromRIS();
        calendar.getEventSources().forEach(src => src.remove());
        if (term === "") calendar.addEventSource(allEvents);
        else {
            const filtered = allEvents.filter(e => e.title.toLowerCase().includes(term) || e.extendedProps.patient.rut.toLowerCase().replace(/[^a-z0-9k]/g, '').includes(term));
            calendar.addEventSource(filtered);
        }
    });

    $("#pRut").on("input", function () {
        let actual = $(this).val().replace(/[^0-9kK]/g, '');
        if (actual.length === 0) { $(this).val(""); return; }
        let rutPuntos = ""; let cuerpo = actual.slice(0, -1); let dv = actual.slice(-1).toUpperCase();
        for (let i = cuerpo.length - 1, j = 1; i >= 0; i--, j++) {
            rutPuntos = cuerpo.charAt(i) + rutPuntos;
            if (j % 3 === 0 && i !== 0) rutPuntos = "." + rutPuntos;
        }
        $(this).val(cuerpo.length > 0 ? rutPuntos + "-" + dv : dv);
    });

    $("#pRut").on("blur", function () {
        let rut = $(this).val().toUpperCase();
        if (!rut) return;
        if (!validarRut(rut)) { showToast("❌ RUT Inválido", "danger"); $(this).addClass("is-invalid"); return; }
        $(this).removeClass("is-invalid").addClass("is-valid");

        const personaMatriz = (window.RIS.personas || []).find(p => p.rut.toUpperCase() === rut);
        if (personaMatriz) {
            $("#pName").val(personaMatriz.nombres);
            $("#pLastName").val(personaMatriz.apellidoPaterno);
            $("#pSecondLastName").val(personaMatriz.apellidoMaterno);
            $("#pBirthDate").val(personaMatriz.fechaNacimiento).trigger("change");
            $("#pSex").val(personaMatriz.sexo);
            $("#pEmail").val(personaMatriz.email);
            $("#pPhone").val(personaMatriz.telefono);
            showToast("✅ Paciente cargado de base de datos.", "success");
        }
    });

    $(document).on("change", ".eMachine", function () {
        const row = $(this).closest("tr");
        const machineId = $(this).val();
        const examSelect = row.find(".eExam");
        const subSelect = row.find(".eSubExam");
        examSelect.empty().append('<option value="">Seleccione...</option>');
        subSelect.empty().append('<option value="">--</option>');
        row.find(".eCode, .ePrice").val("");

        const sala = window.RIS.resources.find(r => r.id === machineId);
        if (!sala) return;
        const catalogKey = window.RIS.groupMap[sala.group];
        if (!catalogKey || !window.RIS.examTypes[catalogKey]) return;

        Object.keys(window.RIS.examTypes[catalogKey].exams).forEach(e => {
            examSelect.append(`<option value="${e}">${e}</option>`);
        });
        row.data("catalog-key", catalogKey);
    });

    $(document).on("change", ".eExam", function () {
        const row = $(this).closest("tr");
        const catalogKey = row.data("catalog-key");
        const examName = $(this).val();
        const subSelect = row.find(".eSubExam");
        subSelect.empty().append('<option value="">--</option>');
        row.find(".eCode, .ePrice").val("").removeClass("bg-success text-white border-success");

        if (!examName || !window.RIS.examTypes[catalogKey]) return;
        const examData = window.RIS.examTypes[catalogKey].exams[examName];

        if (examData.subs && examData.subs.length > 0) examData.subs.forEach(sub => subSelect.append(`<option value="${sub}">${sub}</option>`));
        if (examData.price) {
            row.find(".eCode").val(examData.code || "");
            row.find(".ePrice").val(examData.price);
            row.data("original-price", examData.price);
        }
        calculateTotal();
    });
}