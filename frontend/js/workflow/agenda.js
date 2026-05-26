/* =========================================
   MÓDULO DE AGENDA Y RECEPCIÓN (agenda.js)
   ========================================= */

let calendar;
let currentInsumos = [];
let catalogosAgenda = {};
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

async function initAgenda() {
    window.RIS = window.RIS || {
        agenda: [],
        config: {},
        resources: [],
        tiemposPorGrupo: { RX: 15, TC: 30, RM: 45, US: 20, MG: 15 },
        doctors: [],
        supplies: [],
        supplyPacks: []
    };

    const calendarEl = document.getElementById('calendar');
    if (!calendarEl) {
        console.error('Agenda: no se encontró #calendar en la página.');
        return;
    }

    if (typeof initPaymentManager === 'function') initPaymentManager();

    const catalogosOk = await cargarCatalogosDesdeBD();
    if (!catalogosOk) {
        showToast('No se pudieron cargar salas y catálogos. Revise el laboratorio seleccionado.', 'warning');
    }

    setupCalendar(calendarEl);
    await cargarAgendaDesdeServidor();

    if (!window._agendaListenersBound) {
        setupProEventListeners();
        window._agendaListenersBound = true;
    }
}
async function cargarCatalogosDesdeBD() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/agenda-catalogs`, {
            headers: {
                'Authorization': `Bearer ${token}`,
                'Accept': 'application/json',
                'X-Lab-Id': labId || ''
            }
        });
        const data = await response.json();

        if (response.ok && data.success) {
            catalogosAgenda = data.data;

            window.RIS.resources = (catalogosAgenda.machines || []).map(m => ({
                id: String(m.id),
                title: m.name,
                group: m.group_code || m.group || 'General'
            }));

            window.RIS.supplies = catalogosAgenda.supplies || [];
            window.RIS.supplyPacks = catalogosAgenda.supply_packs || [];

            if (calendar) {
                calendar.getResources().forEach(res => res.remove());
                window.RIS.resources.forEach(res => calendar.addResource(res));
            }

            poblarSelectsAgenda();
            configurarInsumosAgenda();
            return true;
        }

        console.error('Catálogos agenda:', response.status, data);
        return false;
    } catch (e) {
        console.error("Error en catálogos:", e);
        return false;
    }
}
function poblarSelectsAgenda() {
    const selectTratante = $("#mTratante");
    selectTratante.empty().append('<option value="">Seleccione o escriba...</option>');
    selectTratante.append('<option value="NUEVO" class="fw-bold text-success">➕ Agregar Nuevo Médico...</option>');
    catalogosAgenda.referring_doctors.forEach(doc => {
        selectTratante.append(`<option value="${doc.id}">${doc.names} ${doc.last_name_1}</option>`);
    });

    const selectDestinado = $("#mDestinado");
    selectDestinado.empty().append('<option value="">Seleccione Radiólogo...</option>');
    catalogosAgenda.destination_doctors.forEach(doc => {
        const p = doc.persona || {};
        selectDestinado.append(`<option value="${doc.id}">Dr(a). ${p.names} ${p.last_name_1}</option>`);
    });

    const selectPrevision = $("#pInsurance");
    selectPrevision.empty().append('<option value="">Seleccione Previsión...</option>');
    catalogosAgenda.insurances.forEach(ins => {
        selectPrevision.append(`<option value="${ins.id}">${ins.name}</option>`);
    });

    const selectInsumos = $("#addInsumoSelect");
    if (selectInsumos.length) {
        selectInsumos.empty().append('<option value="">Seleccione insumo...</option>');
        catalogosAgenda.supplies.forEach(sup => {
            selectInsumos.append(`<option value="${sup.id}" data-price="${sup.price}">${sup.name} ($${sup.price})</option>`);
        });
    }

    const btnContainer = $("#insumosButtons");
    if (btnContainer.length && catalogosAgenda.supply_packs) {
        btnContainer.empty();
        catalogosAgenda.supply_packs.forEach(pack => {
            btnContainer.append(`
            <button type="button" 
                    class="btn btn-sm btn-outline-primary shadow-sm me-1 mb-1" 
                    onclick="agregarPack('${pack.id}')">
                <i class="bi bi-box-seam me-1"></i>${pack.name}
            </button>
        `);
        });
    }
}

function normalizarDuracionFC(valor) {
    if (!valor) return '00:15';
    const partes = String(valor).split(':');
    if (partes.length >= 2) return `${partes[0]}:${partes[1]}`;
    return valor;
}

function setupCalendar(el) {
    if (typeof FullCalendar === 'undefined') {
        console.error('FullCalendar no está cargado. Revise los scripts en layout.html.');
        showToast('Error: librería de calendario no cargada.', 'danger');
        return;
    }

    if (calendar) {
        calendar.destroy();
        calendar = null;
    }

    const configRIS = window.RIS.config || { horaInicio: '08:00:00', horaFin: '20:00:00', intervalo: '00:15:00' };
    const recursosData = (window.RIS && window.RIS.resources) ? window.RIS.resources : [];
    const slotDur = normalizarDuracionFC(configRIS.intervalo);

    try {
    calendar = new FullCalendar.Calendar(el, {
        schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source',
        locale: 'es',
        timeZone: 'local',
        initialView: 'resourceTimelineDay',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'resourceTimelineDay,resourceTimelineWeek'
        },
        views: {
            resourceTimelineWeek: {
                type: 'resourceTimeline',
                duration: { weeks: 1 },
                slotDuration: slotDur
            }
        },
        resourceAreaWidth: '15%',
        resourceAreaHeaderContent: 'Salas',
        allDaySlot: false,
        slotMinTime: configRIS.horaInicio || '08:00:00',
        slotMaxTime: configRIS.horaFin || '20:00:00',
        slotDuration: slotDur,
        eventOverlap: false,
        selectOverlap: false,
        resources: recursosData,
        events: [],
        selectable: true,
        editable: true,
        eventResourceEditable: true,
        droppable: true,
        slotMinWidth: 120,
        select: function (info) {
            abrirModalCita({ start: info.startStr, machine: info.resource ? info.resource.id : null });
        },
        eventClick: function (info) {
            const estadosIniciales = ['pre-agendado', 'agendado', 'confirmado', 'espera'];
            const status = info.event.extendedProps.status || '';
            const isLocked = !estadosIniciales.includes(status);

            if (isLocked) {
                showToast("🔒 Esta cita ya ingresó al flujo clínico y no puede ser modificada desde Recepción.", "warning");
                return;
            }

            const appointment = window.RIS.agenda.find(a => a.id === info.event.id);
            if (appointment) abrirModalCita(appointment);
        },

        eventDrop: async function (info) {
            const id = info.event.id;
            const newStart = info.event.start;
            const duracionActual = info.event.end ? (info.event.end.getTime() - info.oldEvent.start.getTime()) : (15 * 60000);
            const newEnd = info.event.end || new Date(newStart.getTime() + duracionActual);
            const newMachine = info.newResource ? info.newResource.id : info.event.getResources()[0].id;

            if (!(await showConfirm(`¿Confirmas re-agendar la cita de ${info.event.title}?`, { title: "Re-agendar cita" }))) {
                info.revert();
                return;
            }

            const token = localStorage.getItem('ris_token');
            const labId = localStorage.getItem('ris_lab_id');

            try {
                const response = await fetch(`${API_URL}/appointments/${id}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
                    body: JSON.stringify({
                        is_drag_and_drop: true,
                        start_time: toLocalISOString(newStart),
                        end_time: toLocalISOString(newEnd),
                        machine_id: newMachine
                    })
                });

                if (!response.ok) throw new Error("Error en el servidor");
                showToast("Cita y exámenes asociados re-agendados correctamente", "success");
                cargarAgendaDesdeServidor();
            } catch (error) {
                info.revert();
                showToast("Error al mover la cita", "danger");
            }
        },
        eventContent: function (arg) {
            const props = arg.event.extendedProps;
            const patient = props.patient;
            const needsReview = props.needsReview;

            const estadosIniciales = ['pre-agendado', 'agendado', 'confirmado', 'espera'];
            const isLocked = !estadosIniciales.includes(props.status);
            const lockIcon = isLocked ? '<i class="bi bi-lock-fill text-white me-1"></i>' : '';

            const bgColor = arg.event.backgroundColor || '#3788d8';

            if (!patient) return { html: `<div class="p-1" style="background-color:${bgColor}; color:white; border-radius:3px;">${lockIcon}${arg.event.title}</div>` };

            const alertIcon = needsReview
                ? `<span class="blink-icon me-2 shadow-sm" title="Devuelto por Tecnólogo - Revisar" 
                         style="display: inline-flex; align-items: center; justify-content: center; 
                                width: 18px; height: 18px; background-color: red; color: white; 
                                border-radius: 50%; font-weight: 900; font-size: 13px; 
                                border: 1px solid white; flex-shrink: 0; box-shadow: 0 0 5px rgba(255,0,0,0.8);">!</span>`
                : `<i class="bi bi-person-fill me-1"></i>`;

            return {
                html: `
                <div class="d-flex flex-column justify-content-center h-100 p-1 shadow-sm text-white" 
                     style="line-height: 1.2; border-radius: 4px; background-color: ${bgColor}; border-left: 4px solid rgba(255,255,255,0.4);">
                    
                    <div class="fw-bold text-truncate text-uppercase d-flex align-items-center" style="font-size: 0.85rem; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);">
                        ${alertIcon} ${lockIcon} <span class="text-truncate">${arg.event.title}</span>
                    </div>
                    
                    <div class="text-truncate opacity-100 fw-bold mt-1" style="font-size: 0.75rem;">
                        <i class="bi bi-person-vcard me-1"></i>${patient.rut || ''}
                    </div>
                </div>`
            };
        }
    });

    calendar.render();
    } catch (err) {
        console.error('Error inicializando FullCalendar:', err);
        showToast('No se pudo dibujar el calendario. Recargue la página (Ctrl+F5).', 'danger');
    }
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
        let estadoLimpio = 'agendado';

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

async function cargarAgendaDesdeServidor() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/appointments`, {
            headers: {
                'Accept': 'application/json',
                'Authorization': `Bearer ${token}`,
                'X-Lab-Id': labId
            }
        });
        const data = await response.json();

        if (response.ok && data.success) {
            window.RIS.agenda = data.data.map(app => {
                const p = app.patient?.persona || {};
                const estudios = app.studies || [];
                const salasUnicas = [...new Set(estudios.map(s => String(s.machine_id)))];
                if (salasUnicas.length === 0 && app.machine_id) salasUnicas.push(String(app.machine_id));
                return {
                    id: String(app.id),
                    machine: String(app.machine_id),
                    resourceIds: salasUnicas,
                    start: app.start_time.split('.')[0],
                    end: app.end_time.split('.')[0],
                    status: app.status || 'pre-agendado',
                    needsReview: app.needs_review || false,
                    returnReason: app.return_reason || '',
                    title: `${p.names || 'Paciente'} ${p.last_name_1 || ''}`,
                    mTratante: app.referring_doctor_id,
                    mDestinado: app.destination_doctor_id,
                    priority: app.priority,
                    procedencia: app.origin,
                    payMethod: app.payment_method,
                    paymentStatus: app.payment_status || 'Pendiente',
                    transactionCode: app.transaction_code,
                    tipoBono: app.tipo_bono,
                    entidadPagadora: app.entidad_pagadora,
                    patient: {
                        rut: p.rut,
                        name: p.names,
                        lastName: p.last_name_1,
                        secondLastName: p.last_name_2,
                        sex: p.gender,
                        birthDate: p.birth_date,
                        email: p.email,
                        phone: p.phone,
                        insurance: app.insurance_id,
                        plan: app.insurance_plan_id
                    },
                    studies: (app.studies || []).map(s => ({
                        machine: String(s.machine_id),
                        exam: s.exam_id,
                        examName: s.exam_name,
                        subExam: s.sub_exam_id,
                        qty: s.quantity,
                        code: s.fonasa_code,
                        price: parseFloat(s.price_charged ?? s.price) || 0
                    }))
                };
            });

            if (typeof calendar !== 'undefined' && calendar) {
                calendar.getEventSources().forEach(src => src.remove());

                const eventsForCalendar = window.RIS.agenda.map(item => {
                    const colorEstado = getHexColorEstado(item.status);

                    return {
                        ...item,
                        resourceId: String(item.machine),
                        color: colorEstado,
                        display: 'block',
                        textColor: '#ffffff'
                    };
                });

                calendar.addEventSource(eventsForCalendar);
            }
        }
    } catch (error) {
        console.error("Error cargando agenda real:", error);
        showToast("🔌 Error de conexión con el servidor", "danger");
    }
}

function actualizarCalendarioEnVivo() {
    cargarAgendaDesdeServidor();
}

function abrirModalCita(data) {
    const $form = $("#formCita");
    const labTypeId = parseInt(localStorage.getItem('ris_lab_type_id')) || 1;

    if (labTypeId === 3) {
        $("#pInsurance").closest('.col-md-3').addClass('d-none');
        $("#pPlan").closest('.col-md-3').addClass('d-none');

        $("#pInsurance").val("");
        $("#pPlan").val("");
    } else {
        $("#pInsurance").closest('.col-md-3').removeClass('d-none');
        $("#pPlan").closest('.col-md-3').removeClass('d-none');
    }
    if ($form.length) $form[0].reset();

    $("#studyBody").empty();
    currentInsumos = [];
    window.currentInsumosTotal = 0;
    $("#appointmentId").val(data.id || "");
    $("#alertDevolucion").remove();

    if (data.needsReview) {
        const alertHtml = `
            <div id="alertDevolucion" class="alert border-danger bg-danger-subtle shadow-sm mb-4 d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold text-danger mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> ATENCIÓN: Paciente Devuelto por Tecnólogo</h6>
                    <p class="mb-0 text-dark small"><strong>Motivo:</strong> ${data.returnReason}</p>
                </div>
                <button type="button" class="btn btn-sm btn-danger fw-bold shadow-sm" onclick="marcarComoRevisado('${data.id}')">
                    <i class="bi bi-check2-all me-1"></i> Marcar como Leído
                </button>
            </div>
        `;
        $("#formCita").prepend(alertHtml);
    }
    if (data.start) {
        const isoStart = String(data.start).includes('T') ? data.start : data.start.replace(' ', 'T');
        $("#selectedStart").val(isoStart.substring(0, 16));
    }

    if (data.id) {
        const p = data.patient || {};

        $("#pRut").val(p.rut || "");
        $("#pName").val(p.name || "");
        $("#pLastName").val(p.lastName || "");
        $("#pSecondLastName").val(p.secondLastName || "");
        $("#pSex").val(p.sex || "M");
        $("#pBirthDate").val(p.birthDate || "").trigger("change");
        $("#pEmail").val(p.email || "");
        $("#pPhone").val(p.phone || "");

        if (p.insurance) {
            $("#pInsurance").val(p.insurance).trigger("change");
            setTimeout(() => {
                $("#pPlan").val(p.plan || "");
            }, 100);
        }

        $("#agendaStatus").val(data.status || "pre-agendado").trigger("change");
        $("#mTratante").val(data.mTratante || "");
        $("#mDestinado").val(data.mDestinado || "");
        $("#mProcedencia").val(data.procedencia || "Ambulatorio");
        $("#mPriority").val(data.priority || "Normal");

        $("#pTipoBono").val(data.tipoBono || "Sin Bono");
        $("#payMethod").val(data.payMethod || "Efectivo").trigger("change");
        $("#paymentStatus").val(data.paymentStatus || "Pendiente");
        $("#pEntidadPagadora").val(data.entidadPagadora || "");
        $("#pTransactionCode").val(data.transactionCode || "");

        if (data.supplies && data.supplies.length > 0) {
            currentInsumos = [...data.supplies];
        }
        renderInsumos();

        if (data.studies && data.studies.length > 0) {
            data.studies.forEach(s => {
                addStudyRow('primo', {
                    machine: s.machine || data.machine,
                    exam: s.exam,
                    subExam: s.subExam,
                    qty: s.qty,
                    code: s.code,
                    price: s.price
                });
            });
        } else {
            addStudyRow('principal', { machine: data.machine });
        }

        $("#modalTitle").html('<i class="bi bi-pencil-square me-2"></i>Editar Cita Médica');
        $("#btnEliminarCita").show();

    } else {
        $("#modalTitle").html('<i class="bi bi-calendar-plus me-2"></i>Nueva Cita Médica');
        $("#btnEliminarCita").hide();
        $("#agendaStatus").val("pre-agendado").trigger("change");

        addStudyRow('principal', { machine: data.machine });
        renderInsumos();
    }

    const appointmentId = $("#appointmentId").val();
    $("#btnRegistrarPago").toggle(!!appointmentId);
    if (appointmentId && window.paymentManager) {
        window.paymentManager.cargarDesglose(appointmentId);
        window.paymentManager.cargarHistorialPagos(appointmentId);
    }

    initAgendaWizard();
    openModal("appointmentModal");
}

async function guardarNuevoMedico() {
    const payload = {
        rut: $("#newDocRut").val(),
        names: $("#newDocNames").val(),
        last_name_1: $("#newDocLastNames").val(),
        email: $("#newDocEmail").val()
    };

    const response = await fetch(`${API_URL}/referring-doctors`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${localStorage.getItem('ris_token')}` },
        body: JSON.stringify(payload)
    });

    if (response.ok) {
        const data = await response.json();
        $("#mTratante").append(`<option value="${data.data.id}" selected>${data.data.names} ${data.data.last_name_1}</option>`);
        $("#modalNuevoMedico").modal('hide');
        showToast("Médico registrado y vinculado a Keycloak", "success");
    }
}

async function guardarCita() {
    const idOriginal = $("#appointmentId").val();
    const rut = $("#pRut").val();
    const statusSeleccionado = $("#agendaStatus").val();
    validarDocumentoAgenda();
    if (!rut || !$("#pName").val() || !$("#pLastName").val()) {
        return showToast("Faltan datos obligatorios (RUT y Apellidos)", "danger");
    }

    const todosLosEstudios = [];
    const salasInvolucradas = new Set();

    $(".study-entry").each(function () {
        const machine = $(this).find(".eMachine").val();
        if (!machine) return;

        salasInvolucradas.add(machine);
        const subExamVal = $(this).find(".eSubExam").val();

        todosLosEstudios.push({
            machine_id: machine,
            exam_id: $(this).find(".eExam").val(),
            exam_name: $(this).find(".eExam option:selected").text().trim(),
            sub_exam_name: $(this).find(".eSubExam option:selected").text().replace('--', '').trim() || null,
            sub_exam_id: (subExamVal && subExamVal !== "-") ? subExamVal : null,
            fonasa_code: $(this).find(".eCode").val() || null,
            quantity: parseInt($(this).find(".eQty").val()) || 1,
            price: parseFloat($(this).find(".ePrice").val()) || 0
        });
    });

    if (todosLosEstudios.length === 0) {
        return showToast("Debe agregar al menos un examen con su sala.", "warning");
    }

    const snapshotPaciente = {
        rut: rut,
        names: $("#pName").val(),
        last_name_1: $("#pLastName").val(),
        last_name_2: $("#pSecondLastName").val(),
        gender: $("#pSex").val(),
        birth_date: $("#pBirthDate").val(),
        email: $("#pEmail").val(),
        phone: $("#pPhone").val(),
        insurance_id: $("#pInsurance").val() || null,
        insurance_plan_id: $("#pPlan").val() || null
    };

    let duracionTotalMinutos = 0;
    salasInvolucradas.forEach(machineId => {
        const cantEnSala = todosLosEstudios.filter(s => s.machine_id === machineId).reduce((sum, s) => sum + s.quantity, 0);
        duracionTotalMinutos += calcularDuracionCita(machineId, cantEnSala);
    });

    const citaStart = new Date($("#selectedStart").val());
    const citaEnd = new Date(citaStart.getTime() + (duracionTotalMinutos * 60000));

    let colisionDetectada = null;
    salasInvolucradas.forEach(machineId => {
        const conflicto = (window.RIS.agenda || []).find(a => {
            if (idOriginal && String(a.id) === String(idOriginal)) return false;
            if (!a.resourceIds.includes(machineId)) return false;

            const aStart = new Date(a.start).getTime();
            const aEnd = new Date(a.end).getTime();
            return (citaStart.getTime() < aEnd && citaEnd.getTime() > aStart);
        });

        if (conflicto && !colisionDetectada) {
            colisionDetectada = {
                sala: window.RIS.resources.find(r => r.id === machineId)?.title || machineId,
                hora: new Date(conflicto.start).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            };
        }
    });

    if (colisionDetectada) {
        return showToast(`Choque de horario: La sala "${colisionDetectada.sala}" está ocupada a las ${colisionDetectada.hora}.`, "danger");
    }

    const payloadCitaGlobal = {
        start_time: toLocalISOString(citaStart),
        end_time: toLocalISOString(citaEnd),
        machine_id: Array.from(salasInvolucradas)[0],
        status: statusSeleccionado,
        patient: snapshotPaciente,
        studies: todosLosEstudios,
        supplies: (currentInsumos || []).map(ins => ({ id: ins.id, quantity: ins.quantity || 1, price: ins.price })),
        referring_doctor_id: $("#mTratante").val() || null,
        destination_doctor_id: $("#mDestinado").val() || null,
        priority: $("#mPriority").val(),
        origin: $("#mProcedencia").val(),
        tipo_bono: $("#pTipoBono").val(),
        payment_method: $("#payMethod").val(),
        entidad_pagadora: $("#pEntidadPagadora").val(),
        transaction_code: $("#pTransactionCode").val(),
        payment_status: $("#paymentStatus").val(),
    };

    const formData = new FormData();
    formData.append('data', JSON.stringify(payloadCitaGlobal));

    // Archivos Nativos adjuntos
    const fileOrden = $('#fileOrdenMedica')[0].files[0];
    if (fileOrden) formData.append('order_file', fileOrden);

    const fileEncuesta = $('#fileEncuesta')[0].files[0];
    if (fileEncuesta) formData.append('survey_file', fileEncuesta);

    const btnGuardar = $("#btnGuardarCita");
    btnGuardar.prop('disabled', true);

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    let url = `${API_URL}/appointments`;
    let method = 'POST';

    if (idOriginal) {
        // Si hay idOriginal, significa que estamos editando una cita existente
        url = `${API_URL}/appointments/${idOriginal}`;
        // Para enviar archivos (FormData) en edición, Laravel requiere POST + _method
        formData.append('_method', 'PUT');
    }

    try {
        const response = await fetch(url, {
            method: method,
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
            body: formData
        });

        if (!response.ok) throw new Error(await response.text());

        const data = await response.json();
        closeModal("appointmentModal");

        showToast("Cita guardada correctamente.", "success");

        if (!idOriginal && data.instructions_email) {
            const mail = data.instructions_email;
            if (mail.sent) {
                showToast(`📧 Instrucciones enviadas a ${mail.email} (${mail.count} examen${mail.count > 1 ? 'es' : ''}).`, "info");
            } else if (payloadCitaGlobal.origin !== 'Ambulatorio') {
                const msgs = {
                    sin_correo: 'El paciente no tiene correo registrado.',
                    sin_instrucciones: 'Los exámenes agendados no tienen instrucciones configuradas.',
                    error_envio: 'No se pudieron enviar las instrucciones por correo.'
                };
                if (msgs[mail.reason]) {
                    showToast(`⚠️ ${msgs[mail.reason]}`, "warning");
                }
            }
        }

        // IMPRESIÓN DEL COMPROBANTE
        if (await showConfirm("¿Desea imprimir el comprobante para el paciente?", { title: "Imprimir comprobante", confirmText: "Imprimir" })) {
            imprimirComprobantePaciente(payloadCitaGlobal);
        }

        cargarAgendaDesdeServidor();
    } catch (error) {
        showToast(`Error al guardar: ${error.message}`, "danger");
    } finally {
        btnGuardar.prop('disabled', false);
    }
}

function imprimirComprobantePaciente(data) {
    const printWindow = window.open('', '_blank', 'width=400,height=600');
    const html = `
        <html><head><title>Comprobante de Atención</title>
        <style>
            body { font-family: monospace; text-align: center; padding: 20px; }
            .ticket { border: 1px dashed #000; padding: 15px; display: inline-block; width: 300px; text-align: left; }
            h2 { margin-bottom: 5px; text-align: center; }
            .sep { border-top: 1px dashed #ccc; margin: 10px 0; }
        </style>
        </head><body>
        <div class="ticket">
            <h2>HealthTiCloud RIS</h2>
            <div class="sep"></div>
            <b>Paciente:</b> ${data.patient.names} ${data.patient.last_name_1}<br>
            <b>RUT:</b> ${data.patient.rut}<br>
            <b>Fecha Cita:</b> ${new Date(data.start_time).toLocaleString('es-CL')}<br>
            <div class="sep"></div>
            <b>Exámenes a realizar:</b><br>
            ${data.studies.map(s => `- ${s.exam_name}`).join('<br>')}<br>
            <div class="sep"></div>
            <b>Total a Pagar:</b> $${$("#totalCopay").text().replace('$', '')}<br>
            <b>Estado:</b> ${data.payment_status}<br>
            <div class="sep"></div>
            <p style="font-size:11px; text-align:justify;">Recuerde llegar 15 minutos antes. Traer exámenes previos.</p>
        </div>
        <script>setTimeout(() => { window.print(); window.close(); }, 500);</script>
        </body></html>
    `;
    printWindow.document.write(html);
    printWindow.document.close();
}
async function eliminarCita() {
    const id = $("#appointmentId").val();
    if (!id || String(id).startsWith('APP-')) return;

    if (!(await showConfirm("¿Estás seguro de anular esta cita? Quedará registro en la auditoría.", { title: "Anular cita", dangerous: true, confirmText: "Anular" }))) return;

        const token = localStorage.getItem('ris_token');
        const labId = localStorage.getItem('ris_lab_id');
        try {
            const response = await fetch(`${API_URL}/appointments/${id}`, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
            });

            if (response.ok) {
                closeModal("appointmentModal");
                showToast("Cita anulada correctamente.", "warning");
                cargarAgendaDesdeServidor();
            }
        } catch (e) {
            showToast("Error al intentar anular la cita", "danger");
        }
}

function getHexColorEstado(status) {
    const exactColors = {
        'pre-agendado': '#8b5cf6', // Morado
        'agendado': '#10b981',     // Verde
        'confirmado': '#3b82f6',   // Azul
        'espera': '#f59e0b',       // Naranja
        'anulado': '#ef4444'       // Rojo
    };
    return exactColors[status ? status.toLowerCase() : ''] || '#64748b';
}

function colorSelectorEstado() {
    const sel = $("#agendaStatus");
    const val = sel.val();
    sel.removeClass("text-success text-primary text-warning text-danger text-info border-success border-primary border-warning border-danger border-info");

    const color = getHexColorEstado(val);

    sel.css({
        "color": color,
        "border-color": color,
        "font-weight": "bold"
    });
}

function calculateTotal() {
    let subtotalExamenes = 0;
    $(".study-entry").each(function () {
        const p = parseFloat($(this).find(".ePrice").val()) || 0;
        const q = parseInt($(this).find(".eQty").val()) || 1;
        subtotalExamenes += (p * q);
    });

    let subtotalInsumos = window.currentInsumosTotal || 0;

    let porcentajeDescuento = 0;
    const insId = $("#pInsurance").val();
    const planId = $("#pPlan").val();

    if (insId && planId && catalogosAgenda.insurances) {
        const seguro = catalogosAgenda.insurances.find(i => String(i.id) === String(insId));
        const plan = seguro?.plans?.find(p => String(p.id) === String(planId));
        porcentajeDescuento = parseFloat(plan?.percentage) || 0;
    }

    const montoDescuentoExamenes = subtotalExamenes * (porcentajeDescuento / 100);

    const totalFinal = (subtotalExamenes - montoDescuentoExamenes) + subtotalInsumos;

    let textoTotal = `$${Math.round(totalFinal).toLocaleString('es-CL')}`;
    if (porcentajeDescuento > 0) {
        textoTotal += ` <span class="badge bg-success ms-2" style="font-size:0.7rem;">Copago aplicado</span>`;
    }
    $("#percentageInsurance").val(porcentajeDescuento);
    $("#totalCopay").html(textoTotal);
    if (window.paymentManager && typeof window.paymentManager.actualizarDesglosePrecios === 'function') {
        window.paymentManager.actualizarDesglosePrecios();
    }
}

async function registrarPagoDesdeAgenda() {
    const appointmentId = $("#appointmentId").val();
    if (!appointmentId) {
        return showToast('Guarde la cita antes de registrar un pago en caja.', 'warning');
    }
    if (!window.paymentManager) {
        return showToast('Módulo de pagos no disponible.', 'danger');
    }

    const totalText = $("#totalCopay").text().replace(/[^\d]/g, '');
    const monto = parseInt(totalText, 10) || 0;
    if (monto <= 0) {
        return showToast('El monto a pagar debe ser mayor a cero.', 'warning');
    }

    await window.paymentManager.registrarPago(appointmentId, {
        monto,
        metodo: $("#payMethod").val() || 'Efectivo',
        estado: $("#paymentStatus").val() || 'Pagado',
        codigoTransaccion: $("#pTransactionCode").val() || null
    });
    $("#paymentStatus").val('Pagado');
}

function renderInsumos() {
    const tbody = $("#insumosListBody");
    tbody.empty();
    window.currentInsumosTotal = 0;

    if (currentInsumos.length === 0) {
        tbody.append('<tr><td colspan="3" class="text-muted fst-italic py-2 text-center">Sin insumos adicionales</td></tr>');
        calculateTotal();
        return;
    }

    currentInsumos.forEach((ins, idx) => {
        const subtotal = ins.price * (ins.quantity || 1);
        window.currentInsumosTotal += subtotal;

        tbody.append(`
            <tr class="align-middle">
                <td>
                    <div class="fw-bold">${ins.name}</div>
                    <small class="text-muted">${ins.category || 'Insumo'}</small>
                </td>
                <td class="text-center">x${ins.quantity || 1}</td>
                <td class="text-end text-primary fw-bold">$${subtotal.toLocaleString('es-CL')}</td>
                <td style="width:30px;" class="text-end">
                    <button type="button" class="btn btn-sm text-danger p-0" onclick="quitarInsumo(${idx})">
                        <i class="bi bi-x-circle-fill"></i>
                    </button>
                </td>
            </tr>`);
    });

    calculateTotal();
}
function agregarPack(packId) {
    const pack = catalogosAgenda.supply_packs.find(p => String(p.id) === String(packId));
    if (!pack || !pack.items) {
        showToast("No se encontraron ítems en este pack", "warning");
        return;
    }

    pack.items.forEach(item => {
        const supply = item.supply;

        if (supply && supply.is_active) {
            currentInsumos.push({
                id: supply.id,
                name: supply.name,
                price: parseFloat(supply.price) || 0,
                category: supply.category,
                quantity: item.quantity
            });
        }
    });

    showToast(`✅ Pack "${pack.name}" agregado`, "success");
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

function configurarInsumosAgenda() {
    const insumosCobrados = (window.RIS.supplies || []).filter(s => (parseFloat(s.price) || 0) > 0);
    const select = $("#insumoIndividualSelect");
    select.empty().append('<option value="">+ Agregar insumo individual...</option>');
    insumosCobrados.forEach(s => {
        select.append(`<option value="${s.id}">${s.name} ($${Number(s.price).toLocaleString('es-CL')})</option>`);
    });

    select.off('change.insumos').on('change.insumos', function () {
        if (!this.value) return;
        const sup = window.RIS.supplies.find(s => String(s.id) === String(this.value));
        if (sup) {
            currentInsumos.push({
                id: sup.id,
                name: sup.name,
                price: parseFloat(sup.price) || 0,
                category: sup.category,
                quantity: 1
            });
            renderInsumos();
        }
        $(this).val("");
    });
}

function setupProEventListeners() {
    $("#agendaStatus").on("change", colorSelectorEstado);
    $(document).on("input", ".eQty", calculateTotal);
    window.addEventListener('ris_updated', actualizarCalendarioEnVivo);
    window.addEventListener('storage', (e) => { if (e.key === 'ris_app_data') actualizarCalendarioEnVivo(); });

    $("#mTratante").on("change", async function () {
        if ($(this).val() === "NUEVO") {
            $("#modalNuevoMedico").modal('show');
            $(this).val(""); // Reset select
        }
    });

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

    $(document).on("change", ".ePrice", async function () {
        const row = $(this).closest("tr");
        const originalPrice = parseFloat(row.data("original-price")) || 0;
        const newPrice = parseFloat($(this).val()) || 0;
        if (originalPrice > 0 && newPrice !== originalPrice) {
            const motivo = await showPrompt("Justifique el cambio de precio arancelario:", {
                title: "Cambio de precio"
            });
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

    $("#pRut").on("blur", async function () {
        let rut = $(this).val().toUpperCase();
        if (!rut) return;

        if (!validarRut(rut)) {
            if (typeof showToast === 'function') showToast("❌ RUT Inválido", "danger");
            $(this).addClass("is-invalid");
            return;
        }
        $(this).removeClass("is-invalid").addClass("is-valid");

        const token = localStorage.getItem('ris_token');
        const labId = localStorage.getItem('ris_lab_id');

        try {
            const response = await fetch(`${API_URL}/patients/search?rut=${rut}`, {
                headers: {
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${token}`,
                    'X-Lab-Id': labId
                }
            });

            const rawText = await response.text();

            if (response.ok) {
                if (!rawText) {
                    console.error("Laravel devolvió un código 200, pero el texto está vacío.");
                    return;
                }

                const data = JSON.parse(rawText);

                if (data.success && data.data) {
                    const persona = data.data.persona || data.data;

                    $("#pName").val(persona.names || "");
                    $("#pLastName").val(persona.last_name_1 || "");
                    $("#pSecondLastName").val(persona.last_name_2 || "");
                    $("#pSex").val(persona.gender || "M");
                    $("#pEmail").val(persona.email || "");
                    $("#pPhone").val(persona.phone || "");

                    $("#pBirthDate").val(persona.birth_date || "").trigger("change");

                    if (data.data.insurance_id) {
                        $("#pInsurance").val(data.data.insurance_id).trigger("change");
                        setTimeout(() => {
                            if (data.data.insurance_plan_id) {
                                $("#pPlan").val(data.data.insurance_plan_id);
                            }
                        }, 250);
                    }
                    if (data.data.history_count && data.data.history_count > 0) {
                        showToast(`🔔 Este paciente tiene ${data.data.history_count} exámenes previos. Pídale que los traiga para comparativa.`, "info");
                        $("#pName").addClass('border-info bg-info-subtle');
                    }
                    if (typeof showToast === 'function') showToast("✅ Paciente cargado desde la base de datos.", "success");
                }
            } else if (response.status === 404) {
                if (typeof showToast === 'function') showToast("ℹ️ Paciente nuevo. Por favor ingrese sus datos.", "info");
                $("#pName, #pLastName, #pSecondLastName, #pBirthDate, #pEmail, #pPhone").val("");
                $("#pInsurance, #pPlan").val("");
            } else {
                console.error(`Error del Servidor (${response.status}):`, rawText);
            }
        } catch (error) {
            console.error("Error buscando paciente:", error);
        }
    });
    $("#pInsurance").on("change", function () {
        const insId = $(this).val();
        const selectPlan = $("#pPlan");
        selectPlan.empty().append('<option value="">Seleccione Plan...</option>');

        const seguro = catalogosAgenda.insurances.find(i => i.id == insId);
        if (seguro && seguro.plans) {
            seguro.plans.forEach(plan => {
                selectPlan.append(`<option value="${plan.id}">${plan.name} (${plan.percentage}% desc)</option>`);
            });
        }

        calculateTotal();
    });

    $("#pPlan").on("change", function () {
        calculateTotal();
    });

    $(document).on("change", ".eMachine", function () {
        const row = $(this).closest("tr");
        const machineId = $(this).val();
        const examSelect = row.find(".eExam");
        const subSelect = row.find(".eSubExam");

        examSelect.empty().append('<option value="">--</option>');
        subSelect.empty().append('<option value="">--</option>');
        row.find(".ePrice").val(0);
        row.find(".eCode").val('');

        if (!machineId) return;

        const sala = window.RIS.resources.find(r => r.id === machineId);
        if (!sala) return;

        const examenesFiltrados = catalogosAgenda.exams.filter(e => (e.group_code || e.group) === sala.group);

        examenesFiltrados.forEach(e => {
            examSelect.append(`<option value="${e.id}" data-price="${e.price}">${e.name}</option>`);
        });
    });

    $(document).on("change", ".eExam", function () {
        const row = $(this).closest("tr");
        const examId = $(this).val();
        const subSelect = row.find(".eSubExam");

        subSelect.empty().append('<option value="">Sin variante</option>');

        if (!examId) return;

        const examData = catalogosAgenda.exams.find(e => String(e.id) === String(examId));
        if (examData) {
            row.find(".ePrice").val(examData.price || 0);
            row.find(".eCode").val(examData.fonasa_code || '');

            if (examData.sub_exams && examData.sub_exams.length > 0) {
                examData.sub_exams.forEach(sub => {
                    subSelect.append(`<option value="${sub.id}" data-duration="${sub.duration}">${sub.name}</option>`);
                });
            }
        }
        calculateTotal();
    });
}

function buscarDisponibilidad() {
    const machine = $(".eMachine").val();
    if (!machine) return showToast("Seleccione una sala de examen primero", "warning");

    // Algoritmo simplificado: Setea la hora a "ahora" más 30 minutos
    let now = new Date();
    now.setMinutes(now.getMinutes() + 30);
    now.setSeconds(0);
    $("#manualStartTime").val(toLocalISOString(now).substring(0, 16));

    let end = new Date(now.getTime() + 15 * 60000); // +15 mins
    $("#manualEndTime").val(toLocalISOString(end).substring(0, 16));
    showToast("Disponibilidad sugerida ingresada en los controles de hora.", "success");
}

$(document).ready(function () {
    $('#fileOrdenMedica').on('change', function (e) { procesarArchivoEscaner(e, 'orden'); });
    $('#fileEncuesta').on('change', function (e) { procesarArchivoEscaner(e, 'encuesta'); });
});

function procesarArchivoEscaner(event, tipo) {
    const file = event.target.files[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
        showToast("El archivo es demasiado grande. El máximo permitido es 5MB.", "warning");
        event.target.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function (e) {
        const base64Data = e.target.result;

        if (tipo === 'orden') {
            $('#docOrdenMedica').val(base64Data);
            $('#btnVerOrden, #btnBorrarOrden').removeClass('d-none');
        } else {
            $('#docEncuesta').val(base64Data);
            $('#btnVerEncuesta, #btnBorrarEncuesta').removeClass('d-none');
        }
        showToast("✅ Documento procesado y adjuntado correctamente.", "success");
    };
    reader.readAsDataURL(file);
}

function verDocumento(tipo) {
    const inputId = tipo === 'orden' ? '#docOrdenMedica' : '#docEncuesta';
    const docData = $(inputId).val();

    if (!docData) {
        if (typeof showToast === 'function') showToast("No hay ningún documento adjunto.", "warning");
        return;
    }

    if (docData.startsWith('data:')) {
        const mimeType = docData.match(/data:([a-zA-Z0-9]+\/[a-zA-Z0-9-.+]+).*,.*/)[1];

        const byteString = atob(docData.split(',')[1]);
        const ab = new ArrayBuffer(byteString.length);
        const ia = new Uint8Array(ab);
        for (let i = 0; i < byteString.length; i++) {
            ia[i] = byteString.charCodeAt(i);
        }
        const blob = new Blob([ab], { type: mimeType });
        const blobUrl = URL.createObjectURL(blob);

        window.open(blobUrl, '_blank');
    }
    else {
        let fullUrl = docData;

        if (!fullUrl.startsWith('http')) {
            const baseUrl = "http://170.246.172.83";
            fullUrl = `${baseUrl}/${docData}`;
        }

        window.open(fullUrl, '_blank');
    }
}

async function iniciarEscaneoDirecto(tipo) {
    const btn = tipo === 'orden' ? $('#btnEscanearOrden') : $('#btnEscanearEncuesta');
    const textoOriginal = btn.html();

    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Escaneando...');

    try {
        const response = await fetch(`${LOCAL_BRIDGE_URL}/escanear`);
        const data = await response.json();

        if (response.ok && data.success) {
            if (tipo === 'orden') {
                $('#docOrdenMedica').val(data.file);
                $('#btnVerOrden, #btnBorrarOrden').removeClass('d-none');
            } else {
                $('#docEncuesta').val(data.file);
                $('#btnVerEncuesta, #btnBorrarEncuesta').removeClass('d-none');
            }
            showToast("✅ Documento digitalizado con éxito.", "success");
        } else {
            throw new Error(data.message || "Error desconocido");
        }
    } catch (error) {
        console.error("Error del puente:", error);
        showToast("❌ No se detectó el Escáner. Asegúrese de tener el 'RIS Bridge' abierto en su PC.", "danger");
    } finally {
        btn.prop('disabled', false).html(textoOriginal);
    }
}

function calcularTotalAgenda() {
    let totalExamenes = 0;

    $(".exam-row").each(function () {
        const row = $(this);
        const qty = parseInt(row.find(".eQty").val()) || 1;
        const subOption = row.find(".eSubExam option:selected");
        const extraSubExamen = parseInt(subOption.attr("data-extra")) || 0;

        let precioFila = parseInt(row.find(".ePrice").val()) || 0;

        if (precioFila === 0 && extraSubExamen > 0) {
            precioFila = extraSubExamen;
            row.find(".ePrice").val(precioFila);
        }

        totalExamenes += (precioFila * qty);
    });

    let totalInsumos = 0;
    $("#tablaInsumosAgregados tr").each(function () {
        const subtotalText = $(this).find("td:last").text().replace('$', '').replace(/\./g, '');
        totalInsumos += parseInt(subtotalText) || 0;
    });

    const totalGeneral = totalExamenes + totalInsumos;

    const totalFormateado = new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP' }).format(totalGeneral);

    $("#totalCopay").text(totalFormateado);
}

async function marcarComoRevisado(citaId) {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/appointments/${citaId}/clear-review`, {
            method: 'PUT',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });

        if (response.ok) {
            $("#alertDevolucion").fadeOut(() => $("#alertDevolucion").remove());
            if (typeof showToast === 'function') showToast("✅ Alerta revisada y apagada.", "success");

            cargarAgendaDesdeServidor();
        }
    } catch (e) {
        console.error("Error al limpiar revisión:", e);
    }
}

function toggleFormatoDocAgenda() {
    const tipo = $("#pTipoDoc").val();
    const input = $("#pRut");
    const label = $("#lblDoc");

    input.val("").removeClass("is-invalid is-valid");

    if (tipo === "PASAPORTE") {
        label.text("N° Pasaporte / ID *");
        input.attr("placeholder", "Ej. AB123456");
        input.off("input"); // Desactivamos el formateo automático de RUT
    } else {
        label.text("N° de RUT *");
        input.attr("placeholder", "12.345.678-9");
        // Re-vinculamos el formateo de RUT
        input.on("input", function () {
            let actual = $(this).val().replace(/[^0-9kK]/g, '');
            if (actual.length === 0) return;
            let cuerpo = actual.slice(0, -1);
            let dv = actual.slice(-1).toUpperCase();
            let rutPuntos = "";
            for (let i = cuerpo.length - 1, j = 1; i >= 0; i--, j++) {
                rutPuntos = cuerpo.charAt(i) + rutPuntos;
                if (j % 3 === 0 && i !== 0) rutPuntos = "." + rutPuntos;
            }
            $(this).val(rutPuntos + "-" + dv);
        });
    }
}

// Al guardar la cita, usa esta validación:
function validarDocumentoAgenda() {
    const tipo = $("#pTipoDoc").val();
    const doc = $("#pRut").val().trim();
    if (tipo === "RUT" && !validarRut(doc)) return false;
    if (tipo === "PASAPORTE" && doc.length < 4) return false;
    return true;
}