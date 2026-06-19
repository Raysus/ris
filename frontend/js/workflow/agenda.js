/* =========================================
   MÓDULO DE AGENDA Y RECEPCIÓN (agenda.js)
   ========================================= */

let calendar;
let currentInsumos = [];
let catalogosAgenda = {};
window.currentInsumosTotal = 0;

/** Placeholder de selects (ej. value="-") → null para UUIDs en la API. */
function risNullableUuid(value) {
    if (value == null || value === '' || value === '-') return null;
    return value;
}

/** Capitaliza cada palabra: "maria jose" → "Maria Jose", "GONZÁLEZ" → "González". */
function capitalizarNombrePropio(value) {
    if (value == null || typeof value !== 'string') return '';
    return value
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .map((word) => {
            const lower = word.toLocaleLowerCase('es-CL');
            return lower.charAt(0).toLocaleUpperCase('es-CL') + lower.slice(1);
        })
        .join(' ');
}

function configurarCamposPacienteAgenda() {
    const hoy = new Date().toISOString().split('T')[0];
    $("#pBirthDate").attr({ min: '1900-01-01', max: hoy });

    const camposNombre = '#pName, #pLastName, #pSecondLastName';
    $(document).off('blur.agendaCapitalize', camposNombre).on('blur.agendaCapitalize', camposNombre, function () {
        const formatted = capitalizarNombrePropio($(this).val());
        if (formatted !== $(this).val()) {
            $(this).val(formatted);
        }
    });
}

/** Normaliza birth_date de la API (ISO) al formato YYYY-MM-DD del input date. */
function risFormatBirthDateForInput(raw) {
    if (raw == null || raw === '') return '';
    if (typeof raw === 'string') {
        const m = raw.match(/^(\d{4}-\d{2}-\d{2})/);
        if (m) return m[1];
    }
    try {
        const d = new Date(raw);
        if (!Number.isNaN(d.getTime())) {
            const y = d.getUTCFullYear();
            const mo = String(d.getUTCMonth() + 1).padStart(2, '0');
            const day = String(d.getUTCDate()).padStart(2, '0');
            return `${y}-${mo}-${day}`;
        }
    } catch (_) { /* ignore */ }
    return '';
}

function risActualizarEdadPacienteAgenda() {
    if (!validarFechaNacimientoAgenda()) {
        $("#pAge").val('');
        return;
    }
    const raw = $("#pBirthDate").val();
    if (!raw) {
        $("#pAge").val('');
        return;
    }
    const bd = new Date(raw + 'T12:00:00');
    const today = new Date();
    let age = today.getFullYear() - bd.getFullYear();
    if (today.getMonth() < bd.getMonth() || (today.getMonth() === bd.getMonth() && today.getDate() < bd.getDate())) {
        age--;
    }
    $("#pAge").val(age >= 0 ? age : '');
}

function validarFechaNacimientoAgenda() {
    const raw = $("#pBirthDate").val();
    if (!raw) return true;

    if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
        showToast('La fecha de nacimiento debe usar año de 4 dígitos (AAAA-MM-DD).', 'warning');
        $("#pBirthDate").addClass('is-invalid');
        return false;
    }

    const year = parseInt(raw.slice(0, 4), 10);
    const currentYear = new Date().getFullYear();
    if (year < 1900 || year > currentYear) {
        showToast(`El año de nacimiento debe estar entre 1900 y ${currentYear}.`, 'warning');
        $("#pBirthDate").addClass('is-invalid');
        return false;
    }

    const bd = new Date(raw + 'T12:00:00');
    if (Number.isNaN(bd.getTime()) || bd > new Date()) {
        showToast('La fecha de nacimiento no es válida.', 'warning');
        $("#pBirthDate").addClass('is-invalid');
        return false;
    }

    $("#pBirthDate").removeClass('is-invalid');
    return true;
}

/** Colores de estado (alineados con --agenda-* en style.css / marca HealthTICloud) */
const AGENDA_ESTADO_COLORES = {
    'pre-agendado': '#9a6fa8',
    'agendado': '#7d2181',
    'confirmado': '#2d5080',
    'espera': '#b8860b',
    'anulado': '#c41e3a',
    'atendido': '#4a5568',
};
const AGENDA_ESTADO_TEXTO = '#ffffff';

/** Estados que ya salieron de recepción → gris en calendario. */
const AGENDA_ESTADOS_ATENDIDO = new Set([
    'dicom_enviado',
    'en_atencion',
    'devuelto_worklist',
    'en_informe',
    'pendiente_radiologo',
    'para_firma',
    'entregable',
    'entregado',
    'atendido',
]);

/** Clave visual para color de cita en la agenda. */
function risNormalizarEstadoAgendaVisual(status) {
    const s = String(status || '').trim().toLowerCase();
    if (AGENDA_ESTADOS_ATENDIDO.has(s)) return 'atendido';
    if (s.includes('pre')) return 'pre-agendado';
    if (s.includes('anulado')) return 'anulado';
    if (s.includes('espera')) return 'espera';
    if (s.includes('confirmado')) return 'confirmado';
    if (s.includes('agendado')) return 'agendado';
    return 'agendado';
}

/** Minutos por modalidad/sala cuando no viene del servidor (evita fallo si RIS.tiemposPorGrupo no está inicializado). */
const TIEMPOS_POR_GRUPO_DEFAULT = { CR: 15, DX: 15, RX: 15, CT: 30, MRI: 45, US: 20, MAMO: 15, General: 15 };

/** Equivalencias de código de modalidad (sala ↔ prestación). CR y DX no se fusionan. */
function risGrupoModalidadEquiv(code) {
    const c = String(code || '').toUpperCase().trim();
    const aliases = { ECO: 'US', SCANNER: 'CT', TC: 'CT', RM: 'MRI', MR: 'MRI', MG: 'MAMO', DENSITO: 'DEXA' };
    return aliases[c] || c;
}

/** Grupos de examen compatibles con cada sala (catálogo usa RX; salas CR/DX). */
const RIS_SALA_EXAM_COMPAT = {
    CR: ['CR', 'RX', 'DX'],
    DX: ['CR', 'RX', 'DX'],
    RX: ['CR', 'RX', 'DX'],
    MAMO: ['MAMO', 'MG'],
    US: ['US', 'ECO'],
    CT: ['CT', 'SCANNER', 'TC'],
    MRI: ['MRI', 'MR', 'RM'],
    DEXA: ['DEXA', 'DENSITO'],
    CBCT: ['CBCT'],
    IO: ['IO'],
    NM: ['NM'],
    PT: ['PT'],
    RF: ['RF'],
    XA: ['XA'],
};

function risGruposExamenPermitidosParaSala(machineGroup) {
    const salaGroup = risGrupoModalidadEquiv(machineGroup);
    if (!salaGroup || salaGroup === 'GENERAL' || salaGroup === 'OT') return null;
    return RIS_SALA_EXAM_COMPAT[salaGroup] || [salaGroup];
}

function risGrupoSalaDesdeMaquina(machineId) {
    if (!machineId) return null;

    const fromResource = (window.RIS?.resources || []).find((r) => String(r.id) === String(machineId));
    const resourceGroup = String(fromResource?.group || '').trim();
    if (resourceGroup && resourceGroup.toUpperCase() !== 'GENERAL') return resourceGroup;

    const fromCatalog = (catalogosAgenda.machines || []).find((m) => String(m.id) === String(machineId));
    const catalogGroup = String(fromCatalog?.group_code || fromCatalog?.group || '').trim();
    return catalogGroup || null;
}

/** ¿El examen corresponde al grupo/modalidad de la sala? */
function risExamenCompatibleConSala(exam, machineGroup) {
    if (!machineGroup) return false;

    const allowed = risGruposExamenPermitidosParaSala(machineGroup);
    if (!allowed) return true;

    const examGroup = risGrupoModalidadEquiv(exam.group_code || exam.group) || 'OT';
    return allowed.includes(examGroup);
}

const RIS_MODALITY_LABELS = {
    CR: 'Radiografía CR',
    DX: 'Radiografía DX',
    RX: 'Radiografía (RX)',
    CT: 'Tomografía (CT)',
    MRI: 'Resonancia (MRI)',
    US: 'Ecografía (US)',
    MAMO: 'Mamografía (MAMO)',
    DEXA: 'Densitometría (DEXA)',
    CBCT: 'Cone beam (CBCT)',
    IO: 'Intraoral (IO)',
    NM: 'Medicina nuclear (NM)',
    PT: 'PET (PT)',
    RF: 'Fluoroscopia (RF)',
    XA: 'Angiografía (XA)',
    OT: 'Otro (OT)',
};

const RIS_MODALITY_ORDER = ['CR', 'DX', 'RX', 'CT', 'MRI', 'US', 'MAMO', 'DEXA', 'CBCT', 'IO', 'NM', 'PT', 'RF', 'XA', 'OT'];

function risNormalizeAgendaSearch(value) {
    return String(value || '')
        .toLocaleLowerCase('es-CL')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9k]/g, '');
}

function risExamenesParaSala(machineId) {
    if (!machineId) return [];
    const machineGroup = risGrupoSalaDesdeMaquina(machineId);
    if (!machineGroup) return [];
    return risDedupeExamenesCatalogo(catalogosAgenda.exams || [])
        .filter((e) => risExamenCompatibleConSala(e, machineGroup));
}

function risExamCoincideBusqueda(exam, query) {
    const q = String(query || '').trim().toLowerCase();
    if (!q) return true;
    const name = String(exam.name || '').toLowerCase();
    const code = String(exam.fonasa_code || '').toLowerCase();
    const qCode = q.replace(/[\s.]/g, '');
    const codeNorm = code.replace(/[\s.]/g, '');
    return name.includes(q) || code.includes(q) || (qCode && codeNorm.includes(qCode));
}

function risResolverExamenDesdeBusqueda(query, machineId) {
    const q = String(query || '').trim();
    if (!q || !machineId) return null;

    const qLower = q.toLowerCase();
    const qCode = qLower.replace(/[\s.]/g, '');
    const exams = risExamenesParaSala(machineId);

    let match = exams.find((e) => String(e.fonasa_code || '').toLowerCase().replace(/[\s.]/g, '') === qCode);
    if (match) return match;

    match = exams.find((e) => String(e.name || '').toLowerCase() === qLower);
    if (match) return match;

    match = exams.find((e) => {
        const code = String(e.fonasa_code || '').toLowerCase();
        return code && (code === qLower || code.startsWith(qLower));
    });
    if (match) return match;

    const partial = exams.filter((e) => risExamCoincideBusqueda(e, q));
    if (partial.length === 1) return partial[0];

    return null;
}

function risSyncExamQueryLabel($row, exam) {
    if (!$row || !$row.length) return;
    if (!exam) {
        $row.find('.eExamQuery').val('');
        return;
    }
    const code = exam.fonasa_code ? String(exam.fonasa_code) : '';
    $row.find('.eExamQuery').val(code ? `${code} — ${exam.name}` : exam.name);
}

function risSeleccionarExamenEnFila($row, examId) {
    if (!$row || !$row.length || !examId) return;
    const $select = $row.find('.eExam');
    if (!$select.find(`option[value="${examId}"]`).length) {
        poblarSelectExamenesAgenda($select, $row.find('.eMachine').val());
    }
    $select.val(String(examId)).trigger('change');
    const exam = (catalogosAgenda.exams || []).find((e) => String(e.id) === String(examId));
    if (exam) risSyncExamQueryLabel($row, exam);
}

function filtrarEventosAgendaCalendario(termRaw) {
    const viewType = calendar?.view?.type || 'resourceTimelineDay';
    return mapearEventosCalendario(filtrarAgendaItems(window.RIS?.agenda, termRaw), viewType);
}

function risDedupeExamenesCatalogo(exams) {
    const seen = new Map();
    for (const exam of exams) {
        const group = risGrupoModalidadEquiv(exam.group_code || exam.group) || 'OT';
        const key = `${exam.laboratory_id || ''}|${group}|${String(exam.name || '').trim().toLowerCase()}`;
        if (!seen.has(key)) seen.set(key, exam);
    }
    return [...seen.values()];
}

/** Exámenes del catálogo agrupados por modalidad, filtrados por grupo de la sala seleccionada. */
function poblarSelectExamenesAgenda($examSelect, machineId, filterQuery = '') {
    if (!$examSelect || !$examSelect.length) return;

    const prev = $examSelect.val();
    $examSelect.empty();

    if (!machineId) {
        $examSelect.append('<option value="">-- Seleccione sala primero --</option>');
        return;
    }

    const machineGroup = risGrupoSalaDesdeMaquina(machineId);
    if (!machineGroup) {
        $examSelect.append('<option value="">-- Sala sin grupo (configurar en admin) --</option>');
        return;
    }

    const exams = risExamenesParaSala(machineId).filter((e) => risExamCoincideBusqueda(e, filterQuery));
    if (!exams.length) {
        const salaLabel = RIS_MODALITY_LABELS[risGrupoModalidadEquiv(machineGroup)] || machineGroup || 'sala';
        const hint = String(filterQuery || '').trim()
            ? '-- Sin coincidencias --'
            : `-- Sin exámenes para ${salaLabel} --`;
        $examSelect.append(`<option value="">${hint}</option>`);
        return;
    }

    $examSelect.append('<option value="">-- Seleccione examen --</option>');

    const byGroup = {};
    exams.forEach((e) => {
        const g = risGrupoModalidadEquiv(e.group_code || e.group) || 'OT';
        if (!byGroup[g]) byGroup[g] = [];
        byGroup[g].push(e);
    });

    const grupos = [...new Set([...RIS_MODALITY_ORDER, ...Object.keys(byGroup)])].filter((g) => byGroup[g]?.length);

    grupos.forEach((grupo) => {
        const label = RIS_MODALITY_LABELS[grupo] || grupo;
        const $og = $(`<optgroup label="${label}"></optgroup>`);
        byGroup[grupo]
            .slice()
            .sort((a, b) => String(a.name).localeCompare(String(b.name), 'es'))
            .forEach((e) => {
                const code = e.fonasa_code ? String(e.fonasa_code) : '';
                const text = code ? `[${code}] ${e.name}` : e.name;
                $og.append(
                    `<option value="${e.id}" data-price="${e.price || 0}" data-group="${grupo}" data-code="${code}">${text}</option>`
                );
            });
        $examSelect.append($og);
    });

    if (prev && $examSelect.find(`option[value="${prev}"]`).length) {
        $examSelect.val(prev);
    }
}

const AGENDA_LEYENDA_ITEMS = [
    ['pre-agendado', 'Pre-agendado', 'Reserva tentativa'],
    ['agendado', 'Agendado', 'Cita formalizada'],
    ['confirmado', 'Confirmado', 'Paciente confirmó asistencia'],
    ['espera', 'En espera', 'En sala de espera'],
    ['anulado', 'Anulado', 'Cancelada'],
    ['atendido', '<i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Atendido', 'En flujo clínico'],
];

function htmlLeyendaEstadosCalendario() {
    const items = AGENDA_LEYENDA_ITEMS.map(([key, label, title]) =>
        `<span class="agenda-leyenda-item" data-estado-leyenda="${key}" title="${title}">${label}</span>`
    ).join('');
    return `<div class="agenda-leyenda-estados d-flex flex-wrap gap-3 gap-md-4" aria-label="Leyenda de estados de cita">${items}</div>`;
}

function pintarLeyendaEstadosAgenda(root) {
    const scope = root || document.getElementById('calendar');
    if (!scope) return;
    scope.querySelectorAll('[data-estado-leyenda]').forEach((el) => {
        const key = el.getAttribute('data-estado-leyenda');
        const color = AGENDA_ESTADO_COLORES[key] || '#7d2181';
        el.style.setProperty('--leyenda-color', color);
    });
}

/** Leyenda + toolbar dentro del contenedor #calendar (elemento .fc) */
function montarUiInternaCalendario() {
    const root = document.getElementById('calendar');
    if (!root || !root.classList.contains('fc')) return;

    let leyenda = root.querySelector('.agenda-fc-leyenda');
    if (!leyenda) {
        leyenda = document.createElement('div');
        leyenda.className = 'agenda-fc-leyenda';
        leyenda.innerHTML = htmlLeyendaEstadosCalendario();
        const toolbar = root.querySelector('.fc-header-toolbar');
        if (toolbar) {
            root.insertBefore(leyenda, toolbar);
        } else {
            root.prepend(leyenda);
        }
    }
    pintarLeyendaEstadosAgenda(root);
}

function calcularDuracionCita(machineId, cantidadExamenes) {
    const sala = (window.RIS?.resources || []).find((r) => String(r.id) === String(machineId));
    const tiempos = { ...TIEMPOS_POR_GRUPO_DEFAULT, ...(window.RIS?.tiemposPorGrupo || {}) };
    const group = sala?.group;
    const minutosBase = group != null && tiempos[group] != null ? tiempos[group] : 15;
    const qty = Math.max(1, Number(cantidadExamenes) || 1);
    return minutosBase * qty;
}

function toLocalISOString(date) {
    if (!date) return "";
    const pad = n => (n < 10 ? '0' + n : n);
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:00`;
}

/**
 * Laravel devuelve UTC con microsegundos (ej. 2026-06-15T14:00:00.000000Z).
 * split('.')[0] pierde la Z y el navegador interpreta la hora como local (+4 h en Chile).
 */
function normalizeApiDateTime(value) {
    if (!value) return "";
    let s = String(value).trim().replace(" ", "T");
    const zMatch = s.match(/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\.\d+Z$/i);
    if (zMatch) return `${zMatch[1]}Z`;
    const offsetMatch = s.match(/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\.\d+([+-]\d{2}:?\d{2})$/);
    if (offsetMatch) return `${offsetMatch[1]}${offsetMatch[2]}`;
    if (s.includes(".")) s = s.split(".")[0];
    return s;
}

/** Valor para input datetime-local (hora local del navegador, sin desfase UTC). */
function formatDateTimeLocal(value) {
    if (!value) return "";
    const d = value instanceof Date ? value : new Date(normalizeApiDateTime(value));
    if (Number.isNaN(d.getTime())) return "";
    const pad = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function getAgendaScheduleConfig() {
    const defaults = { horaInicio: '08:00:00', horaFin: '20:00:00', intervalo: '00:15:00' };
    return { ...defaults, ...(window.RIS?.config || {}) };
}

function intervaloAMinutos(intervalo) {
    const partes = String(intervalo || '00:15:00').split(':');
    return (parseInt(partes[0], 10) || 0) * 60 + (parseInt(partes[1], 10) || 15);
}

function formatearHoraLegible(timeStr) {
    if (!timeStr) return '';
    const p = String(timeStr).split(':');
    return `${p[0] || '00'}:${p[1] || '00'}`;
}

function redondearDatetimeAlIntervalo(value, intervalo) {
    const mins = intervaloAMinutos(intervalo);
    const d = value instanceof Date ? new Date(value) : new Date(normalizeApiDateTime(value));
    if (Number.isNaN(d.getTime()) || mins <= 0) return d;
    const total = d.getHours() * 60 + d.getMinutes();
    const redondeado = Math.round(total / mins) * mins;
    d.setHours(Math.floor(redondeado / 60), redondeado % 60, 0, 0);
    return d;
}

function aplicarConfigAgendaHorario(schedule) {
    window.RIS = window.RIS || {};
    window.RIS.config = { ...(window.RIS.config || {}), ...schedule };
    actualizarPanelAyudaAgenda();
    if (!calendar) return;
    const slotDur = normalizarDuracionFC(schedule.intervalo);
    calendar.setOption('slotMinTime', schedule.horaInicio || '08:00:00');
    calendar.setOption('slotMaxTime', schedule.horaFin || '20:00:00');
    calendar.setOption('views', buildAgendaCalendarViews(schedule));
    calendar.setOption('slotDuration', slotDur);
    calendar.setOption('slotLabelInterval', '01:00:00');
}

function actualizarPanelAyudaAgenda() {
    const cfg = getAgendaScheduleConfig();
    const mins = intervaloAMinutos(cfg.intervalo);
    const inicio = formatearHoraLegible(cfg.horaInicio);
    const fin = formatearHoraLegible(cfg.horaFin);

    $('#agendaIntervaloTexto').text(`${mins} minutos`);
    $('#agendaHorarioLab').text(`${inicio} – ${fin}`);
    $('#agendaGrillaDetalle').text(`bloques de ${mins} min · marcas cada hora`);
    $('.agenda-intervalo-inline').text(String(mins));

    const manual = document.getElementById('manualStartTime');
    const manualEnd = document.getElementById('manualEndTime');
    if (manual) manual.step = mins * 60;
    if (manualEnd) manualEnd.step = mins * 60;
}

function sincronizarHorariosCitaModal(startValue, endValue) {
    const cfg = getAgendaScheduleConfig();
    let start = startValue ? redondearDatetimeAlIntervalo(startValue, cfg.intervalo) : null;
    const startLocal = start ? formatDateTimeLocal(start) : '';
    if (startLocal) {
        $('#selectedStart').val(startLocal);
        $('#manualStartTime').val(startLocal);
    }
    if (endValue) {
        const endLocal = formatDateTimeLocal(redondearDatetimeAlIntervalo(endValue, cfg.intervalo));
        if (endLocal) $('#manualEndTime').val(endLocal);
    } else if (start) {
        actualizarTerminoEstimadoDesdeExamenes();
    }
    actualizarResumenBloquesCita();
}

function actualizarResumenBloquesCita() {
    const cfg = getAgendaScheduleConfig();
    const mins = intervaloAMinutos(cfg.intervalo);
    const startVal = $('#manualStartTime').val() || $('#selectedStart').val();
    const endVal = $('#manualEndTime').val();
    const $resumen = $('#agendaCitaBloquesResumen');
    const $ayuda = $('#agendaCitaHorarioAyuda');

    if (!startVal) {
        $ayuda.text('Seleccione un bloque libre en el calendario (vista Día/Semana) o ingrese fecha y hora abajo.');
        $resumen.text(`Cada bloque del calendario = ${mins} minutos.`);
        return;
    }

    const inicio = new Date(startVal);
    if (Number.isNaN(inicio.getTime())) return;

    const inicioFmt = inicio.toLocaleString('es-CL', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    $ayuda.html(`<strong>Inicio:</strong> ${inicioFmt}`);

    if (endVal) {
        const fin = new Date(endVal);
        if (!Number.isNaN(fin.getTime())) {
            const diffMin = Math.max(mins, Math.round((fin - inicio) / 60000));
            const bloques = Math.max(1, Math.ceil(diffMin / mins));
            const finFmt = fin.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
            $resumen.text(`Término estimado ${finFmt} · ~${bloques} bloque(s) de ${mins} min (${diffMin} min total).`);
            return;
        }
    }
    $resumen.text(`Duración según exámenes · bloques de ${mins} min en el calendario.`);
}

function actualizarTerminoEstimadoDesdeExamenes() {
    const startVal = $('#manualStartTime').val() || $('#selectedStart').val();
    if (!startVal) return;

    const salas = new Set();
    let totalMin = 0;
    $('.study-entry').each(function () {
        const machine = $(this).find('.eMachine').val();
        if (!machine) return;
        salas.add(machine);
        const qty = parseInt($(this).find('.eQty').val(), 10) || 1;
        totalMin += calcularDuracionCita(machine, qty);
    });
    if (totalMin < intervaloAMinutos(getAgendaScheduleConfig().intervalo)) {
        totalMin = intervaloAMinutos(getAgendaScheduleConfig().intervalo);
    }
    const fin = new Date(new Date(startVal).getTime() + totalMin * 60000);
    $('#manualEndTime').val(formatDateTimeLocal(fin));
    actualizarResumenBloquesCita();
}

function formatearRangoHoraEvento(start, end) {
    if (!start) return '';
    const opts = { hour: '2-digit', minute: '2-digit', hour12: false };
    const a = start.toLocaleTimeString('es-CL', opts);
    if (!end) return a;
    return `${a} – ${end.toLocaleTimeString('es-CL', opts)}`;
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

function actualizarCtaAtencionSalas() {
    const wrap = $("#agendaIrAtencionWrap");
    if (!wrap.length) return;
    const status = ($("#agendaStatus").val() || '').toLowerCase();
    if (status !== 'confirmado') {
        wrap.addClass('d-none');
        return;
    }
    const p = typeof getLabProfile === 'function' ? getLabProfile() : {};
    const moduloLabel = p.technician_module_label || (p.uses_dicom_worklist === false ? 'Atención en salas' : 'Worklist');
    const icon = p.uses_dicom_worklist === false ? 'bi-door-open' : 'bi-list-task';
    wrap.removeClass('d-none');
    $("#agendaIrAtencionTexto").html(
        `<i class="bi ${icon} me-1"></i> Cita confirmada: el tecnólogo debe atenderla en <strong>${moduloLabel}</strong>.`
    );
    $("#agendaIrAtencionBtn").text(`Ir a ${moduloLabel}`);
}

async function initAgenda() {
    window.RIS = window.RIS || {};
    window.RIS.agenda = window.RIS.agenda || [];
    window.RIS.config = window.RIS.config || {};
    window.RIS.resources = window.RIS.resources || [];
    window.RIS.tiemposPorGrupo = { ...TIEMPOS_POR_GRUPO_DEFAULT, ...(window.RIS.tiemposPorGrupo || {}) };
    window.RIS.doctors = window.RIS.doctors || [];
    window.RIS.supplies = window.RIS.supplies || [];
    window.RIS.supplyPacks = window.RIS.supplyPacks || [];

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

    ensureAgendaModalsAnchored();
    bindAgendaModalWizardEvents();
    configurarCamposPacienteAgenda();

    if (!window._agendaListenersBound) {
        setupProEventListeners();
        window._agendaListenersBound = true;
    }
}

function ensureAgendaModalsAnchored() {
    ['appointmentModal', 'modalNuevoMedico'].forEach((id) => {
        const inApp = document.querySelector(`#appContent #${id}`);
        const inBody = document.body.querySelector(`:scope > #${id}`);
        if (inApp && inBody && inApp !== inBody) {
            inBody.remove();
        }
        const el = inApp || document.getElementById(id);
        if (el && typeof risEnsureModalInBody === 'function') {
            risEnsureModalInBody(el);
        }
    });
}

function bindAgendaModalWizardEvents() {
    const modalEl = document.querySelector('#appContent #appointmentModal')
        || document.getElementById('appointmentModal');
    if (!modalEl) return;

    $(modalEl).off('shown.bs.modal.risWizard').on('shown.bs.modal.risWizard', () => {
        if (typeof updateAgendaWizardUI === 'function') updateAgendaWizardUI();
    });

    $('#btnEliminarCita').off('click.risAnular').on('click.risAnular', (e) => {
        e.preventDefault();
        eliminarCita();
    });
    $('#btnGuardarCita').off('click.risGuardar').on('click.risGuardar', (e) => {
        e.preventDefault();
        guardarCita();
    });
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
                group: String(m.group_code || m.group || '').trim()
            }));

            window.RIS.supplies = catalogosAgenda.supplies || [];
            window.RIS.supplyPacks = catalogosAgenda.supply_packs || [];

            if (catalogosAgenda.lab_profile && typeof setLabProfile === 'function') {
                setLabProfile(catalogosAgenda.lab_profile);
                if (typeof applyOperationalModuleNav === 'function') applyOperationalModuleNav();
            }

            if (catalogosAgenda.schedule) {
                aplicarConfigAgendaHorario(catalogosAgenda.schedule);
            } else {
                actualizarPanelAyudaAgenda();
            }

            sincronizarRecursosCalendario();

            try {
                poblarSelectsAgenda();
                configurarInsumosAgenda();
            } catch (populateErr) {
                console.error('Error poblando selects agenda:', populateErr);
            }

            if (typeof applyLabProfileUI === 'function') {
                applyLabProfileUI(document.getElementById('appointmentModal') || document);
            }

            return true;
        }

        console.error('Catálogos agenda:', response.status, data);
        return false;
    } catch (e) {
        console.error("Error en catálogos:", e);
        return false;
    }
}
function sincronizarRecursosCalendario() {
    if (!calendar || !window.RIS?.resources?.length) return;
    try {
        calendar.getResources().forEach(res => res.remove());
        window.RIS.resources.forEach(res => calendar.addResource(res));
    } catch (e) {
        console.warn('No se pudieron refrescar recursos del calendario:', e);
    }
}

function poblarPlanesPrevision(insuranceId, planId = null) {
    const selectPlan = $("#pPlan");
    selectPlan.empty().append('<option value="">Seleccione Plan...</option>');

    const insId = insuranceId ? String(insuranceId) : "";
    if (!insId || !(catalogosAgenda.insurances || []).length) {
        calculateTotal();
        return;
    }

    const seguro = catalogosAgenda.insurances.find((i) => String(i.id) === insId);
    if (seguro?.plans?.length) {
        seguro.plans.forEach((plan) => {
            selectPlan.append(
                `<option value="${plan.id}">${plan.name} (${plan.percentage}% desc)</option>`
            );
        });
    }

    if (planId) {
        const planStr = String(planId);
        if (selectPlan.find(`option[value="${planStr}"]`).length) {
            selectPlan.val(planStr);
        }
    }

    calculateTotal();
}

/**
 * Asigna previsión y plan tras poblar catálogos (edición de cita o búsqueda por RUT).
 */
function setAgendaPrevision(insuranceId, planId = null) {
    const ins = insuranceId ? String(insuranceId) : "";

    if (!ins) {
        $("#pInsurance").val("");
        poblarPlanesPrevision(null);
        return;
    }

    const existe = (catalogosAgenda.insurances || []).some((i) => String(i.id) === ins);
    if (!existe) {
        if (typeof showToast === "function") {
            showToast(
                "La previsión guardada no está disponible en el catálogo de esta sede.",
                "warning"
            );
        }
        $("#pInsurance").val("");
        poblarPlanesPrevision(null);
        return;
    }

    $("#pInsurance").val(ins);
    poblarPlanesPrevision(ins, planId);
}

function poblarSelectsAgenda() {
    const selectTratante = $("#mTratante");
    selectTratante.empty().append('<option value="">Seleccione o escriba...</option>');
    selectTratante.append('<option value="NUEVO" class="fw-bold text-success">➕ Agregar Nuevo Médico...</option>');
    (catalogosAgenda.referring_doctors || []).forEach(doc => {
        selectTratante.append(`<option value="${doc.id}">${doc.names} ${doc.last_name_1}</option>`);
    });

    const selectDestinado = $("#mDestinado");
    selectDestinado.empty().append('<option value="">Seleccione Radiólogo...</option>');
    (catalogosAgenda.destination_doctors || []).forEach(doc => {
        const p = doc.persona || {};
        selectDestinado.append(`<option value="${doc.id}">Dr(a). ${p.names} ${p.last_name_1}</option>`);
    });

    const selectPrevision = $("#pInsurance");
    selectPrevision.empty().append('<option value="">Seleccione Previsión...</option>');
    (catalogosAgenda.insurances || []).forEach(ins => {
        selectPrevision.append(`<option value="${ins.id}">${ins.name}</option>`);
    });

    const selectInsumos = $("#addInsumoSelect");
    if (selectInsumos.length) {
        selectInsumos.empty().append('<option value="">Seleccione insumo...</option>');
        (catalogosAgenda.supplies || []).forEach(sup => {
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

/** Vistas Día (timeline por sala) · Semana (grilla horaria) · Mes (resumen por día). */
function buildAgendaCalendarViews(config) {
    const schedule = typeof config === 'string'
        ? { intervalo: config, horaInicio: '08:00:00', horaFin: '20:00:00' }
        : { ...getAgendaScheduleConfig(), ...(config || {}) };
    const slotDur = normalizarDuracionFC(schedule.intervalo);
    const horaInicio = schedule.horaInicio || '08:00:00';
    const horaFin = schedule.horaFin || '20:00:00';

    return {
        resourceTimelineDay: {
            type: 'resourceTimeline',
            slotDuration: slotDur,
            slotMinWidth: 120,
            slotMinTime: horaInicio,
            slotMaxTime: horaFin,
            slotLabelInterval: '01:00:00',
            slotLabelFormat: {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            },
        },
        resourceTimeGridWeek: {
            type: 'resourceTimeGrid',
            duration: { weeks: 1 },
            slotDuration: slotDur,
            slotMinTime: horaInicio,
            slotMaxTime: horaFin,
            slotLabelInterval: '01:00:00',
            slotLabelFormat: {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            },
            dayHeaderFormat: {
                weekday: 'short',
                day: 'numeric',
                month: 'numeric',
                omitCommas: true,
            },
            allDaySlot: false,
        },
        agendaMes: {
            type: 'list',
            duration: { months: 1 },
            listDayFormat: { weekday: 'long', day: 'numeric', month: 'long' },
            listDaySideFormat: false,
            eventOrder: 'start',
            navLinks: false,
        },
    };
}

function nombreSalaPorEvento(event) {
    const rid = event.getResources?.()[0]?.id || event._def?.resourceIds?.[0];
    if (rid) {
        const sala = (window.RIS?.resources || []).find((r) => String(r.id) === String(rid));
        if (sala) return sala.title || sala.name;
    }
    const appt = window.RIS?.agenda?.find((a) => String(a.id) === String(event.id));
    if (appt?.machine) {
        const sala = (window.RIS?.resources || []).find((r) => String(r.id) === String(appt.machine));
        return sala?.title || appt.machine;
    }
    return 'Sala';
}

function isAgendaVistaResumenMes(viewType) {
    return viewType === 'agendaMes';
}

function formatDateKey(date) {
    const d = date instanceof Date ? new Date(date) : new Date(normalizeApiDateTime(date));
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function esMismaFechaLocal(a, b) {
    return formatDateKey(a) === formatDateKey(b);
}

function nombreSalaPorItem(item) {
    const sala = (window.RIS?.resources || []).find((r) => String(r.id) === String(item.machine));
    return sala?.title || sala?.name || 'Sala';
}

function togglePanelMes(activo) {
    const cal = document.getElementById('calendar');
    const panel = document.getElementById('agendaMesPanel');
    if (cal) cal.classList.toggle('agenda-mes-activo', !!activo);
    if (panel) panel.classList.toggle('d-none', !activo);
}

function renderAgendaMesBloques(rangeStart, rangeEnd, searchTerm) {
    const panel = document.getElementById('agendaMesPanel');
    if (!panel) return;

    const items = filtrarAgendaItems(window.RIS?.agenda || [], searchTerm);
    const hoy = formatDateKey(new Date());

    const days = [];
    const cursor = new Date(rangeStart);
    cursor.setHours(0, 0, 0, 0);
    const end = new Date(rangeEnd);
    while (cursor < end) {
        days.push(new Date(cursor));
        cursor.setDate(cursor.getDate() + 1);
    }

    const html = days.map((day) => {
        const key = formatDateKey(day);
        const citas = items
            .filter((item) => esMismaFechaLocal(item.start, day))
            .sort(
                (a, b) => new Date(normalizeApiDateTime(a.start)) - new Date(normalizeApiDateTime(b.start))
            );

        const label = day.toLocaleDateString('es-CL', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        });
        const hoyCls = key === hoy ? ' agenda-mes-dia--hoy' : '';
        const badge = citas.length
            ? `<span class="badge bg-primary-subtle text-primary ms-2">${citas.length}</span>`
            : '';

        const filas = citas.map((c) => {
            const color = getHexColorEstado(c.status);
            const hora = formatearRangoHoraEvento(
                new Date(normalizeApiDateTime(c.start)),
                c.end ? new Date(normalizeApiDateTime(c.end)) : null
            );
            const estadosIniciales = ['pre-agendado', 'agendado', 'confirmado', 'espera'];
            const locked = !estadosIniciales.includes(c.status);
            const lock = locked ? '<i class="bi bi-lock-fill ms-1" aria-hidden="true"></i>' : '';
            const alert = c.needsReview ? '<span class="badge bg-danger rounded-pill ms-1">!</span>' : '';
            const titulo = c.title
                || `${c.patient?.lastName || ''}, ${c.patient?.name || ''}`.replace(/^,\s*/, '');
            const rut = c.patient?.rut
                ? `<span class="agenda-mes-cita-rut">${c.patient.rut}</span>`
                : '';

            return `
                <button type="button" class="agenda-mes-cita" data-cita-id="${c.id}" style="--cita-color:${color}">
                    <span class="agenda-mes-cita-hora">${hora}</span>
                    <span class="agenda-mes-cita-sala"><i class="bi bi-door-open me-1"></i>${nombreSalaPorItem(c)}</span>
                    <span class="agenda-mes-cita-paciente">${titulo}${lock}${alert}</span>
                    ${rut}
                </button>`;
        }).join('');

        return `
            <section class="agenda-mes-dia${hoyCls}" data-fecha="${key}">
                <header class="agenda-mes-dia-header">
                    <button type="button" class="agenda-mes-dia-titulo" data-fecha="${key}">
                        <span class="agenda-mes-dia-fecha">${label}</span>${badge}
                    </button>
                </header>
                <div class="agenda-mes-dia-cuerpo">${filas}</div>
            </section>`;
    }).join('');

    panel.innerHTML = `<div class="agenda-mes-bloques">${html}</div>`;
}

function initAgendaMesPanelEvents() {
    const panel = document.getElementById('agendaMesPanel');
    if (!panel || panel.dataset.bound === '1') return;
    panel.dataset.bound = '1';

    panel.addEventListener('click', (e) => {
        const citaBtn = e.target.closest('[data-cita-id]');
        if (citaBtn) {
            const appt = window.RIS?.agenda?.find((a) => String(a.id) === String(citaBtn.dataset.citaId));
            if (!appt) return;
            const estadosIniciales = ['pre-agendado', 'agendado', 'confirmado', 'espera'];
            if (!estadosIniciales.includes(appt.status)) {
                showToast('🔒 Esta cita ya ingresó al flujo clínico y no puede ser modificada desde Recepción.', 'warning');
                return;
            }
            abrirModalCita(appt);
            return;
        }

        const dayBtn = e.target.closest('.agenda-mes-dia-titulo');
        if (!dayBtn) return;
        if (typeof risRequireConcreteLabId === 'function' ? !risRequireConcreteLabId() : !localStorage.getItem('ris_lab_id')) {
            return;
        }
        const cfg = getAgendaScheduleConfig();
        const horaLab = (cfg.horaInicio || '08:00:00').split(':');
        const parts = String(dayBtn.dataset.fecha || '').split('-');
        if (parts.length < 3) return;
        const startSel = new Date(
            parseInt(parts[0], 10),
            parseInt(parts[1], 10) - 1,
            parseInt(parts[2], 10)
        );
        startSel.setHours(parseInt(horaLab[0], 10) || 8, parseInt(horaLab[1], 10) || 0, 0, 0);
        abrirModalCita({
            start: formatDateTimeLocal(redondearDatetimeAlIntervalo(startSel, cfg.intervalo)),
            machine: null,
        });
    });
}

function actualizarContextoVistaAgenda(view) {
    const el = document.getElementById('agendaVistaContexto');
    if (!el || !view) return;
    const hints = {
        resourceTimelineDay: 'Día: filas = salas · columnas = horas del laboratorio (de izquierda a derecha).',
        resourceTimeGridWeek: 'Semana: filas = salas · columnas = días · reloj a la izquierda indica la hora de cada cita.',
        agendaMes: 'Mes: un bloque por día del mes. Dentro van todas las citas (hora, sala, paciente). Día vacío = sin citas. Clic en la fecha para agendar.',
    };
    el.textContent = hints[view.type] || '';
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
            right: 'resourceTimelineDay,resourceTimeGridWeek,agendaMes',
        },
        buttonText: {
            today: 'Hoy',
            resourceTimelineDay: 'Día',
            resourceTimeGridWeek: 'Semana',
            agendaMes: 'Mes',
        },
        datesSet: function (arg) {
            montarUiInternaCalendario();
            actualizarContextoVistaAgenda(arg.view);
            const esMes = isAgendaVistaResumenMes(arg.view.type);
            calendar.setOption('editable', !esMes);
            calendar.setOption('eventResourceEditable', !esMes);
            calendar.setOption('selectable', !esMes);
            togglePanelMes(esMes);
            if (esMes) {
                renderAgendaMesBloques(arg.view.currentStart, arg.view.currentEnd, $('#searchAgenda').val() || '');
            } else {
                refrescarEventosCalendario($('#searchAgenda').val() || '');
            }
        },
        views: buildAgendaCalendarViews(configRIS),
        resourceAreaWidth: '18%',
        resourceAreaHeaderContent: 'Salas / equipos',
        allDaySlot: false,
        nowIndicator: true,
        slotMinTime: configRIS.horaInicio || '08:00:00',
        slotMaxTime: configRIS.horaFin || '20:00:00',
        slotDuration: slotDur,
        slotLabelInterval: '01:00:00',
        slotLabelFormat: {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        },
        eventOverlap: false,
        selectOverlap: false,
        resources: recursosData,
        events: [],
        selectable: true,
        editable: true,
        eventResourceEditable: true,
        droppable: true,
        select: function (info) {
            if (isAgendaVistaResumenMes(info.view.type)) {
                return;
            }
            if (typeof risRequireConcreteLabId === 'function' ? !risRequireConcreteLabId() : !localStorage.getItem("ris_lab_id")) {
                return;
            }
            let startSel = info.start;
            abrirModalCita({
                start: formatDateTimeLocal(redondearDatetimeAlIntervalo(startSel, configRIS.intervalo)),
                machine: info.resource ? info.resource.id : null,
            });
        },
        dateClick: function (info) {
            if (isAgendaVistaResumenMes(info.view.type)) {
                return;
            }
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
            if (isAgendaVistaResumenMes(info.view.type)) {
                info.revert();
                return;
            }
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

            const bgColor = arg.event.backgroundColor || '#7d2181';

            if (!patient) return { html: `<div class="p-1" style="background-color:${bgColor}; color:white; border-radius:3px;">${lockIcon}${arg.event.title}</div>` };

            const rangoHora = formatearRangoHoraEvento(arg.event.start, arg.event.end);

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
                    
                    <div class="text-truncate opacity-100 fw-bold" style="font-size: 0.7rem; opacity: 0.95;">
                        <i class="bi bi-clock me-1"></i>${rangoHora}
                    </div>
                    <div class="text-truncate opacity-100 mt-1" style="font-size: 0.72rem;">
                        <i class="bi bi-person-vcard me-1"></i>${patient.rut || ''}
                    </div>
                </div>`
            };
        }
    });

    calendar.render();
    montarUiInternaCalendario();
    sincronizarRecursosCalendario();
    } catch (err) {
        console.error('Error inicializando FullCalendar:', err);
        showToast('No se pudo dibujar el calendario. Recargue la página (Ctrl+F5).', 'danger');
    }
}

function filtrarAgendaItems(agenda, termRaw) {
    const term = risNormalizeAgendaSearch(termRaw);
    if (!term) return agenda || [];
    return (agenda || []).filter((item) => {
        const p = item.patient || {};
        const rut = risNormalizeAgendaSearch(p.rut);
        const nombres = risNormalizeAgendaSearch(
            `${p.name || ''} ${p.lastName || ''} ${p.secondLastName || ''}`
        );
        const title = risNormalizeAgendaSearch(item.title || '');
        return title.includes(term) || rut.includes(term) || nombres.includes(term);
    });
}

/** Scheduler oculta citas con resourceId en vista lista; en Mes no asignamos sala al objeto FC. */
function mapearEventosCalendario(agendaItems, viewType) {
    const esMes = viewType === 'agendaMes';
    return (agendaItems || []).map((item) => {
        const colorEstado = getHexColorEstado(item.status);
        const titulo = item.title
            || `${item.patient?.lastName || ''}, ${item.patient?.name || ''}`.replace(/^,\s*/, '');
        const ev = {
            id: String(item.id),
            title: titulo,
            start: item.start,
            end: item.end,
            color: colorEstado,
            textColor: AGENDA_ESTADO_TEXTO,
            display: esMes ? 'auto' : 'block',
            extendedProps: {
                patient: item.patient,
                status: item.status,
                statusRaw: item.statusRaw,
                needsReview: item.needsReview,
                returnReason: item.returnReason,
                machine: item.machine,
                resourceIds: item.resourceIds,
                mTratante: item.mTratante,
                mDestinado: item.mDestinado,
                priority: item.priority,
                procedencia: item.procedencia,
                payMethod: item.payMethod,
                paymentStatus: item.paymentStatus,
                transactionCode: item.transactionCode,
                tipoBono: item.tipoBono,
                entidadPagadora: item.entidadPagadora,
                studies: item.studies,
            },
        };
        if (!esMes && item.machine) {
            ev.resourceId = String(item.machine);
        }
        return ev;
    });
}

function refrescarEventosCalendario(searchTerm) {
    if (!calendar || !window.RIS?.agenda) return;
    const viewType = calendar.view?.type || 'resourceTimelineDay';
    if (viewType === 'agendaMes') {
        renderAgendaMesBloques(calendar.view.currentStart, calendar.view.currentEnd, searchTerm);
        return;
    }
    const items = filtrarAgendaItems(window.RIS.agenda, searchTerm);
    calendar.getEventSources().forEach((src) => src.remove());
    calendar.addEventSource(mapearEventosCalendario(items, viewType));
}

function getEventsFromRIS() {
    if (!window.RIS || !window.RIS.agenda) return [];
    const viewType = calendar?.view?.type || 'resourceTimelineDay';
    return mapearEventosCalendario(window.RIS.agenda, viewType);
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
                    start: normalizeApiDateTime(app.start_time),
                    end: normalizeApiDateTime(app.end_time),
                    statusRaw: app.status || 'pre-agendado',
                    status: risNormalizarEstadoAgendaVisual(app.status || 'pre-agendado'),
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
                        birthDate: risFormatBirthDateForInput(p.birth_date),
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
                refrescarEventosCalendario($('#searchAgenda').val() || '');
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

    if (typeof applyLabProfileUI === 'function') {
        applyLabProfileUI(document.getElementById('appointmentModal') || document);
    }
    if ($form.length) $form[0].reset();
    poblarSelectsAgenda();

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
        sincronizarHorariosCitaModal(data.start, data.end || null);
    } else {
        actualizarResumenBloquesCita();
    }

    if (data.id) {
        const p = data.patient || {};

        $("#pRut").val(p.rut || "").data('agendaLastSearch', (p.rut || '').trim().toUpperCase());
        $("#pName").val(capitalizarNombrePropio(p.name || ""));
        $("#pLastName").val(capitalizarNombrePropio(p.lastName || ""));
        $("#pSecondLastName").val(capitalizarNombrePropio(p.secondLastName || ""));
        $("#pSex").val(p.sex || "M");
        const birthVal = risFormatBirthDateForInput(p.birthDate);
        $("#pBirthDate").val(birthVal);
        risActualizarEdadPacienteAgenda();
        $("#pEmail").val(p.email || "");
        $("#pPhone").val(p.phone || "");

        setAgendaPrevision(p.insurance || null, p.plan || null);

        $("#agendaStatus").val(data.statusRaw || data.status || "pre-agendado").trigger("change");
        actualizarCtaAtencionSalas();
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
        $("#btnEliminarCita").removeClass("d-none");

    } else {
        $("#modalTitle").html('<i class="bi bi-calendar-plus me-2"></i>Nueva Cita Médica');
        $("#btnEliminarCita").addClass("d-none");
        $("#agendaStatus").val("pre-agendado").trigger("change");
        actualizarCtaAtencionSalas();

        addStudyRow('principal', { machine: data.machine });
        renderInsumos();
    }

    const appointmentId = $("#appointmentId").val();
    $("#btnRegistrarPago").toggleClass("d-none", !appointmentId);
    if (appointmentId && window.paymentManager) {
        window.paymentManager.cargarDesglose(appointmentId);
        window.paymentManager.cargarHistorialPagos(appointmentId);
        window.paymentManager.toggleFonasaPanel();
        window.paymentManager.cargarPreviewFonasa(appointmentId);
    }

    if (!data.id) {
        actualizarTerminoEstimadoDesdeExamenes();
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
    if (window._risGuardandoCita) {
        return;
    }
    window._risGuardandoCita = true;
    const btnGuardar = $("#btnGuardarCita");

    try {
    const idOriginal = $("#appointmentId").val();
    const rut = $("#pRut").val();
    const statusSeleccionado = $("#agendaStatus").val();
    const labId = typeof risRequireConcreteLabId === 'function'
        ? risRequireConcreteLabId()
        : localStorage.getItem("ris_lab_id");

    if (!labId) {
        return;
    }

    if (!validarDocumentoAgenda()) {
        return showToast("Documento del paciente inválido o incompleto.", "danger");
    }
    $("#pName").val(capitalizarNombrePropio($("#pName").val()));
    $("#pLastName").val(capitalizarNombrePropio($("#pLastName").val()));
    $("#pSecondLastName").val(capitalizarNombrePropio($("#pSecondLastName").val()));
    if (!validarFechaNacimientoAgenda()) {
        return;
    }
    if (!rut || !$("#pName").val() || !$("#pLastName").val()) {
        const pLabel = (typeof getLabProfile === 'function' ? getLabProfile().patient_label : 'Paciente');
        return showToast(`Faltan datos obligatorios (${pLabel}).`, "danger");
    }

    const startVal = $("#manualStartTime").val() || $("#selectedStart").val();
    if (!startVal) {
        return showToast("Seleccione un bloque en el calendario o ingrese la hora de inicio.", "warning");
    }
    $("#selectedStart").val(startVal);

    const todosLosEstudios = [];
    const salasInvolucradas = new Set();
    let errorEstudios = null;

    $(".study-entry").each(function () {
        const machine = $(this).find(".eMachine").val();
        const examId = $(this).find(".eExam").val();
        if (!machine && !examId) return;
        if (!machine) {
            errorEstudios = "Seleccione la sala en cada fila de examen.";
            return false;
        }
        if (!examId) {
            errorEstudios = "Seleccione el examen en cada fila agregada.";
            return false;
        }

        salasInvolucradas.add(machine);
        const subExamVal = $(this).find(".eSubExam").val();

        todosLosEstudios.push({
            machine_id: machine,
            exam_id: examId,
            exam_name: $(this).find(".eExam option:selected").text().trim(),
            sub_exam_name: $(this).find(".eSubExam option:selected").text().replace('--', '').trim() || null,
            sub_exam_id: (subExamVal && subExamVal !== "-") ? subExamVal : null,
            fonasa_code: $(this).find(".eCode").val() || null,
            quantity: parseInt($(this).find(".eQty").val()) || 1,
            price: parseFloat($(this).find(".ePrice").val()) || 0
        });
    });

    if (errorEstudios) {
        return showToast(errorEstudios, "warning");
    }

    if (todosLosEstudios.length === 0) {
        return showToast("Debe agregar al menos un examen con sala y prestación.", "warning");
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
        insurance_id: risNullableUuid($("#pInsurance").val()),
        insurance_plan_id: risNullableUuid($("#pPlan").val())
    };

    let duracionTotalMinutos = 0;
    salasInvolucradas.forEach(machineId => {
        const cantEnSala = todosLosEstudios.filter(s => s.machine_id === machineId).reduce((sum, s) => sum + s.quantity, 0);
        duracionTotalMinutos += calcularDuracionCita(machineId, cantEnSala);
    });

    const citaStart = new Date(startVal);
    if (Number.isNaN(citaStart.getTime())) {
        return showToast("El horario seleccionado no es válido.", "danger");
    }
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
        referring_doctor_id: risNullableUuid($("#mTratante").val()),
        destination_doctor_id: risNullableUuid($("#mDestinado").val()),
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

    btnGuardar.prop('disabled', true);

    const token = localStorage.getItem('ris_token');

    let url = `${API_URL}/appointments`;
    let method = 'POST';

    if (idOriginal) {
        // Si hay idOriginal, significa que estamos editando una cita existente
        url = `${API_URL}/appointments/${idOriginal}`;
        // Para enviar archivos (FormData) en edición, Laravel requiere POST + _method
        formData.append('_method', 'PUT');
    }

        const response = await fetch(url, {
            method: method,
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
            body: formData
        });

        let data = {};
        try {
            data = await response.json();
        } catch (e) {
            data = {};
        }

        if (!response.ok) {
            const msg = data.message || data.error || "No se pudo guardar la cita.";
            showToast(msg, "danger");
            return;
        }

        closeModal("appointmentModal");

        showToast("Cita guardada correctamente.", "success");

        if (!idOriginal && data.confirmation_email?.sent) {
            showToast(`📧 Confirmación de cita enviada a ${data.confirmation_email.email}.`, "info");
        }

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
        window._risGuardandoCita = false;
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
    const id = String($("#appointmentId").val() || '').trim();
    if (!id || id.startsWith('APP-')) {
        showToast("No hay una cita seleccionada para anular.", "warning");
        return;
    }

    if (!(await showConfirm(
        "¿Estás seguro de anular esta cita? Quedará registro en la auditoría.",
        { title: "Anular cita", dangerous: true, confirmText: "Anular", nested: true }
    ))) {
        return;
    }

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const $btn = $("#btnEliminarCita");
    const btnHtml = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Anulando...');

    try {
        const response = await fetch(`${API_URL}/appointments/${id}`, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });

        let data = {};
        try {
            data = await response.json();
        } catch (e) {
            data = {};
        }

        if (!response.ok) {
            const msg = data.message || data.error || `No se pudo anular la cita (HTTP ${response.status}).`;
            showToast(msg, "danger");
            return;
        }

        closeModal("appointmentModal");
        showToast("Cita anulada correctamente.", "warning");
        cargarAgendaDesdeServidor();
    } catch (e) {
        showToast(`Error al intentar anular la cita: ${e.message}`, "danger");
    } finally {
        $btn.prop('disabled', false).html(btnHtml);
    }
}

function getHexColorEstado(status) {
    const key = risNormalizarEstadoAgendaVisual(status);
    return AGENDA_ESTADO_COLORES[key] || '#7d2181';
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
            <td>
                <input type="text" class="form-control form-control-sm eExamQuery mb-1" placeholder="Código o nombre..." autocomplete="off" aria-label="Buscar examen por código o nombre">
                <select class="form-select form-select-sm eExam"><option value="">--</option></select>
            </td>
            <td><select class="form-select form-select-sm eSubExam"><option value="">--</option></select></td>
            <td><input type="number" class="form-control form-control-sm eQty text-center" value="${existingData ? existingData.qty || 1 : 1}" min="1"></td>
            <td><input type="text" class="form-control form-control-sm eCode text-center" value="${existingData ? existingData.code || '' : ''}" placeholder="Cód." autocomplete="off" aria-label="Código de prestación"></td>
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
    poblarSelectExamenesAgenda(newRow.find(".eExam"), newRow.find(".eMachine").val());
    if (existingData) {
        newRow.find(".eMachine").val(existingData.machine || '');
        poblarSelectExamenesAgenda(newRow.find(".eExam"), existingData.machine);
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

let _agendaPatientSearchKey = '';
let _agendaPatientSearchPromise = null;

/** Busca persona por documento (RUT/pasaporte) y rellena el formulario de cita. */
async function buscarPacientePorDocumentoAgenda(options = {}) {
    const $input = options.$input ? $(options.$input) : $('#pRut');
    if (!$input.length) return false;

    const tipoDoc = $('#pTipoDoc').val() || 'RUT';
    const doc = ($input.val() || '').trim().toUpperCase();
    if (!doc) return false;

    if (tipoDoc === 'RUT') {
        if (!validarRut(doc)) {
            if (typeof showToast === 'function') showToast('❌ RUT Inválido', 'danger');
            $input.addClass('is-invalid');
            return false;
        }
    } else if (doc.length < 4) {
        if (typeof showToast === 'function') showToast('Documento inválido.', 'warning');
        $input.addClass('is-invalid');
        return false;
    }

    $input.removeClass('is-invalid').addClass('is-valid');

    const searchKey = `${tipoDoc}|${doc}`;
    if (_agendaPatientSearchPromise && _agendaPatientSearchKey === searchKey) {
        return _agendaPatientSearchPromise;
    }

    _agendaPatientSearchKey = searchKey;
    _agendaPatientSearchPromise = (async () => {
        const token = localStorage.getItem('ris_token');
        const labId = localStorage.getItem('ris_lab_id');

        try {
            const labIdBusqueda =
                typeof risRequireConcreteLabId === 'function'
                    ? risRequireConcreteLabId(false)
                    : labId;
            if (!labIdBusqueda) {
                showToast('Seleccione una sede específica en la barra superior.', 'warning');
                return false;
            }

            const response = await fetch(
                `${API_URL}/patients/search?rut=${encodeURIComponent(doc)}`,
                {
                    headers: {
                        Accept: 'application/json',
                        Authorization: `Bearer ${token}`,
                        'X-Lab-Id': labIdBusqueda
                    }
                }
            );

            const rawText = await response.text();
            let data = null;
            try {
                data = rawText ? JSON.parse(rawText) : null;
            } catch (e) {
                console.error('Respuesta inválida al buscar paciente:', rawText);
                showToast('Error al interpretar la respuesta del servidor.', 'danger');
                return false;
            }

            if (response.ok && data?.success && data.data) {
                const payload = data.data;
                const persona = payload.persona;

                if (!persona) {
                    showToast('Respuesta incompleta: falta registro de persona.', 'danger');
                    return false;
                }

                $('#pName').val(capitalizarNombrePropio(persona.names || ''));
                $('#pLastName').val(capitalizarNombrePropio(persona.last_name_1 || ''));
                $('#pSecondLastName').val(capitalizarNombrePropio(persona.last_name_2 || ''));
                $('#pSex').val(persona.gender || 'M');
                $('#pEmail').val(persona.email || '');
                $('#pPhone').val(persona.phone || '');
                $('#pBirthDate').val(risFormatBirthDateForInput(persona.birth_date));
                risActualizarEdadPacienteAgenda();

                setAgendaPrevision(
                    payload.insurance_id || null,
                    payload.insurance_plan_id || null
                );

                if (payload.history_count && payload.history_count > 0) {
                    showToast(
                        `🔔 ${payload.history_count} cita(s) previa(s) en esta sede (registro global de persona).`,
                        'info'
                    );
                    $('#pName').addClass('border-info bg-info-subtle');
                } else if (payload.insurance_id) {
                    showToast(
                        'Previsión sugerida desde la última atención registrada.',
                        'info'
                    );
                }

                showToast('✅ Persona encontrada (registro global).', 'success');
                $input.data('agendaLastSearch', doc);
                return true;
            }

            if (response.status === 404 || (data && data.success === false)) {
                showToast(
                    'ℹ️ No hay persona con ese documento. Al guardar la cita se creará el registro.',
                    'info'
                );
                $('#pName, #pLastName, #pSecondLastName, #pBirthDate, #pEmail, #pPhone').val('');
                setAgendaPrevision(null);
                $('#pSex').val('M');
                $input.data('agendaLastSearch', doc);
                return true;
            }

            showToast(data?.message || `Error al buscar paciente (${response.status})`, 'danger');
            console.error(`Error del Servidor (${response.status}):`, rawText);
            return false;
        } catch (error) {
            console.error('Error buscando paciente:', error);
            return false;
        } finally {
            if (_agendaPatientSearchKey === searchKey) {
                _agendaPatientSearchPromise = null;
            }
        }
    })();

    return _agendaPatientSearchPromise;
}

/** Asegura búsqueda de paciente antes de validar el paso 1 del wizard (blur puede no haber corrido). */
async function ensureAgendaPacienteCargado() {
    const doc = ($('#pRut').val() || '').trim().toUpperCase();
    if (!doc) return;

    const lastDoc = $('#pRut').data('agendaLastSearch') || '';
    const namesFilled = $('#pName').val()?.trim() && $('#pLastName').val()?.trim();
    if (namesFilled && lastDoc === doc) return;

    await buscarPacientePorDocumentoAgenda();
}

function setupProEventListeners() {
    $(document).on('change', '#manualStartTime', function () {
        const v = $(this).val();
        if (v) $('#selectedStart').val(v);
        actualizarTerminoEstimadoDesdeExamenes();
    });
    $(document).on('change', '#manualEndTime', actualizarResumenBloquesCita);
    $(document).on('change', '#fileOrdenMedica', function (e) { procesarArchivoEscaner(e, 'orden'); });
    $(document).on('change', '#fileEncuesta', function (e) { procesarArchivoEscaner(e, 'encuesta'); });

    $(document).on('change.agendaPro', '#agendaStatus', function () {
        colorSelectorEstado();
        actualizarCtaAtencionSalas();
    });
    $(document).on('input', '.eQty', calculateTotal);
    window.addEventListener('ris_updated', actualizarCalendarioEnVivo);
    window.addEventListener('storage', (e) => { if (e.key === 'ris_app_data') actualizarCalendarioEnVivo(); });

    $(document).on('change.agendaPro', '#mTratante', function () {
        if ($(this).val() === 'NUEVO') {
            $('#modalNuevoMedico').modal('show');
            $(this).val('');
        }
    });

    $(document).on('change.agendaPro', '#pBirthDate', function () {
        risActualizarEdadPacienteAgenda();
    });

    $(document).on('change.agendaPro', '#payMethod', function () {
        const val = $(this).val();
        let lbl = 'Cód. Transacción';
        if (val === 'Cheque') lbl = 'N° Cheque';
        if (val === 'Tarjeta') lbl = 'Cód. ISWITCH';
        if (val === 'Transbank') lbl = 'Número de Operación';
        $('#lblTransactionCode').text(lbl);
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

    $(document).on('input.agendaPro', '#searchAgenda', function () {
        refrescarEventosCalendario($(this).val());
    });

    $(document).on('input.agendaPro', '#pRut', function () {
        $(this).removeData('agendaLastSearch');
        if (($('#pTipoDoc').val() || 'RUT') !== 'RUT') return;
        let actual = $(this).val().replace(/[^0-9kK]/g, '');
        if (actual.length === 0) {
            $(this).val('');
            return;
        }
        let rutPuntos = '';
        const cuerpo = actual.slice(0, -1);
        const dv = actual.slice(-1).toUpperCase();
        for (let i = cuerpo.length - 1, j = 1; i >= 0; i--, j++) {
            rutPuntos = cuerpo.charAt(i) + rutPuntos;
            if (j % 3 === 0 && i !== 0) rutPuntos = '.' + rutPuntos;
        }
        $(this).val(cuerpo.length > 0 ? rutPuntos + '-' + dv : dv);
    });

    $(document).on('blur.agendaPro', '#pRut', function () {
        buscarPacientePorDocumentoAgenda({ $input: this });
    });

    $(document).on('keydown.agendaPro', '#pRut', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            buscarPacientePorDocumentoAgenda({ $input: this });
        }
    });

    $(document).on('input.agendaPro', '.eExamQuery', function () {
        const $row = $(this).closest('tr');
        poblarSelectExamenesAgenda(
            $row.find('.eExam'),
            $row.find('.eMachine').val(),
            $(this).val()
        );
    });

    $(document).on('keydown.agendaPro', '.eExamQuery, .eCode', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const $row = $(this).closest('tr');
        const machineId = $row.find('.eMachine').val();
        if (!machineId) {
            showToast('Seleccione la sala antes de buscar el examen.', 'warning');
            return;
        }
        const query = $(this).val();
        const exam = risResolverExamenDesdeBusqueda(query, machineId);
        if (!exam) {
            if (String(query || '').trim()) {
                showToast('No se encontró examen con ese código o nombre para la sala.', 'warning');
            }
            return;
        }
        risSeleccionarExamenEnFila($row, exam.id);
    });

    $(document).on('blur.agendaPro', '.eCode', function () {
        const val = String($(this).val() || '').trim();
        if (!val) return;
        const $row = $(this).closest('tr');
        const machineId = $row.find('.eMachine').val();
        if (!machineId) return;
        const exam = risResolverExamenDesdeBusqueda(val, machineId);
        if (exam) {
            risSeleccionarExamenEnFila($row, exam.id);
            return;
        }
        const selectedId = $row.find('.eExam').val();
        if (selectedId) {
            const examData = (catalogosAgenda.exams || []).find((e) => String(e.id) === String(selectedId));
            if (examData) $(this).val(examData.fonasa_code || '');
        }
    });

    $(document).on('change.agendaPro', '#pInsurance', function () {
        poblarPlanesPrevision(risNullableUuid($(this).val()));
    });

    $(document).on('change.agendaPro', '#pPlan', function () {
        calculateTotal();
    });

    $(document).on("change", ".eMachine", function () {
        const row = $(this).closest("tr");
        const machineId = $(this).val();
        const examSelect = row.find(".eExam");
        const subSelect = row.find(".eSubExam");
        const prevExam = examSelect.val();

        subSelect.empty().append('<option value="">--</option>');
        row.find('.ePrice').val(0);
        row.find('.eCode').val('');
        row.find('.eExamQuery').val('');

        poblarSelectExamenesAgenda(examSelect, machineId);
        if (prevExam && examSelect.find(`option[value="${prevExam}"]`).length) {
            examSelect.val(prevExam).trigger("change");
        }
    });

    $(document).on("change", ".eExam", function () {
        const row = $(this).closest("tr");
        const examId = $(this).val();
        const subSelect = row.find(".eSubExam");

        subSelect.empty().append('<option value="">Sin variante</option>');

        if (!examId) {
            row.find('.eCode').val('');
            risSyncExamQueryLabel(row, null);
            return;
        }

        const examData = catalogosAgenda.exams.find(e => String(e.id) === String(examId));
        if (examData) {
            row.find(".ePrice").val(examData.price || 0);
            row.find(".eCode").val(examData.fonasa_code || '');
            risSyncExamQueryLabel(row, examData);

            if (examData.sub_exams && examData.sub_exams.length > 0) {
                examData.sub_exams.forEach(sub => {
                    subSelect.append(`<option value="${sub.id}" data-duration="${sub.duration}">${sub.name}</option>`);
                });
            }
        }
        calculateTotal();
        actualizarTerminoEstimadoDesdeExamenes();
    });
}

function buscarDisponibilidad() {
    const machine = $(".eMachine").val() || $('.eMachine').first().val();
    if (!machine) return showToast("Seleccione una sala de examen primero", "warning");

    const cfg = getAgendaScheduleConfig();
    const intervaloMin = intervaloAMinutos(cfg.intervalo);
    let candidato = redondearDatetimeAlIntervalo(new Date(), cfg.intervalo);
    candidato.setMinutes(candidato.getMinutes() + intervaloMin);

    const duracion = calcularDuracionCita(machine, 1) || intervaloMin;
    const finBusqueda = new Date(candidato.getTime() + 8 * 60 * 60000);
    let libre = null;

    while (candidato < finBusqueda) {
        const finCita = new Date(candidato.getTime() + duracion * 60000);
        const conflicto = (window.RIS.agenda || []).some((a) => {
            if (!a.resourceIds?.includes(String(machine))) return false;
            const aStart = new Date(a.start).getTime();
            const aEnd = new Date(a.end).getTime();
            return candidato.getTime() < aEnd && finCita.getTime() > aStart;
        });
        if (!conflicto) {
            libre = new Date(candidato);
            break;
        }
        candidato = new Date(candidato.getTime() + intervaloMin * 60000);
    }

    if (!libre) {
        return showToast('No se encontró hueco libre en las próximas horas para esa sala.', 'warning');
    }

    sincronizarHorariosCitaModal(libre, new Date(libre.getTime() + duracion * 60000));
    showToast(`Hora sugerida: ${libre.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })}`, 'success');
}

function subirDocumentoAgenda(tipo) {
    const inputId = tipo === 'orden' ? '#fileOrdenMedica' : '#fileEncuesta';
    $(inputId).val('');
    $(inputId).trigger('click');
}

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

function borrarDocumento(tipo) {
    if (tipo === 'orden') {
        $('#docOrdenMedica').val('');
        $('#fileOrdenMedica').val('');
        $('#btnVerOrden, #btnBorrarOrden').addClass('d-none');
    } else {
        $('#docEncuesta').val('');
        $('#fileEncuesta').val('');
        $('#btnVerEncuesta, #btnBorrarEncuesta').addClass('d-none');
    }
    if (typeof showToast === 'function') showToast('Documento eliminado.', 'info');
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
    const tipo = $('#pTipoDoc').val();
    const input = $('#pRut');
    const label = $('#lblDoc');

    input.val('').removeClass('is-invalid is-valid').removeData('agendaLastSearch');

    if (tipo === 'PASAPORTE') {
        label.text('N° Pasaporte / ID *');
        input.attr('placeholder', 'Ej. AB123456');
    } else {
        label.text('N° de RUT *');
        input.attr('placeholder', '12.345.678-9');
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

function refreshAgendaExamSelects() {
    $("#studyBody tr.study-entry").each(function () {
        const $row = $(this);
        poblarSelectExamenesAgenda($row.find(".eExam"), $row.find(".eMachine").val());
    });
}

window.initAgenda = initAgenda;
window.abrirModalCita = abrirModalCita;
window.guardarCita = guardarCita;
window.eliminarCita = eliminarCita;
window.refreshAgendaExamSelects = refreshAgendaExamSelects;
window.buscarPacientePorDocumentoAgenda = buscarPacientePorDocumentoAgenda;
window.ensureAgendaPacienteCargado = ensureAgendaPacienteCargado;