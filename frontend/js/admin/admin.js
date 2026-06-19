/* =========================================
   MÓDULO DE ADMINISTRACIÓN (admin.js)
   ========================================= */
let currentServicesFromDB = [];
let currentUsersFromDB = [];
let currentExamsFromDB = [];
let currentMachinesFromDB = [];
let currentAdminSuppliesFromDB = [];
let currentSucursalesFromDB = [];
let catalogRolesFromDB = [];
let catalogLabTypes = [];
let currentSucursalesAdmin = [];
let currentPlanesFromDB = [];
let catalogInsurances = [];
let currentPacientesAdmin = [];
let currentPlantillasFromDB = [];
let currentLaboratoriesTree = [];

const RIS_MIN_PASSWORD_LENGTH = 4;

function risHumanizarErrorValidacion(msg) {
    if (!msg || typeof msg !== 'string') return 'No se pudo guardar el usuario.';
    if (msg === 'validation.min.string') {
        return `La contraseña debe tener al menos ${RIS_MIN_PASSWORD_LENGTH} caracteres.`;
    }
    if (msg.startsWith('validation.')) return 'Datos inválidos. Revise los campos del formulario.';
    return msg;
}

/** Modalidades para prestaciones / plantillas. */
const RIS_EXAM_MODALITY_OPTIONS = [
    { value: 'CR', label: 'Radiografía CR — cassette digital (CR)' },
    { value: 'DX', label: 'Radiografía DX — detector digital (DX)' },
    { value: 'RX', label: 'Radiografía general (RX, legado)' },
    { value: 'CT', label: 'Tomografía / Scanner (CT)' },
    { value: 'MRI', label: 'Resonancia magnética (MRI)' },
    { value: 'US', label: 'Ecografía (US)' },
    { value: 'MAMO', label: 'Mamografía (MAMO)' },
    { value: 'DEXA', label: 'Densitometría ósea (DEXA)' },
    { value: 'CBCT', label: 'Tomografía cone beam (CBCT)' },
    { value: 'IO', label: 'Radiografía intraoral (IO)' },
    { value: 'NM', label: 'Medicina nuclear (NM)' },
    { value: 'PT', label: 'PET (PT)' },
    { value: 'RF', label: 'Fluoroscopia (RF)' },
    { value: 'XA', label: 'Angiografía (XA)' },
    { value: 'OT', label: 'Otro / General (OT)' },
];

/** Modalidades para equipos / salas (misma lista; CR y DX son salas distintas). */
const RIS_MACHINE_MODALITY_OPTIONS = RIS_EXAM_MODALITY_OPTIONS;

const RIS_MODALITY_ALIASES = {
    ECO: 'US',
    SCANNER: 'CT',
    TC: 'CT',
    RM: 'MRI',
    MR: 'MRI',
    MG: 'MAMO',
    DENSITO: 'DEXA',
};

function risNormalizarCodigoModalidad(code) {
    const c = String(code || '').toUpperCase().trim();
    return RIS_MODALITY_ALIASES[c] || c;
}

function risBadgeClassModalidad(grupo) {
    const g = risNormalizarCodigoModalidad(grupo);
    const map = {
        CR: 'bg-success',
        DX: 'bg-primary',
        RX: 'bg-primary',
        CT: 'bg-info text-dark',
        MRI: 'bg-danger',
        US: 'bg-success',
        MAMO: 'bg-warning text-dark',
        DEXA: 'bg-secondary',
        CBCT: 'bg-info text-dark',
        IO: 'bg-primary',
        NM: 'bg-dark',
        PT: 'bg-dark',
        RF: 'bg-secondary',
        XA: 'bg-secondary',
        OT: 'bg-light text-dark border',
    };
    return map[g] || 'bg-secondary';
}

function risPoblarSelectModalidades(selector, options = RIS_EXAM_MODALITY_OPTIONS) {
    const $sel = $(selector);
    if (!$sel.length) return;
    const selected = $sel.val();
    $sel.empty();
    options.forEach((m) => {
        $sel.append(`<option value="${m.value}">${m.label}</option>`);
    });
    if (selected) risAsegurarValorModalidad(selector, selected);
}

function risAsegurarValorModalidad(selector, code) {
    const $sel = $(selector);
    if (!$sel.length || code == null || code === '') return;
    const norm = risNormalizarCodigoModalidad(code);
    if (!$sel.find(`option[value="${norm}"]`).length) {
        $sel.append(`<option value="${norm}">${norm}</option>`);
    }
    $sel.val(norm);
}

function esAdminLogueado() {
    const perfil = localStorage.getItem('ris_user_profile') || '';

    return perfil === 'sis_admin' || perfil === 'admin' || perfil === 'super_admin';
}

function initAdmin() {
    if (typeof applyLabProfileUI === 'function') applyLabProfileUI();
    risPoblarSelectModalidades('#catGrupo');
    risPoblarSelectModalidades('#tplGrupo');
    risPoblarSelectModalidades('#salaGroup', RIS_MACHINE_MODALITY_OPTIONS);
    window.RIS = window.RIS || { users: [], personas: [], config: {} };
    renderListaUsuariosAdmin();
    renderListaInsumosAdmin();
    renderListaSalasAdmin();
    renderCatalogoAdmin();
    renderListaPlanesAdmin();
    renderListaPlantillasAdmin();
    cargarInsurancesAdmin();
    cargarConfigCentro();
    cargarCatalogoRoles();

    if (typeof cargarPacientes === "function") cargarPacientes();

    const fechaActual = new Date();
    const mesActual = `${fechaActual.getFullYear()}-${String(fechaActual.getMonth() + 1).padStart(2, '0')}`;
    $("#mesHonorarios").val(mesActual);
    $("#mesExamenes").val(mesActual);
    $("#fechaNomina").val(new Date().toISOString().split('T')[0]);
    $("#mesNomina").val(mesActual);
    $("#fechaCierreCaja").val(new Date().toISOString().split('T')[0]);
    $("#mesConsolidado").val(mesActual);

    renderReporteHonorarios();
    renderReporteExamenes();
    renderNominaDiaria();
    setupAdminEvents();
}

function setupAdminSync() {
    // Sincronización local eliminada; los datos se cargan desde la API.
}

function setupAdminEvents() {
    $("form").on("submit", function (e) {
        e.preventDefault();
    });
    $("#uRut").on("input", function () {
        let actual = $(this).val().replace(/[^0-9kK]/g, '');
        if (actual.length === 0) { $(this).val(""); return; }
        let rutPuntos = ""; let cuerpo = actual.slice(0, -1); let dv = actual.slice(-1).toUpperCase();
        for (let i = cuerpo.length - 1, j = 1; i >= 0; i--, j++) {
            rutPuntos = cuerpo.charAt(i) + rutPuntos;
            if (j % 3 === 0 && i !== 0) rutPuntos = "." + rutPuntos;
        }
        $(this).val(cuerpo.length > 0 ? rutPuntos + "-" + dv : dv);
    });

    $("#uRut").on("blur", function () {
        let rut = $(this).val().toUpperCase();
        if (!rut || !validarRut(rut)) {
            $(this).removeClass("is-valid").addClass("is-invalid border-danger");
            return;
        }
        $(this).removeClass("is-invalid border-danger").addClass("is-valid");

        if (window.RIS.users.find(u => u.rut === rut)) return;

        const p = (window.RIS.personas || []).find(per => per.rut === rut);
        if (p) {
            $("#uNombres").val(p.nombres || p.names);
            $("#uPrimerApellido").val(p.apellidoPaterno || p.last_name_1);
            $("#uSegundoApellido").val(p.apellidoMaterno || p.last_name_2);
            if (p.email) $("#uEmail").val(p.email);
            showToast("Identidad recuperada de la base de datos.", "info");
        }
    });
    $('button[data-bs-target="#tab-config"]').on('shown.bs.tab', function (e) {
        renderTablaSucursales();
    });
    $('button[data-bs-target="#tab-caja"]').on('shown.bs.tab', () => cargarCierreCaja());
    $('button[data-bs-target="#tab-cloud-sync"]').on('shown.bs.tab', () => cargarCloudSyncLogs());
    $('button[data-bs-target="#tab-hl7"]').on('shown.bs.tab', () => cargarHl7Messages());
    $('button[data-bs-target="#tab-consolidado"]').on('shown.bs.tab', () => cargarConsolidadoMatriz());
    $('button[data-bs-target="#tab-dte"]').on('shown.bs.tab', () => cargarListaDte());
}

let currentConsolidadoData = null;

let currentCierreCajaData = null;

function adminAuthHeaders(extra = {}) {
    if (typeof risBuildAuthHeaders === 'function') {
        return risBuildAuthHeaders(extra);
    }
    const headers = {
        Authorization: `Bearer ${localStorage.getItem('ris_token')}`,
        Accept: 'application/json',
        ...extra,
    };
    const labId = localStorage.getItem('ris_lab_id');
    if (labId && labId !== 'ALL') {
        headers['X-Lab-Id'] = labId;
    }
    return headers;
}

async function cargarCierreCaja() {
    const fecha = $("#fechaCierreCaja").val() || new Date().toISOString().split('T')[0];
    try {
        const res = await fetch(`${API_URL}/payments/reports/cash-close?fecha=${fecha}`, {
            headers: adminAuthHeaders(),
        });
        const json = await res.json();
        if (!res.ok || !json.success) throw new Error(json.message || 'Error al cargar cierre');

        currentCierreCajaData = json.data;
        const r = json.data.resumen;
        $("#cajaTotalCobrado").text('$' + (r.total_cobrado || 0).toLocaleString('es-CL'));
        $("#cajaTransacciones").text(r.transacciones || 0);
        $("#cajaCitasPendientes").text(r.citas_pendientes || 0);
        $("#cajaMontoPendiente").text('$' + (r.monto_pendiente_estimado || 0).toLocaleString('es-CL'));

        const tbodyPagos = $("#tablaCierreCajaPagos tbody").empty();
        (json.data.pagos || []).forEach(p => {
            const pac = p.appointment?.patient?.persona;
            const nombre = pac ? `${pac.names || ''} ${pac.last_name_1 || ''}`.trim() : '-';
            tbodyPagos.append(`<tr>
                <td class="small">${new Date(p.created_at).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })}</td>
                <td class="small">${nombre}</td>
                <td class="small">${p.payment_method}</td>
                <td class="text-end fw-bold">$${parseFloat(p.amount).toLocaleString('es-CL')}</td>
                <td><span class="badge bg-success-subtle text-success">${p.status}</span></td>
            </tr>`);
        });
        if (!json.data.pagos?.length) {
            tbodyPagos.append('<tr><td colspan="5" class="text-center text-muted small py-3">Sin cobros en esta fecha</td></tr>');
        }

        const tbodyPen = $("#tablaCierreCajaPendientes tbody").empty();
        (json.data.citas_pendientes || []).forEach(c => {
            tbodyPen.append(`<tr>
                <td>${c.hora || '-'}</td>
                <td>${c.paciente}</td>
                <td><span class="badge bg-warning-subtle text-warning">${c.payment_status}</span></td>
                <td class="text-end">$${parseFloat(c.monto_estimado || 0).toLocaleString('es-CL')}</td>
            </tr>`);
        });
        if (!json.data.citas_pendientes?.length) {
            tbodyPen.append('<tr><td colspan="4" class="text-center text-muted small py-3">Sin citas pendientes de cobro</td></tr>');
        }
    } catch (e) {
        showToast(e.message, 'danger');
    }
}

function exportarCierreCajaExcel() {
    if (!currentCierreCajaData) {
        showToast('Cargue el cierre de caja primero', 'warning');
        return;
    }
    const d = currentCierreCajaData;
    const rows = [
        ['Cierre de caja', d.fecha_label],
        [],
        ['Total cobrado', d.resumen.total_cobrado],
        ['Transacciones', d.resumen.transacciones],
        ['Citas pendientes', d.resumen.citas_pendientes],
        ['Monto pendiente estimado', d.resumen.monto_pendiente_estimado],
        [],
        ['COBROS'],
        ['Hora', 'Paciente', 'Método', 'Monto', 'Estado'],
    ];
    (d.pagos || []).forEach(p => {
        const pac = p.appointment?.patient?.persona;
        rows.push([
            new Date(p.created_at).toLocaleString('es-CL'),
            pac ? `${pac.names} ${pac.last_name_1}` : '',
            p.payment_method,
            p.amount,
            p.status,
        ]);
    });
    rows.push([], ['CITAS PENDIENTES'], ['Hora', 'Paciente', 'Estado', 'Monto est.']);
    (d.citas_pendientes || []).forEach(c => {
        rows.push([c.hora, c.paciente, c.payment_status, c.monto_estimado]);
    });
    descargarExcelXLSX(rows, `Cierre_Caja_${d.fecha}.xlsx`, 'Cierre');
    showToast('Cierre de caja exportado', 'success');
}

async function cargarCloudSyncStatus() {
    try {
        const res = await fetch(`${API_URL}/integrations/cloud-sync/status`, { headers: adminAuthHeaders() });
        const json = await res.json();
        if (!res.ok || !json.success) return;
        const d = json.data;
        const roleLabel = d.role === 'local' ? 'Laboratorio (envía a nube)' : 'Servidor central (recibe datos)';
        $("#cloudSyncRoleHint").text(
            `${roleLabel} · Cola: ${d.queue_connection || '—'} · ` +
            (d.can_push ? 'Envío configurado' : 'Envío no configurado')
        );
    } catch (e) {
        $("#cloudSyncRoleHint").text('');
    }
}

async function cargarCloudSyncLogs() {
    try {
        await cargarCloudSyncStatus();
        const res = await fetch(`${API_URL}/integrations/cloud-sync?limit=100`, {
            headers: adminAuthHeaders(),
        });
        const json = await res.json();
        if (!res.ok || !json.success) throw new Error(json.message || 'Error sync');

        const stats = json.data.stats;
        $("#resumenCloudSync").html(`
            <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><small class="text-muted">Pendientes</small><h5 class="fw-bold mb-0">${stats.pending}</h5></div></div></div>
            <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><small class="text-muted">OK</small><h5 class="fw-bold text-success mb-0">${stats.success}</h5></div></div></div>
            <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><small class="text-muted">Fallidos</small><h5 class="fw-bold text-danger mb-0">${stats.failed}</h5></div></div></div>
            <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><small class="text-muted">Últimas 24h</small><h5 class="fw-bold mb-0">${stats.last_24h}</h5></div></div></div>
            ${stats.skipped != null ? `<div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><small class="text-muted">Omitidos</small><h5 class="fw-bold mb-0">${stats.skipped}</h5></div></div></div>` : ''}
        `);

        const tbody = $("#tablaCloudSync tbody").empty();
        (json.data.logs || []).forEach(log => {
            const badge = log.status === 'success' ? 'success' : (log.status === 'failed' ? 'danger' : 'warning');
            const retryBtn = log.status === 'failed'
                ? `<button class="btn btn-sm btn-outline-primary" onclick="reintentarCloudSync('${log.id}')">Reintentar</button>`
                : '';
            tbody.append(`<tr>
                <td class="small">${new Date(log.created_at).toLocaleString('es-CL')}</td>
                <td class="small">${log.entity_type}</td>
                <td class="small">${log.action}</td>
                <td><span class="badge bg-${badge}-subtle text-${badge}">${log.status}</span></td>
                <td>${log.attempts}</td>
                <td class="small text-danger text-truncate" style="max-width:200px" title="${log.last_error || ''}">${log.last_error || '-'}</td>
                <td>${retryBtn}</td>
            </tr>`);
        });
        if (!json.data.logs?.length) {
            tbody.append('<tr><td colspan="7" class="text-center text-muted py-3">Sin registros de sincronización</td></tr>');
        }
    } catch (e) {
        showToast(e.message, 'danger');
    }
}

async function pullCatalogoDesdeNube(includePatients, includeUsers = true) {
    const labId = typeof risRequireConcreteLabId === 'function'
        ? risRequireConcreteLabId()
        : (localStorage.getItem('ris_lab_id') || '');
    if (!labId) {
        return;
    }
    const parts = ['catálogo'];
    if (includeUsers) parts.push('usuarios de la sede');
    if (includePatients) parts.push('pacientes');
    if (!(await showConfirm(
        `¿Importar ${parts.join(', ')} desde la nube?`,
        { title: 'Sincronizar desde nube', confirmText: 'Importar' }
    ))) {
        return;
    }
    try {
        const res = await fetch(`${API_URL}/integrations/cloud-sync/pull-catalog`, {
            method: 'POST',
            headers: { ...adminAuthHeaders(), 'Content-Type': 'application/json' },
            body: JSON.stringify({
                laboratory_id: labId,
                include_patients: !!includePatients,
                include_users: !!includeUsers,
            }),
        });
        const json = await res.json();
        if (!res.ok || !json.success) throw new Error(json.message || 'Error al importar');
        const counts = json.data?.counts || {};
        const detail = Object.entries(counts).map(([k, v]) => `${k}: ${v}`).join(', ');
        showToast(`${json.message} ${detail ? '(' + detail + ')' : ''}`, 'success', 8000);
    } catch (e) {
        showToast(e.message, 'danger');
    }
}

async function reintentarCloudSyncFallidos() {
    if (!(await showConfirm('¿Reenviar a la nube todos los registros pendientes o fallidos?', {
        title: 'Enviar pendientes',
        confirmText: 'Enviar',
    }))) {
        return;
    }
    try {
        const res = await fetch(`${API_URL}/integrations/cloud-sync/retry-failed`, {
            method: 'POST',
            headers: adminAuthHeaders(),
        });
        const json = await res.json();
        if (!res.ok || !json.success) throw new Error(json.message || 'Error');
        showToast(json.message, 'success');
        cargarCloudSyncLogs();
    } catch (e) {
        showToast(e.message, 'danger');
    }
}

async function reintentarCloudSync(id) {
    try {
        const res = await fetch(`${API_URL}/integrations/cloud-sync/${id}/retry`, {
            method: 'POST',
            headers: adminAuthHeaders(),
        });
        const json = await res.json();
        if (!res.ok || !json.success) throw new Error(json.message || 'No se pudo reintentar');
        showToast(json.message, 'success');
        cargarCloudSyncLogs();
    } catch (e) {
        showToast(e.message, 'danger');
    }
}

async function cargarConsolidadoMatriz() {
    const mes = $("#mesConsolidado").val() || new Date().toISOString().slice(0, 7);
    try {
        const res = await fetch(`${API_URL}/reports/consolidated-matrix?month=${mes}`, { headers: adminAuthHeaders() });
        const json = await res.json();
        if (!res.ok || !json.success) throw new Error(json.message || 'Error');
        currentConsolidadoData = json.data;
        const tbody = $("#tablaConsolidadoMatriz tbody").empty();
        (json.data.filas || []).forEach(f => {
            tbody.append(`<tr>
                <td>${f.nombre}</td>
                <td>${f.es_matriz ? '<span class="badge bg-primary-subtle text-primary">Matriz</span>' : 'Sucursal'}</td>
                <td class="text-end">${f.citas}</td>
                <td class="text-end">${f.entregados}</td>
                <td class="text-end">$${parseFloat(f.produccion).toLocaleString('es-CL')}</td>
                <td class="text-end">$${parseFloat(f.cobrado).toLocaleString('es-CL')}</td>
            </tr>`);
        });
        const t = json.data.totales;
        $("#filaTotalesConsolidado").html(`<td colspan="2">TOTAL RED</td><td class="text-end">${t.citas}</td><td class="text-end">${t.entregados}</td><td class="text-end">$${parseFloat(t.produccion).toLocaleString('es-CL')}</td><td class="text-end">$${parseFloat(t.cobrado).toLocaleString('es-CL')}</td>`);
    } catch (e) {
        showToast(e.message, 'danger');
    }
}

function exportarConsolidadoExcel() {
    if (!currentConsolidadoData) return;
    const rows = [['Consolidado matriz', currentConsolidadoData.mes], [], ['Sede', 'Tipo', 'Citas', 'Entregados', 'Producción', 'Cobrado']];
    currentConsolidadoData.filas.forEach(f => rows.push([f.nombre, f.es_matriz ? 'Matriz' : 'Sucursal', f.citas, f.entregados, f.produccion, f.cobrado]));
    rows.push([], ['TOTAL', '', currentConsolidadoData.totales.citas, currentConsolidadoData.totales.entregados, currentConsolidadoData.totales.produccion, currentConsolidadoData.totales.cobrado]);
    descargarExcelXLSX(rows, `Consolidado_${currentConsolidadoData.mes}.xlsx`, 'Consolidado');
    showToast('Exportado', 'success');
}

async function cargarListaDte() {
    try {
        const res = await fetch(`${API_URL}/billing/dte?limit=100`, { headers: adminAuthHeaders() });
        const json = await res.json();
        if (!res.ok || !json.success) throw new Error(json.message || 'Error DTE');
        const tbody = $("#tablaDteAdmin tbody").empty();
        (json.data || []).forEach(d => {
            tbody.append(`<tr>
                <td class="small">${new Date(d.created_at).toLocaleString('es-CL')}</td>
                <td>${d.document_type}</td>
                <td>${d.folio || '-'}</td>
                <td><span class="badge bg-secondary-subtle">${d.status}</span></td>
                <td class="text-end">$${parseFloat(d.monto_total).toLocaleString('es-CL')}</td>
                <td class="small">${d.receptor_name || '-'}</td>
            </tr>`);
        });
        if (!json.data?.length) tbody.append('<tr><td colspan="6" class="text-center text-muted py-3">Sin documentos emitidos</td></tr>');
    } catch (e) {
        showToast(e.message, 'danger');
    }
}

async function cargarHl7Messages() {
    try {
        const res = await fetch(`${API_URL}/integrations/hl7/messages?limit=80`, {
            headers: adminAuthHeaders(),
        });
        const json = await res.json();
        if (!res.ok || !json.success) throw new Error(json.message || 'Error HL7');

        const tbody = $("#tablaHl7Messages tbody").empty();
        (json.data || []).forEach(m => {
            tbody.append(`<tr>
                <td class="small">${new Date(m.created_at).toLocaleString('es-CL')}</td>
                <td class="small">${m.message_type}</td>
                <td>${m.direction || '-'}</td>
                <td><span class="badge bg-secondary-subtle">${m.status}</span></td>
                <td class="small">${m.placer_order_id || '-'}</td>
                <td class="small text-truncate">${m.appointment_id ? m.appointment_id.substring(0, 8) + '…' : '-'}</td>
            </tr>`);
        });
        if (!json.data?.length) {
            tbody.append('<tr><td colspan="6" class="text-center text-muted py-3">Sin mensajes HL7</td></tr>');
        }
    } catch (e) {
        showToast(e.message, 'danger');
    }
}

async function renderListaServiciosAdmin() {
    const tbody = $("#tablaServiciosAdmin tbody");
    if (!tbody.length) return;

    tbody.empty().append(`<tr><td colspan="4" class="text-center p-3"><span class="spinner-border spinner-border-sm text-info"></span> Cargando servicios...</td></tr>`);

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/services`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        const data = await response.json();
        tbody.empty();

        if (response.ok && data.success) {
            currentServicesFromDB = data.data;

            if (currentServicesFromDB.length === 0) {
                return tbody.append(`<tr><td colspan="4" class="text-center text-muted p-4">No hay servicios clínicos creados.</td></tr>`);
            }

            currentServicesFromDB.forEach(srv => {
                tbody.append(`
                    <tr>

                        <td class="fw-bold text-dark"><i class="bi bi-hospital me-2 text-muted"></i>${srv.name}</td>
                        <td class="text-muted">${srv.description || '--'}</td>
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-outline-info fw-bold text-dark" onclick="cargarServicio('${srv.id}')">
                                <i class="bi bi-pencil-square"></i> Editar
                            </button>
                        </td>
                    </tr>
                `);
            });
        }
    } catch (error) {
        tbody.empty().append(`<tr><td colspan="4" class="text-center text-danger p-4">Error de conexión.</td></tr>`);
    }
}

function nuevoServicio() {
    $("#formServicio")[0].reset();
    $("#srvId").val("");
    $(".req-srv").removeClass("is-invalid");
    $("#btnEliminarServicio").hide();
    $("#modalServicio").modal('show');
}

function cargarServicio(id) {
    const srv = currentServicesFromDB.find(s => s.id == id);
    if (!srv) return;

    $("#srvId").val(srv.id);
    $("#srvNombre").val(srv.name);
    $("#srvDesc").val(srv.description || "");

    $(".req-srv").removeClass("is-invalid");
    $("#btnEliminarServicio").show();
    $("#modalServicio").modal('show');
}

async function guardarServicio() {
    let hasError = false;
    if ($("#srvNombre").val().trim() === "") {
        $("#srvNombre").addClass("is-invalid"); hasError = true;
    } else {
        $("#srvNombre").removeClass("is-invalid");
    }

    if (hasError) return showToast("⚠️ Faltan datos obligatorios.", "danger");

    const srvData = {
        id: $("#srvId").val(),
        name: $("#srvNombre").val().trim(),
        description: $("#srvDesc").val().trim()
    };

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/services`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' },
            body: JSON.stringify(srvData)
        });

        const data = await response.json();
        if (response.ok && data.success) {
            $("#modalServicio").modal('hide');
            showToast(`✅ Servicio guardado exitosamente.`, "success");
            renderListaServiciosAdmin();
        } else {
            showToast(`❌ Error: ${data.message}`, "danger");
        }
    } catch (error) {
        showToast("🔌 Error de conexión", "danger");
    }
}

async function eliminarServicio() {
    const id = $("#srvId").val();
    if (!id) return;
    if (!(await showConfirm("¿Estás seguro de eliminar este servicio?", { dangerous: true, confirmText: "Eliminar" }))) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/services/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });

        if (response.ok) {
            $("#modalServicio").modal('hide');
            showToast("✅ Servicio eliminado.", "warning");
            renderListaServiciosAdmin();
        }
    } catch (error) { showToast("🔌 Error al eliminar", "danger"); }
}

async function cargarCatalogoRoles() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/roles`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'Authorization': `Bearer ${token}`,
                'X-Lab-Id': labId,
                'Accept': 'application/json'
            }
        });

        const contentType = response.headers.get("content-type");
        if (!contentType || !contentType.includes("application/json")) {
            const htmlError = await response.text();
            console.error("El servidor devolvió HTML en lugar de JSON. Error de ruta o Middleware:", htmlError);
            return;
        }

        const data = await response.json();

        if (response.ok && data.success) {
            catalogRolesFromDB = data.data;
            const contenedor = $("#contenedorRolesAdmin");
            contenedor.empty();

            catalogRolesFromDB.forEach(rol => {
                const slug = rol.name.toLowerCase().trim();
                contenedor.append(`
                    <div class="form-check">
                        <input class="form-check-input role-check req-user-role" type="checkbox" value="${slug}" id="rol_${rol.id}">
                        <label class="form-check-label fw-bold text-secondary" style="cursor:pointer;" for="rol_${rol.id}">
                            ${rol.description}
                        </label>
                    </div>
                `);
            });
        } else {
            console.error("Error lógico del backend:", data.message || data);
        }
    } catch (error) {
        console.error("Error de red al cargar los roles:", error);
    }
}

async function renderListaUsuariosAdmin() {
    const tbody = $("#tablaUsuariosAdmin tbody");
    if (!tbody.length) return;
    tbody.empty().append(`<tr><td colspan="6" class="text-center p-3"><span class="spinner-border spinner-border-sm text-primary"></span> Cargando...</td></tr>`);

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/users`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        const data = await response.json();
        tbody.empty();

        if (response.ok && data.success) {
            currentUsersFromDB = data.data;
            const searchStr = $("#searchUsuario").val().toLowerCase();

            const filtrados = currentUsersFromDB.filter(u => {
                const tipo = u.tipo_usuario?.name || u.tipoUsuario?.name || '';
                if (tipo === 'sis_admin') return false;
                const p = u.persona || {};
                const fullName = `${p.names || ''} ${p.last_name_1 || ''}`.toLowerCase();
                const email = (p.email || '').toLowerCase();
                return fullName.includes(searchStr)
                    || (u.username || '').toLowerCase().includes(searchStr)
                    || email.includes(searchStr);
            });

            if (filtrados.length === 0) return tbody.append(`<tr><td colspan="6" class="text-center p-4">No hay usuarios.</td></tr>`);

            filtrados.forEach(u => {
                const p = u.persona || {};
                const rolesArray = (u.settings && u.settings.roles) ? u.settings.roles : [];
                // Obtenemos el nombre del Tipo de Usuario (relación tipoUsuario en Laravel)
                const tipoPrincipal = u.tipo_usuario ? u.tipo_usuario.description : 'No asignado';

                const rolesBadges = rolesArray.map(r => `<span class="badge bg-light text-dark border me-1 small">${r.toUpperCase()}</span>`).join('');

                tbody.append(`
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold text-dark">${u.medical_title || ''} ${p.names || ''} ${p.last_name_1 || ''}</div>
                            <small class="text-muted">@${u.username}</small>
                            ${p.email ? `<div class="small text-secondary"><i class="bi bi-envelope me-1"></i>${p.email}</div>` : ''}
                        </td>
                        <td class="fw-bold text-secondary">${p.rut || '--'}</td>
                        <td><span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3">${tipoPrincipal}</span></td>
                        <td>${rolesBadges}</td>
                        <td class="small text-muted">
                            ${u.pacs_ae ? `<div><i class="bi bi-display me-1"></i>${u.pacs_ae}</div>` : '--'}
                        </td>
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-outline-primary fw-bold" onclick="cargarUsuario('${u.id}')">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </td>
                    </tr>
                `);
            });
        }
    } catch (error) { tbody.append(`<tr><td colspan="6" class="text-center text-danger">Error de conexión.</td></tr>`); }
}

function nuevoUsuario() {
    abrirModalUsuario(null);
}

function abrirModalUsuario(id = null) {
    $("#adminForm")[0].reset();
    $("#uId").val("");
    $("#uRut").prop("disabled", false).removeClass("is-valid is-invalid");
    $(".req-user").removeClass("is-valid is-invalid");
    $(".role-check").prop("checked", false);
    $("#btnEliminarUsuario").hide();

    // 1. Dibujamos los switches de sucursales SIEMPRE
    renderCheckboxesSucursales();
    $(".chk-lab").prop("checked", false);

    if (id) {
        const u = currentUsersFromDB.find(user => user.id === id);
        if (!u) return;
        const p = u.persona || {};

        $("#uId").val(u.id);
        $("#uRut").val(p.rut).prop("disabled", true).removeClass("is-invalid is-valid");
        $("#uNombres").val(p.names || "");
        $("#uPrimerApellido").val(p.last_name_1 || "");
        $("#uSegundoApellido").val(p.last_name_2 || "");
        $("#uEmail").val(p.email || "");

        $("#uTitulo").val(u.medical_title || "");
        $("#uUsername").val(u.username || "");
        $("#uPassword").val("");

        $("#uDragonProfile").val(u.dragon_profile || "");

        // 2. Marcar switches de sucursales correctos
        if (u.laboratories && u.laboratories.length > 0) {
            u.laboratories.forEach(lab => {
                $(`#chkLab_${lab.id}`).prop("checked", true);
            });
        }

        if (u.settings && u.settings.roles) {
            u.settings.roles.forEach(r => {
                $(`.role-check[value="${r}"]`).prop("checked", true);
            });
        }
        $("#btnEliminarUsuario").show();
    }

    const esAdmin = esAdminLogueado();
    $("#uUsername").prop("disabled", !esAdmin);
    $(".chk-lab").prop("disabled", !esAdmin); // Bloquear sucursales si no es admin
    $(".role-check").prop("disabled", !esAdmin);

    $("#modalUsuario").modal('show');
}

async function guardarUsuario() {
    let hasError = false;
    $(".req-user").each(function () {
        if ($(this).attr('id') === 'uPassword' && $("#uId").val() !== "") return;
        let valor = $(this).val();
        if (!valor || valor.trim() === "") {
            $(this).addClass("is-invalid");
            hasError = true;
        } else {
            $(this).removeClass("is-invalid").addClass("is-valid");
        }
    });

    const rolesSeleccionados = [];
    $(".role-check:checked").each(function () { rolesSeleccionados.push($(this).val()); });

    if (rolesSeleccionados.length === 0) return showToast("⚠️ Debe seleccionar al menos un rol.", "danger");

    // CAPTURAR SUCURSALES (SWITCHES)
    const sucursalesSeleccionadas = $(".chk-lab:checked").map(function () { return $(this).val(); }).get();
    if (sucursalesSeleccionadas.length === 0) return showToast("⚠️ Debe asignar al menos una sucursal al usuario.", "warning");

    const esRadiologo = rolesSeleccionados.includes('radiologo');

    if (hasError) return showToast("⚠️ Faltan datos obligatorios.", "danger");
    const rut = $("#uRut").val().toUpperCase();
    if (!validarRut(rut)) return showToast("❌ RUT inválido.", "danger");

    const esNuevo = $("#uId").val() === "";
    const password = $("#uPassword").val();
    if (password && password.length < RIS_MIN_PASSWORD_LENGTH) {
        $("#uPassword").addClass("is-invalid");
        return showToast(`❌ La contraseña debe tener al menos ${RIS_MIN_PASSWORD_LENGTH} caracteres.`, "danger");
    }
    if (esNuevo && !password) {
        $("#uPassword").addClass("is-invalid");
        return showToast("❌ La contraseña es obligatoria para usuarios nuevos.", "danger");
    }
    $("#uPassword").removeClass("is-invalid");

    const email = $("#uEmail").val().trim();
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        $("#uEmail").addClass("is-invalid");
        return showToast("❌ Correo electrónico inválido.", "danger");
    }
    $("#uEmail").removeClass("is-invalid");

    const formData = new FormData();
    formData.append('rut', rut);
    formData.append('nombres', $("#uNombres").val().trim());
    formData.append('apellidoPaterno', $("#uPrimerApellido").val().trim());
    formData.append('apellidoMaterno', $("#uSegundoApellido").val().trim());
    if (email) formData.append('email', email);
    formData.append('titulo', $("#uTitulo").val());

    // 🔥 CORRECCIÓN: Enviar siempre el username, aunque esté deshabilitado en el HTML
    formData.append('username', $("#uUsername").val().trim());
    formData.append('password', $("#uPassword").val());
    formData.append('dragonProfile', $("#uDragonProfile").val().trim());

    // Adjuntar las sucursales al formulario
    sucursalesSeleccionadas.forEach(labId => formData.append('laboratories[]', labId));

    if (!$(".role-check").prop("disabled")) {
        rolesSeleccionados.forEach(rol => formData.append('roles[]', rol));
    }

    if ($("#uId").val() !== "") formData.append('id', $("#uId").val());

    const firmaFile = document.getElementById('uFirma').files[0];
    if (firmaFile) formData.append('signature', firmaFile);

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/users`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' },
            body: formData
        });
        const data = await response.json();

        if (response.ok && data.success) {
            $("#modalUsuario").modal('hide');
            const msgEmail = email && data.keycloak_synced
                ? ' Usuario guardado. Si es nuevo en Keycloak, se envió correo de activación.'
                : '';
            showToast(`✅ Usuario guardado correctamente.${msgEmail}`, "success");
            renderListaUsuariosAdmin();
        } else {
            let msg = data.message || 'No se pudo guardar el usuario.';
            if (data.errors && typeof data.errors === 'object') {
                const firstField = Object.keys(data.errors)[0];
                if (firstField && data.errors[firstField]?.[0]) {
                    msg = data.errors[firstField][0];
                }
            }
            showToast(`❌ Error: ${risHumanizarErrorValidacion(msg)}`, "danger");
            console.log(data);
        }
    } catch (e) { showToast("🔌 Error de conexión", "danger"); }
}

async function eliminarUsuario() {
    const id = $("#uId").val();
    if (!id) return;
    if (!(await showConfirm("¿Revocar acceso a este usuario en la Base de Datos?", { dangerous: true, confirmText: "Revocar" }))) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/users/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        if (response.ok) {
            $("#modalUsuario").modal('hide');
            showToast("Acceso revocado.", "warning");
            renderListaUsuariosAdmin();
        }
    } catch (e) { }
}

async function renderListaInsumosAdmin() {
    const tbody = $("#tablaInsumosAdmin tbody");
    if (!tbody.length) return;
    tbody.empty().append(`<tr><td colspan="5" class="text-center p-3"><span class="spinner-border spinner-border-sm text-primary"></span> Cargando bodega...</td></tr>`);

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/supplies`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        const data = await response.json();
        tbody.empty();

        if (response.ok && data.success) {
            currentAdminSuppliesFromDB = data.data;
            const searchStr = $("#searchInsumo").val().toLowerCase();

            const inventarioAgrupado = {};
            currentAdminSuppliesFromDB.forEach(s => {
                if (!inventarioAgrupado[s.category]) inventarioAgrupado[s.category] = [];
                inventarioAgrupado[s.category].push(s);
            });

            let totalItems = 0;
            for (const categoria in inventarioAgrupado) {
                inventarioAgrupado[categoria].forEach(ins => {
                    if (searchStr && !ins.name.toLowerCase().includes(searchStr)) return;
                    totalItems++;

                    const pct = (ins.stock / (ins.max_stock || 1)) * 100;
                    let badgeClass = 'bg-success';
                    let statusText = 'Stock Óptimo';

                    if (pct <= 10) { badgeClass = 'bg-danger pulse-danger'; statusText = 'CRÍTICO'; }
                    else if (pct <= 30) { badgeClass = 'bg-warning text-dark'; statusText = 'Stock Bajo'; }

                    tbody.append(`
                        <tr>
                            <td class="ps-4 fw-bold text-secondary">${categoria}</td>
                            <td class="fw-bold text-dark">${ins.name}</td>
                            <td class="text-center fs-5 fw-bold ${pct <= 10 ? 'text-danger' : 'text-primary'}">${ins.stock}</td>
                            <td class="text-center text-muted">${ins.max_stock}</td>
                            <td class="text-center pe-4">
                                <span class="badge ${badgeClass} mb-2 d-block">${statusText}</span>
                                <button class="btn btn-sm btn-outline-dark fw-bold w-100" onclick="cargarInsumo('${ins.id}')">
                                    <i class="bi bi-arrow-repeat"></i> Reponer
                                </button>
                            </td>
                        </tr>
                    `);
                });
            }

            if (totalItems === 0) tbody.append(`<tr><td colspan="5" class="text-center text-muted p-4">La bodega está vacía.</td></tr>`);
        }
    } catch (error) { tbody.empty().append(`<tr><td colspan="5" class="text-center text-danger p-4">Error de conexión.</td></tr>`); }
}

function nuevoInsumo() {
    $("#formInsumo")[0].reset();
    $("#insId").val("");
    $("#insCategoria").prop("disabled", false);
    $(".req-ins").removeClass("is-invalid");
    $("#btnEliminarInsumo").hide();
    $("#modalInsumo").modal('show');
}

function cargarInsumo(id) {
    const insumo = currentAdminSuppliesFromDB.find(i => i.id === id);
    if (!insumo) return;

    $("#insId").val(insumo.id);
    $("#insCategoria").val(insumo.category).prop("disabled", true);
    $("#insNombre").val(insumo.name);
    $("#insStock").val(insumo.stock);
    $("#insTotal").val(insumo.max_stock);

    $(".req-ins").removeClass("is-invalid");
    $("#btnEliminarInsumo").show();
    $("#modalInsumo").modal('show');
}

async function guardarInsumo() {
    let hasError = false;
    $(".req-ins").each(function () {
        if ($(this).val().trim() === "") { $(this).addClass("is-invalid"); hasError = true; }
        else { $(this).removeClass("is-invalid"); }
    });
    if (hasError) return showToast("⚠️ Faltan datos del insumo.", "danger");

    const stockActual = parseInt($("#insStock").val());
    const stockMax = parseInt($("#insTotal").val());
    if (stockActual > stockMax) return showToast("⚠️ El stock actual no puede superar el máximo.", "warning");

    const insumoData = {
        id: $("#insId").val(),
        category: $("#insCategoria").val(),
        name: $("#insNombre").val().trim(),
        stock: stockActual,
        max_stock: stockMax
    };

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/supplies`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' },
            body: JSON.stringify(insumoData)
        });
        if (response.ok) {
            $("#modalInsumo").modal('hide');
            showToast(`✅ Inventario actualizado en BD.`, "success");
            renderListaInsumosAdmin();
        }
    } catch (e) { showToast("🔌 Error al guardar insumo", "danger"); }
}

async function eliminarInsumo() {
    const id = $("#insId").val();
    if (!id) return;
    if (!(await showConfirm("¿Eliminar este insumo de la base de datos?", { dangerous: true, confirmText: "Eliminar" }))) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/supplies/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        if (response.ok) {
            $("#modalInsumo").modal('hide');
            showToast("Insumo eliminado.", "warning");
            renderListaInsumosAdmin();
        }
    } catch (e) { }
}

async function renderListaSalasAdmin() {
    const tbody = $("#tablaSalasAdmin tbody");
    if (!tbody.length) return;
    tbody.empty().append(`<tr><td colspan="4" class="text-center p-3"><span class="spinner-border spinner-border-sm text-primary"></span> Cargando...</td></tr>`);

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/machines`, {
            headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Content-Type': 'application/json' }
        });
        const data = await response.json();
        tbody.empty();

        if (response.ok && data.success) {
            currentMachinesFromDB = data.data;
            const searchStr = $("#searchSala").val() ? $("#searchSala").val().toLowerCase() : "";
            const filtradas = currentMachinesFromDB.filter(m => m.name.toLowerCase().includes(searchStr) || (m.group || '').toLowerCase().includes(searchStr));

            if (filtradas.length === 0) return tbody.append(`<tr><td colspan="4" class="text-center text-muted p-4">Sin equipos.</td></tr>`);

            filtradas.forEach(res => {
                const badgeColor = risBadgeClassModalidad(res.group);
                tbody.append(`
                    <tr>
                        <td class="fw-bold text-dark">
                            <i class="bi bi-display me-2 text-muted"></i>${res.name}
                            <small class="d-block text-muted" style="font-size:0.7rem">${res.manufacturer || ''} ${res.model_name || ''}</small>
                        </td>
                        <td><span class="badge ${badgeColor} px-3 py-2">${res.group}</span></td>
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-outline-success fw-bold me-1" onclick="pingDicom('${res.id}')" title="Diagnóstico: PACS MWL (4242) + TCP al equipo Fuji (puede fallar y ser normal)">
                                <i class="bi bi-wifi"></i> Red/MWL
                            </button>
                            <button class="btn btn-sm btn-outline-info fw-bold text-dark" onclick="cargarSala('${res.id}')">
                                <i class="bi bi-pencil-square"></i> Editar
                            </button>
                        </td>
                    </tr>
                `);
            });
        }
    } catch (error) { tbody.append(`<tr><td colspan="4" class="text-center text-danger p-4">Error de conexión.</td></tr>`); }
}

function cargarSala(id) {
    limpiarFormulario(".req-sala");
    $("#salaId").val("");
    $("#salaName, #salaGroup, #salaAeTitle, #salaIp, #salaPort, #salaManufacturer, #salaModel, #salaDescription").val("");

    if (id) {
        const sala = currentMachinesFromDB.find(s => String(s.id) === String(id));
        if (sala) {
            $("#salaId").val(sala.id);
            $("#salaName").val(sala.name);
            risAsegurarValorModalidad('#salaGroup', sala.group);
            $("#salaManufacturer").val(sala.manufacturer);
            $("#salaModel").val(sala.model_name);
            $("#salaDescription").val(sala.description);
            // Cargar campos DICOM
            $("#salaAeTitle").val(sala.ae_title || "");
            $("#salaIp").val(sala.ip_address || "");
            $("#salaPort").val(sala.port || "");
        }
        $("#btnEliminarSala").show();
    } else {
        $("#btnEliminarSala").hide();
    }
    $("#modalSala").modal('show');
}

async function guardarSala() {
    if (!validarFormulario(".req-sala")) return;

    const labId = typeof risRequireConcreteLabId === 'function'
        ? risRequireConcreteLabId()
        : localStorage.getItem('ris_lab_id');
    if (!labId) {
        return;
    }

    const payload = {
        id: $("#salaId").val(),
        name: $("#salaName").val(),
        group: $("#salaGroup").val(),
        manufacturer: $("#salaManufacturer").val(),
        model_name: $("#salaModel").val(),
        description: $("#salaDescription").val(),
        // Capturar campos DICOM
        ae_title: $("#salaAeTitle").val(),
        ip_address: $("#salaIp").val(),
        port: $("#salaPort").val() ? parseInt($("#salaPort").val()) : null
    };

    const token = localStorage.getItem('ris_token');
    const btn = $("#btnGuardarSala").length ? $("#btnGuardarSala") : $("#modalSala .btn-info.fw-bold").last();
    const btnLabel = btn.text().trim() || "Guardar Sala";

    try {
        btn.prop("disabled", true).html('<span class="spinner-border spinner-border-sm"></span> Guardando...');

        const response = await fetch(`${API_URL}/machines`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        if (response.ok && data.success) {
            $("#modalSala").modal('hide');
            showToast("✅ Equipo guardado exitosamente.", "success");
            renderListaSalasAdmin(); // Asegúrate de tener esta función para recargar la tabla
        } else {
            showToast(`❌ Error: ${data.message}`, "danger");
        }
    } catch (e) {
        showToast("🔌 Error de red", "danger");
    } finally {
        btn.prop("disabled", false).text(btnLabel);
    }
}

async function cargarConfigCentro() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/settings`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        const data = await response.json();

        if (response.ok && data.success) {
            catalogLabTypes = data.lab_types || [];
            const selects = $("#cfgTipo, #sucTipo");
            selects.empty().append('<option value="">Seleccione Tipo...</option>');
            catalogLabTypes.forEach(t => selects.append(`<option value="${t.id}">${t.name}</option>`));

            const lab = data.data || {};
            $("#cfgTipo").val(lab.laboratory_type_id);
            $("#cfgNombre").val(lab.name);
            $("#cfgDireccion").val(lab.address || "");
            $("#cfgCiudad").val(lab.city || "");
            $("#cfgTelefono").val(lab.phone || "");
            $("#cfgEmail").val(lab.email || "");

            if (lab.settings) {
                $("#cfgHoraInicio").val(lab.settings.horaInicio || "");
                $("#cfgHoraFin").val(lab.settings.horaFin || "");
                $("#cfgIntervalo").val(lab.settings.intervalo || "00:15:00");
                $("#cfgColorInforme").val(lab.settings.colorInforme || "#000000");
            }

            if (typeof esAdminLogueado === 'function' && esAdminLogueado()) {

                const esSysAdmin = typeof risIsSysAdmin === 'function'
                    ? risIsSysAdmin()
                    : (localStorage.getItem('ris_user_profile') === 'sis_admin');

                if (esSysAdmin) {
                    $("#btnNuevaMatriz").removeClass("d-none");
                    const selectMatriz = $("#matrizTipo");
                    selectMatriz.empty().append('<option value="">Seleccione Tipo...</option>');
                    catalogLabTypes.forEach(t => selectMatriz.append(`<option value="${t.id}">${t.name}</option>`));
                }

                try {
                    const labsEndpoint = esSysAdmin ? `${API_URL}/all-laboratories` : `${API_URL}/laboratories`;
                    const resAll = await fetch(labsEndpoint, {
                        headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
                    });
                    const dataAll = await resAll.json();

                    if (resAll.ok && dataAll.success) {
                        currentLaboratoriesTree = dataAll.data;
                        currentSucursalesAdmin = dataAll.data.flatMap(padre => padre.children || []);

                        if (esSysAdmin) {
                            actualizarOpcionesLaboratorioGlobal(dataAll.data);
                        } else {
                            dataAll.data.forEach((matriz) => {
                                actualizarOpcionesLaboratorioUsuario(matriz, matriz.children || []);
                            });
                        }
                    }
                } catch (err) { console.error("Error cargando laboratorios del admin", err); }
            } else {
                let matrizLocal = data.data || {};
                matrizLocal.children = data.children || [];
                currentLaboratoriesTree = [matrizLocal];

                currentSucursalesAdmin = data.children || [];
                actualizarOpcionesLaboratorioUsuario(data.data, data.children || []);
            }

            renderTablaSucursales();
            setTimeout(() => {
                if ($("#tablaSucursalesAdmin tbody tr").length <= 1) {
                    renderTablaSucursales();
                }
            }, 200);
        }
    } catch (e) { console.error(e); }
}

function actualizarOpcionesLaboratorioGlobal(todosLosPadres) {
    const $select = $("#uLaboratorio");
    if (!$select.length) return;

    $select.empty().append('<option value="">Seleccione laboratorios...</option>');

    todosLosPadres.forEach(padre => {
        let htmlGroup = `<optgroup label="${padre.name} (Matriz)">`;
        htmlGroup += `<option value="${padre.id}">${padre.name}</option>`;

        if (padre.children && padre.children.length > 0) {
            padre.children.forEach(suc => {
                htmlGroup += `<option value="${suc.id}"> ↳ ${suc.name}</option>`;
            });
        }
        htmlGroup += `</optgroup>`;
        $select.append(htmlGroup);
    });
}
function actualizarOpcionesLaboratorioUsuario(matriz, sucursales) {
    const $select = $("#uLaboratorio");
    if (!$select.length) return;

    $select.empty().append('<option value="">Seleccione un laboratorio...</option>');

    if (matriz) {
        $select.append(`
            <optgroup label="Casa Matriz">
                <option value="${matriz.id}">${matriz.name} (Principal)</option>
            </optgroup>
        `);
    }

    if (sucursales && sucursales.length > 0) {
        let htmlSuc = `<optgroup label="Sucursales">`;
        sucursales.forEach(s => {
            htmlSuc += `<option value="${s.id}"> ↳ ${s.name}</option>`;
        });
        htmlSuc += `</optgroup>`;
        $select.append(htmlSuc);
    }
}

async function guardarConfigCentroAdmin() {
    const formData = new FormData();
    formData.append('laboratory_type_id', $("#cfgTipo").val());
    formData.append('name', $("#cfgNombre").val().trim());
    formData.append('address', $("#cfgDireccion").val().trim());
    formData.append('city', $("#cfgCiudad").val().trim());
    formData.append('phone', $("#cfgTelefono").val().trim());
    formData.append('email', $("#cfgEmail").val().trim());

    const settings = {
        horaInicio: $("#cfgHoraInicio").val(),
        horaFin: $("#cfgHoraFin").val(),
        intervalo: $("#cfgIntervalo").val(),
        colorInforme: $("#cfgColorInforme").val()
    };
    formData.append('settings', JSON.stringify(settings));

    const logoFile = document.getElementById('cfgLogo').files[0];
    if (logoFile) {
        formData.append('logo', logoFile);
    }

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/settings`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' },
            body: formData
        });
        if (response.ok) {
            window.RIS = window.RIS || {};
            window.RIS.config = { ...(window.RIS.config || {}), ...settings };
            if (typeof window.aplicarConfigAgendaHorario === 'function') {
                window.aplicarConfigAgendaHorario(settings);
            }
            showToast("✅ Matriz actualizada.", "success");
        } else {
            const data = await response.json().catch(() => ({}));
            showToast(`❌ ${data.message || 'No se pudo guardar la configuración.'}`, "danger");
        }
    } catch (e) { showToast("Error al guardar", "danger"); }
}

function renderTablaSucursales() {
    const $tbody = $("#tablaSucursalesAdmin tbody");

    $tbody.empty();
    if (!currentSucursalesAdmin || currentSucursalesAdmin.length === 0) {
        $tbody.append(`<tr><td colspan="5" class="text-center text-muted p-4">No hay sucursales registradas.</td></tr>`);
        return;
    }

    currentSucursalesAdmin.forEach(suc => {
        console.log("Sucursal:", suc);
        const activo = (suc.is_active == 1 || suc.is_active === true);
        const statusBadge = activo ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-danger">Inactiva</span>';

        $tbody.append(`
            <tr>
                <td class="ps-3 fw-bold text-dark"><i class="bi bi-building me-2 text-muted"></i>${suc.name}</td>
                <td><div class="small">${suc.address || '--'}</div><div class="small text-muted">${suc.city || ''}</div></td>
                <td>${suc.phone || '--'}</td>
                <td class="text-center">${statusBadge}</td>
                <td class="text-center pe-3">
                    <button class="btn btn-sm btn-outline-primary fw-bold" onclick="cargarSucursal('${suc.id}')"><i class="bi bi-pencil-square"></i> Editar</button>
                </td>
            </tr>
        `);
    });
    console.log($tbody)
}

function nuevaSucursal() {
    $("#formSucursal")[0].reset();
    $("#sucId").val("");
    $("#sucActiva").prop("checked", true);
    $("#btnEliminarSucursal").hide();
    $("#modalSucursal").modal('show');
}

function cargarSucursal(id) {
    const suc = currentSucursalesAdmin.find(s => s.id == id);
    if (!suc) return;
    $("#sucId").val(suc.id);
    $("#sucTipo").val(suc.laboratory_type_id || "");
    $("#sucNombre").val(suc.name || "");
    $("#sucDireccion").val(suc.address || "");
    $("#sucCiudad").val(suc.city || "");
    $("#sucTelefono").val(suc.phone || "");
    $("#sucActiva").prop("checked", !!suc.is_active);
    $("#btnEliminarSucursal").show();
    $("#modalSucursal").modal('show');
}

async function guardarSucursal() {
    const payload = {
        id: $("#sucId").val(),
        laboratory_type_id: $("#sucTipo").val(),
        name: $("#sucNombre").val().trim(),
        address: $("#sucDireccion").val().trim(),
        city: $("#sucCiudad").val().trim(),
        phone: $("#sucTelefono").val().trim(),
        is_active: $("#sucActiva").is(":checked")
    };
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    try {
        const response = await fetch(`${API_URL}/branches`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (response.ok) {
            $("#modalSucursal").modal('hide');
            showToast("✅ Sucursal guardada.", "success");
            cargarConfigCentro();
        }
    } catch (e) { showToast("Error al guardar", "danger"); }
}

async function eliminarSucursal() {
    const id = $("#sucId").val();
    if (!id) return;
    if (!(await showConfirm("¿Eliminar sucursal?", { dangerous: true, confirmText: "Eliminar" }))) return;
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    try {
        const response = await fetch(`${API_URL}/branches/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        if (response.ok) {
            $("#modalSucursal").modal('hide');
            showToast("Sucursal eliminada.", "warning");
            cargarConfigCentro();
        }
    } catch (e) { showToast("Error al eliminar", "danger"); }
}

async function renderCatalogoAdmin() {
    const tbody = $("#tablaCatalogoAdmin tbody");
    if (!tbody.length) return;

    tbody.empty().append(`<tr><td colspan="6" class="text-center p-3"><span class="spinner-border spinner-border-sm text-primary"></span> Cargando catálogo...</td></tr>`);

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/exams`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        const data = await response.json();
        tbody.empty();

        if (response.ok && data.success) {
            currentExamsFromDB = data.data;
            const searchStr = $("#searchCat").val() ? $("#searchCat").val().toLowerCase() : "";

            const examTypes = {};
            currentExamsFromDB.forEach(ex => {
                if (!examTypes[ex.group_code]) examTypes[ex.group_code] = { exams: {} };
                examTypes[ex.group_code].exams[ex.name] = {
                    id: ex.id, code: ex.fonasa_code, price: ex.price, subs: ex.sub_exams || [],
                    instruction: ex.instruction || null
                };
            });

            let totalExamenes = 0;
            Object.keys(examTypes).forEach(grupo => {
                const examenes = examTypes[grupo].exams || {};
                Object.keys(examenes).forEach(nombreExamen => {
                    const exData = examenes[nombreExamen];
                    if (searchStr && !nombreExamen.toLowerCase().includes(searchStr) && !(exData.code || '').toLowerCase().includes(searchStr)) return;
                    totalExamenes++;

                    const badgeColor = risBadgeClassModalidad(grupo);

                    const instr = exData.instruction;
                    const hasInstr = instr && instr.body && instr.is_active;
                    const instrBadge = hasInstr
                        ? `<span class="badge bg-success" title="Se envían por correo al agendar"><i class="bi bi-envelope-check"></i> Sí</span>`
                        : `<span class="badge bg-light text-muted border">—</span>`;

                    tbody.append(`
                        <tr>
                            <td class="ps-4"><span class="badge ${badgeColor}">${grupo}</span></td>
                            <td class="fw-bold text-dark">${nombreExamen}
                                <small class="d-block text-muted" style="font-size: 0.75rem;">${(exData.subs || []).map(risNombreSubExamenAdmin).filter(Boolean).join(", ")}</small>
                            </td>
                            <td class="font-monospace text-secondary">${exData.code || '--'}</td>
                            <td class="text-end fw-bold text-success">$${parseFloat(exData.price).toLocaleString('es-CL')}</td>
                            <td class="text-center">${instrBadge}</td>
                            <td class="text-center pe-4">
                                <button class="btn btn-sm btn-outline-danger fw-bold" onclick="cargarExamen('${exData.id}')">
                                    <i class="bi bi-pencil-square"></i> Editar
                                </button>
                            </td>
                        </tr>
                    `);
                });
            });

            if (totalExamenes === 0) tbody.append(`<tr><td colspan="6" class="text-center text-muted p-4">No se encontraron prestaciones.</td></tr>`);
        }
    } catch (error) {
        tbody.empty().append(`<tr><td colspan="6" class="text-center text-danger p-4">Error de conexión.</td></tr>`);
    }
}

function risNombreSubExamenAdmin(sub) {
    if (!sub) return '';
    if (typeof sub === 'string') return sub.trim();
    return String(sub.name || '').trim();
}

function nuevoExamen() {
    $("#formExamen")[0].reset();
    $("#catId").val("");
    $("#catInstrActive").prop('checked', true);
    $(".req-cat").removeClass("is-invalid");
    $("#btnEliminarExamen").hide();
    $("#modalExamen").modal('show');
}

function cargarExamen(id) {
    const ex = currentExamsFromDB.find(e => e.id === id);
    if (!ex) return;

    $("#catId").val(ex.id);
    risAsegurarValorModalidad('#catGrupo', ex.group_code);
    $("#catNombre").val(ex.name);
    $("#catCodigo").val(ex.fonasa_code);
    $("#catPrecio").val(ex.price);
    $("#catSubs").val((ex.sub_exams || []).map(risNombreSubExamenAdmin).filter(Boolean).join(", "));

    const instr = ex.instruction;
    $("#catInstrSubject").val(instr?.subject || "");
    $("#catInstrBody").val(instr?.body || "");
    $("#catInstrActive").prop('checked', instr ? instr.is_active !== false : true);

    $(".req-cat").removeClass("is-invalid");
    $("#btnEliminarExamen").show();
    $("#modalExamen").modal('show');
}

async function guardarExamen() {
    let hasError = false;
    $(".req-cat").each(function () {
        if ($(this).val().trim() === "") { $(this).addClass("is-invalid"); hasError = true; }
        else { $(this).removeClass("is-invalid"); }
    });
    if (hasError) return showToast("⚠️ Complete los datos requeridos.", "danger");

    const subsArray = $("#catSubs").val().split(',').map(s => s.trim()).filter(s => s !== "");

    const examData = {
        id: $("#catId").val(),
        group_code: $("#catGrupo").val(),
        name: $("#catNombre").val().trim(),
        fonasa_code: $("#catCodigo").val().trim(),
        price: $("#catPrecio").val(),
        sub_exams: subsArray,
        instruction: {
            subject: $("#catInstrSubject").val().trim() || null,
            body: $("#catInstrBody").val().trim(),
            is_active: $("#catInstrActive").is(':checked')
        }
    };

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/exams`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' },
            body: JSON.stringify(examData)
        });
        const data = await response.json();

        if (response.ok && data.success) {
            $("#modalExamen").modal('hide');
            showToast("✅ Arancel guardado con éxito.", "success");
            renderCatalogoAdmin();
        } else {
            showToast(`❌ Error: ${data.message}`, "danger");
        }
    } catch (error) {
        showToast("🔌 Error de conexión", "danger");
    }
}

async function eliminarExamen() {
    const id = $("#catId").val();
    if (!id) return;
    if (!(await showConfirm("¿Eliminar permanentemente este examen del catálogo?", { dangerous: true, confirmText: "Eliminar" }))) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/exams/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        if (response.ok) {
            $("#modalExamen").modal('hide');
            showToast("Examen eliminado del catálogo.", "warning");
            renderCatalogoAdmin();
        }
    } catch (error) { showToast("Error al eliminar", "danger"); }
}

let currentReporteHonorariosData = [];
let currentReporteExamenesData = [];
let currentReporteDiasMes = 30;
let currentNominaDiaria = null;

async function renderReporteHonorarios() {
    const tbody = $("#tablaHonorariosAdmin tbody");
    if (!tbody.length) return;

    const mesSeleccionado = $("#mesHonorarios").val();
    const porcentajeComision = parseFloat($("#porcentajeComision").val()) / 100;
    if (!mesSeleccionado || isNaN(porcentajeComision)) return;

    tbody.empty().append(`<tr><td colspan="5" class="text-center p-4"><span class="spinner-border spinner-border-sm text-primary"></span> Calculando honorarios...</td></tr>`);
    $("#totalHonorariosGlobal").text("Calculando...");

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/reports/honorarios?month=${mesSeleccionado}`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        const res = await response.json();
        tbody.empty();

        if (res.success) {
            currentReporteHonorariosData = res.data || [];
            let granTotalHonorarios = 0;
            const medicosArray = currentReporteHonorariosData;

            if (medicosArray.length === 0) {
                tbody.append(`<tr><td colspan="5" class="text-center text-muted p-5"><i class="bi bi-file-earmark-x fs-1 d-block mb-2"></i>No hay informes firmados en este mes.</td></tr>`);
                $("#totalHonorariosGlobal").text("$0");
                return;
            }

            medicosArray.forEach(med => {
                const honorarios = Math.round(med.totalFacturado * porcentajeComision);
                granTotalHonorarios += honorarios;

                tbody.append(`
                    <tr>
                        <td class="ps-4 fw-bold text-dark"><i class="bi bi-person-check-fill text-primary me-2"></i>${med.nombre}</td>
                        <td class="text-center fw-bold">${med.informes}</td>
                        <td class="text-center">${med.examenes}</td>
                        <td class="text-end text-muted">$${parseFloat(med.totalFacturado).toLocaleString('es-CL')}</td>
                        <td class="text-end pe-4 fw-bold text-success fs-6">$${honorarios.toLocaleString('es-CL')}</td>
                    </tr>
                `);
            });

            $("#totalHonorariosGlobal").text(`$${granTotalHonorarios.toLocaleString('es-CL')}`);
        } else {
            currentReporteHonorariosData = [];
        }
    } catch (e) {
        currentReporteHonorariosData = [];
        tbody.empty().append(`<tr><td colspan="5" class="text-center text-danger p-4">Error al cargar honorarios.</td></tr>`);
    }
}

$(document).on('change', '#mesHonorarios, #porcentajeComision', renderReporteHonorarios);

function validarRut(rut) {
    let valor = rut.replace(/\./g, '');
    if (!/^[0-9]+[-|‐][0-9kK]{1}$/.test(valor)) return false;
    let tmp = valor.split('-');
    let digv = tmp[1].toLowerCase();
    let rutCuerpo = tmp[0];
    let suma = 0; let multiplo = 2;
    for (let i = 1; i <= rutCuerpo.length; i++) {
        suma = suma + (multiplo * valor.charAt(rutCuerpo.length - i));
        if (multiplo < 7) multiplo = multiplo + 1; else multiplo = 2;
    }
    let res = 11 - (suma % 11);
    let vlp = (res == 11) ? 0 : (res == 10) ? 'k' : res;
    return vlp == digv;
}

$(document).on('change', '#mesExamenes', renderReporteExamenes);
$(document).on('change', '#fechaNomina', renderNominaDiaria);

async function renderNominaDiaria() {
    const tbody = $("#tablaNominaDiaria tbody");
    if (!tbody.length) return;

    const fecha = $("#fechaNomina").val();
    if (!fecha) return;

    tbody.empty().append(`<tr><td colspan="9" class="text-center p-4"><span class="spinner-border spinner-border-sm text-primary"></span> Cargando nómina...</td></tr>`);
    $("#totalNominaDia").text('...');

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/reports/nomina-diaria?date=${fecha}`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        const res = await response.json();
        tbody.empty();

        if (!res.success) {
            currentNominaDiaria = null;
            tbody.append(`<tr><td colspan="9" class="text-center text-danger p-4">${res.message || 'Error al cargar nómina.'}</td></tr>`);
            $("#totalNominaDia").text('0');
            return;
        }

        currentNominaDiaria = res;
        $("#totalNominaDia").text(res.data.length);

        if (res.data.length === 0) {
            tbody.append(`<tr><td colspan="9" class="text-center text-muted p-5">Sin pacientes agendados para esta fecha.</td></tr>`);
            return;
        }

        res.data.forEach(row => {
            tbody.append(`
                <tr>
                    <td class="text-center">${row.numero}</td>
                    <td class="fw-bold">${row.nombre_paciente}</td>
                    <td>${row.rut || ''}</td>
                    <td class="text-center">${row.edad ?? ''}</td>
                    <td class="small">${row.rx_intracoral || ''}</td>
                    <td class="small">${row.cone_beam || ''}</td>
                    <td class="text-end fw-bold">$${Number(row.total_boleta || 0).toLocaleString('es-CL')}</td>
                    <td>${row.radiologo || ''}</td>
                    <td class="small">${row.institucion || ''}</td>
                </tr>
            `);
        });
    } catch (e) {
        currentNominaDiaria = null;
        tbody.empty().append(`<tr><td colspan="9" class="text-center text-danger p-4">Error de conexión al cargar nómina.</td></tr>`);
        $("#totalNominaDia").text('0');
    }
}

function obtenerResponsableReporte() {
    try {
        const user = JSON.parse(localStorage.getItem('ris_user_data') || '{}');
        const persona = user.persona || user;
        const nombre = `${persona.names || user.names || ''} ${persona.last_name_1 || user.last_name_1 || ''}`.trim();
        return nombre.toUpperCase() || 'ADMINISTRADOR';
    } catch {
        return 'ADMINISTRADOR';
    }
}

function encabezadosNominaRDOX() {
    return [
        'N°', 'NOMBRE PACIENTE', 'RUT', 'EDAD', 'RX INTRACORAL', 'CONE BEAM',
        'BOLETA', 'TOTAL BOLETA', 'EFECTIVO', 'TRANSBANK', 'TRANSFERENCIA', 'BONO',
        'RADIOLOGO', 'OPERADOR', 'DENTISTAS', 'INSTITUCION', 'OBSERVACION'
    ];
}

function construirMatrizNominaRDOX(nomina) {
    if (!nomina || !nomina.data) return [];

    const matriz = [];
    matriz.push([`AGENDA DIARIA ${nomina.centro} ${nomina.ciudad}`]);
    matriz.push(['NOMINA PACIENTES PARA INFORME']);
    matriz.push([nomina.fecha_formato || nomina.fecha]);
    matriz.push([]);
    matriz.push(encabezadosNominaRDOX());

    nomina.data.forEach(row => {
        matriz.push([
            row.numero,
            row.nombre_paciente,
            row.rut,
            row.edad,
            row.rx_intracoral,
            row.cone_beam,
            row.boleta,
            row.total_boleta,
            row.efectivo,
            row.transbank,
            row.transferencia,
            row.bono,
            row.radiologo,
            row.operador,
            row.dentistas,
            row.institucion,
            row.observacion
        ]);
    });

    const t = nomina.totales || {};
    matriz.push([
        'TOTAL', '', '', '', '', '', '',
        t.total_boleta || 0,
        t.efectivo || 0,
        t.transbank || 0,
        t.transferencia || 0,
        t.bono || 0,
        '', '', '', '', ''
    ]);
    matriz.push([]);
    matriz.push([`RESPONSABLE: ${obtenerResponsableReporte()}`]);

    return matriz;
}

function descargarMatrizCSV(datos, nombreArchivo) {
    let csv = '\uFEFF';
    datos.forEach(fila => {
        const row = (fila || []).map(celda => {
            let v = celda == null ? '' : String(celda);
            v = v.replace(/"/g, '""');
            return /[;"\n\r]/.test(v) ? `"${v}"` : v;
        });
        csv += row.join(';') + '\n';
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = nombreArchivo.endsWith('.csv') ? nombreArchivo : `${nombreArchivo}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

function exportarNominaDiariaCSV() {
    if (!currentNominaDiaria || !currentNominaDiaria.data?.length) {
        return showToast('No hay datos de nómina para exportar.', 'warning');
    }
    const matriz = construirMatrizNominaRDOX(currentNominaDiaria);
    descargarMatrizCSV(matriz, `Nomina_RDOX_${currentNominaDiaria.fecha}.csv`);
    showToast('Nómina exportada a CSV', 'success');
}

function exportarNominaDiariaExcel() {
    if (!currentNominaDiaria || !currentNominaDiaria.data?.length) {
        return showToast('No hay datos de nómina para exportar.', 'warning');
    }
    const matriz = construirMatrizNominaRDOX(currentNominaDiaria);
    descargarExcelXLSX(matriz, `Nomina_RDOX_${currentNominaDiaria.fecha}.xlsx`, 'Nomina');
    showToast('Nómina exportada a Excel', 'success');
}

function sanitizarNombreHojaExcel(nombre) {
    return String(nombre || 'Dia')
        .replace(/[\\/?*\[\]:]/g, ' ')
        .trim()
        .substring(0, 31) || 'Dia';
}

function descargarExcelMultihoja(hojas, nombreArchivo) {
    if (typeof XLSX === 'undefined') {
        return showToast('SheetJS no está cargado. Recargue la página (Ctrl+F5).', 'danger');
    }

    const libro = XLSX.utils.book_new();
    const nombresUsados = new Set();

    hojas.forEach(({ nombre, matriz }) => {
        let nombreHoja = sanitizarNombreHojaExcel(nombre);
        let base = nombreHoja;
        let i = 2;
        while (nombresUsados.has(nombreHoja)) {
            const sufijo = ` ${i}`;
            nombreHoja = sanitizarNombreHojaExcel(base.substring(0, 31 - sufijo.length) + sufijo);
            i++;
        }
        nombresUsados.add(nombreHoja);

        const hoja = XLSX.utils.aoa_to_sheet(matriz);
        hoja['!cols'] = Array(17).fill({ wch: 16 });
        XLSX.utils.book_append_sheet(libro, hoja, nombreHoja);
    });

    XLSX.writeFile(libro, nombreArchivo);
}

async function cargarNominaMensual(mes) {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    const response = await fetch(`${API_URL}/reports/nomina-mensual?month=${mes}`, {
        headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
    });

    const res = await response.json();
    if (!response.ok || !res.success) {
        throw new Error(res.message || 'No se pudo cargar la nómina mensual.');
    }
    return res;
}

async function exportarNominaMensualExcel() {
    const mes = $("#mesNomina").val();
    if (!mes) return showToast('Seleccione un mes para exportar.', 'warning');

    const $btn = $('button[onclick="exportarNominaMensualExcel()"]');
    const textoOriginal = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Generando...');

    try {
        const res = await cargarNominaMensual(mes);
        if (!res.dias || res.dias.length === 0) {
            return showToast('No hay citas registradas en ese mes.', 'warning');
        }

        const hojas = res.dias.map(dia => ({
            nombre: dia.hoja_nombre || dia.fecha_formato || dia.fecha,
            matriz: construirMatrizNominaRDOX(dia)
        }));

        descargarExcelMultihoja(hojas, `Nomina_Mensual_RDOX_${mes}.xlsx`);
        showToast(`Excel generado: ${hojas.length} hoja(s)`, 'success');
    } catch (e) {
        console.error(e);
        showToast(e.message || 'Error al exportar nómina mensual.', 'danger');
    } finally {
        $btn.prop('disabled', false).html(textoOriginal);
    }
}

async function exportarNominaMensualCSV() {
    const mes = $("#mesNomina").val();
    if (!mes) return showToast('Seleccione un mes para exportar.', 'warning');

    const $btn = $('button[onclick="exportarNominaMensualCSV()"]');
    const textoOriginal = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Generando...');

    try {
        const res = await cargarNominaMensual(mes);
        if (!res.dias || res.dias.length === 0) {
            return showToast('No hay citas registradas en ese mes.', 'warning');
        }

        let matrizCompleta = [];
        matrizCompleta.push([`NOMINAS DIARIAS RDOX PORTAL - ${res.mes_formato || mes}`]);
        matrizCompleta.push([`${res.centro} ${res.ciudad}`]);
        matrizCompleta.push([]);

        res.dias.forEach((dia, idx) => {
            if (idx > 0) {
                matrizCompleta.push([]);
                matrizCompleta.push(['========================================']);
                matrizCompleta.push([]);
            }
            matrizCompleta = matrizCompleta.concat(construirMatrizNominaRDOX(dia));
        });

        if (res.totales_mes) {
            matrizCompleta.push([]);
            matrizCompleta.push(['RESUMEN DEL MES']);
            matrizCompleta.push([
                'PACIENTES', res.totales_mes.pacientes,
                'TOTAL BOLETA', res.totales_mes.total_boleta,
                'EFECTIVO', res.totales_mes.efectivo,
                'TRANSBANK', res.totales_mes.transbank,
                'TRANSFERENCIA', res.totales_mes.transferencia,
                'BONO', res.totales_mes.bono
            ]);
        }

        descargarMatrizCSV(matrizCompleta, `Nomina_Mensual_RDOX_${mes}.csv`);
        showToast(`CSV generado: ${res.dias.length} día(s)`, 'success');
    } catch (e) {
        console.error(e);
        showToast(e.message || 'Error al exportar nómina mensual.', 'danger');
    } finally {
        $btn.prop('disabled', false).html(textoOriginal);
    }
}

async function renderReporteExamenes() {
    const tbody = $("#tablaExamenesAdmin tbody");
    if (!tbody.length) return;

    const mesSeleccionado = $("#mesExamenes").val();
    if (!mesSeleccionado) return;

    tbody.empty().append(`<tr><td colspan="3" class="text-center p-4"><span class="spinner-border spinner-border-sm text-primary"></span> Calculando producción...</td></tr>`);
    $("#totalExamenesGlobal").text("...");

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/reports/examenes?month=${mesSeleccionado}`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        const res = await response.json();
        tbody.empty();

        if (res.success) {
            currentReporteExamenesData = res.data;
            currentReporteDiasMes = res.dias_del_mes;

            if (currentReporteExamenesData.length === 0) {
                tbody.append(`<tr><td colspan="3" class="text-center text-muted p-5"><i class="bi bi-folder-x fs-1 d-block mb-2"></i>No hay producción registrada en este mes.</td></tr>`);
                $("#totalExamenesGlobal").text("0");
                return;
            }

            let granTotal = 0;
            currentReporteExamenesData.forEach(est => {
                granTotal += est.total;
                tbody.append(`
                    <tr>
                        <td class="fw-bold text-dark ps-4"><i class="bi bi-file-medical text-primary me-2"></i>${est.examen}</td>
                        <td class="text-muted">${est.sala}</td>
                        <td class="text-center fw-bold fs-5 text-dark pe-4">${est.total}</td>
                    </tr>
                `);
            });

            $("#totalExamenesGlobal").text(granTotal);
        }
    } catch (e) {
        tbody.empty().append(`<tr><td colspan="3" class="text-center text-danger p-4">Error al cargar la producción.</td></tr>`);
    }
}

function descargarCSV(filename, tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;

    let csv = '\uFEFF';
    const rows = table.querySelectorAll('tr');

    for (let i = 0; i < rows.length; i++) {
        const row = [];
        const cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length; j++) {
            if (cols[j].innerText.trim() !== "Acciones") {
                let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').trim();
                data = data.replace(/"/g, '""');
                row.push('"' + data + '"');
            }
        }
        csv += row.join(';') + '\n';
    }

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

/**
 * Exportar Honorarios a Excel
 * Similar a la imagen: Agenda Diaria RDOX Portal
 */
function exportarExcelHonorarios() {
    const mes = $("#mesHonorarios").val();
    const porcentajeComision = parseFloat($("#porcentajeComision").val()) / 100;

    if (!currentReporteHonorariosData || currentReporteHonorariosData.length === 0) {
        return showToast("No hay datos de honorarios para exportar.", "warning");
    }

    let excelData = [];
    excelData.push(['LIQUIDACIÓN DE HONORARIOS - RDOX PORTAL']);
    excelData.push([`Mes: ${mes}`]);
    excelData.push([]);
    excelData.push([
        'MÉDICO RADIÓLOGO', 'INFORMES FIRMADOS', 'EXÁMENES', 'PRODUCCIÓN ($)', 'HONORARIOS ($)'
    ]);

    let totalInformes = 0;
    let totalExamenes = 0;
    let totalProduccion = 0;
    let totalHonorarios = 0;

    currentReporteHonorariosData.forEach(med => {
        const honorarios = Math.round(med.totalFacturado * porcentajeComision);
        excelData.push([
            med.nombre,
            med.informes,
            med.examenes,
            med.totalFacturado,
            honorarios
        ]);
        totalInformes += med.informes;
        totalExamenes += med.examenes;
        totalProduccion += med.totalFacturado;
        totalHonorarios += honorarios;
    });

    excelData.push([]);
    excelData.push(['TOTAL', totalInformes, totalExamenes, totalProduccion, totalHonorarios]);
    excelData.push([]);
    excelData.push([`RESPONSABLE: ${obtenerResponsableReporte()}`]);
    excelData.push([`Comisión aplicada: ${Math.round(porcentajeComision * 100)}%`]);

    descargarExcelXLSX(excelData, `Liquidacion_Honorarios_${mes}.xlsx`, 'Honorarios');
    showToast("Honorarios exportados a Excel", "success");
}

/**
 * Exportar producción mensual (matriz por examen y día)
 */
function exportarExcelExamenes() {
    if (!currentReporteExamenesData || currentReporteExamenesData.length === 0) {
        return showToast("No hay datos para exportar en este mes.", "warning");
    }

    const mes = $("#mesExamenes").val();
    let excelData = [];

    excelData.push(['PRODUCCIÓN MENSUAL RDOX PORTAL']);
    excelData.push([`Mes: ${mes}`]);
    excelData.push([]);

    let headers = ['EXAMEN', 'SALA / MODALIDAD', 'TOTAL MES'];
    for (let i = 1; i <= currentReporteDiasMes; i++) {
        headers.push(`Día ${i}`);
    }
    excelData.push(headers);

    let granTotal = 0;
    currentReporteExamenesData.forEach(row => {
        const fila = [row.examen, row.sala, row.total];
        granTotal += row.total;
        for (let i = 1; i <= currentReporteDiasMes; i++) {
            fila.push(row.dias?.[i] || 0);
        }
        excelData.push(fila);
    });

    excelData.push([]);
    excelData.push(['TOTAL GENERAL', '', granTotal]);
    excelData.push([]);
    excelData.push([`RESPONSABLE: ${obtenerResponsableReporte()}`]);

    descargarExcelXLSX(excelData, `Produccion_Mensual_${mes}.xlsx`, 'Produccion');
    showToast("Producción mensual exportada a Excel", "success");
}

function descargarExcelXLSX(datos, nombreArchivo, nombreHoja = 'Reporte') {
    if (typeof XLSX === 'undefined') {
        console.warn('SheetJS no está cargado. Usando fallback CSV...');
        descargarMatrizCSV(datos, nombreArchivo.replace(/\.xlsx$/i, '.csv'));
        return;
    }

    const libro = XLSX.utils.book_new();
    const hoja = XLSX.utils.aoa_to_sheet(datos);
    hoja['!cols'] = Array(Math.max(...datos.map(f => f.length), 1)).fill({ wch: 18 });
    XLSX.utils.book_append_sheet(libro, hoja, nombreHoja.substring(0, 31));
    XLSX.writeFile(libro, nombreArchivo);
}

async function cargarPacientes() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/patients`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Authorization': `Bearer ${token}`,
                'X-Lab-Id': labId,
                'Accept': 'application/json'
            }
        });

        const data = await response.json();

        if (response.ok && data.success) {
            currentPacientesAdmin = data.data.data || data.data;
            renderizarTablaPacientes(currentPacientesAdmin);
        } else {
            console.error("Error al cargar pacientes:", data.message);
        }
    } catch (error) {
        console.error("Error de conexión:", error);
    }
}

function verDetallePaciente(id) {
    const paciente = currentPacientesAdmin.find(p => p.id === id);
    if (!paciente) return;

    const per = paciente.persona || {};

    $("#detRut").text(per.rut || 'Sin RUT');
    $("#detNombre").text(`${per.names || ''} ${per.last_name_1 || ''} ${per.last_name_2 || ''}`);
    $("#detEmail").text(per.email || 'No registrado');
    $("#detTelefono").text(per.phone || 'No registrado');

    let fechaNacimiento = 'No registrada';
    if (per.birth_date) {
        fechaNacimiento = new Date(per.birth_date).toLocaleDateString('es-CL');
    }
    $("#detNacimiento").text(fechaNacimiento);

    let genero = 'No especificado';
    if (per.gender === 'M') genero = 'Masculino';
    else if (per.gender === 'F') genero = 'Femenino';
    $("#detGenero").text(genero);

    $("#modalPacienteDetalle").modal('show');
}

function renderizarTablaPacientes(pacientes) {
    const $tbody = $('#tabla-pacientes-body');
    $tbody.empty();

    if (pacientes.length === 0) {
        $tbody.append('<tr><td colspan="5" class="text-center">No hay pacientes registrados en este laboratorio.</td></tr>');
        return;
    }

    pacientes.forEach(paciente => {

        const persona = paciente.persona;

        const filaHtml = `
            <tr>
                <td>${persona.rut || 'Sin RUT'}</td>
                <td>${persona.names} ${persona.last_name_1} ${persona.last_name_2 || ''}</td>
                <td>${persona.gender || '-'}</td>
                <td>${persona.phone || '-'}</td>
                <td>
                    <button class="btn btn-sm btn-info" onclick="verDetallePaciente('${paciente.id}')">Ver</button>
                </td>
            </tr>
        `;
        $tbody.append(filaHtml);
    });
}


$(document).ready(function () {
    cargarPacientes();
});

$(document).on('change', '.role-check', function () {
    const roles = [];
    $(".role-check:checked").each(function () { roles.push($(this).val()); });
});


async function procesarImportacionExamenes() {
    const input = document.getElementById('archivoExamenes');
    if (!input.files || input.files.length === 0) {
        return showToast("⚠️ Seleccione un archivo primero.", "warning");
    }

    const formData = new FormData();
    formData.append('file', input.files[0]);

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const $btn = $("#modalImportarExamenes .btn-success");
    const $progreso = $("#progresoImportacion");

    try {
        $btn.prop("disabled", true);
        $progreso.removeClass("d-none");

        const response = await fetch(`${API_URL}/exams/import`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                'X-Lab-Id': labId,
                'Accept': 'application/json'
            },
            body: formData
        });

        const data = await response.json();

        if (response.ok && data.success) {
            showToast(`✅ ¡Éxito! Se importaron ${data.imported} exámenes.`, "success");
            $("#modalImportarExamenes").modal('hide');
            input.value = "";
            renderCatalogoAdmin();
        } else {
            showToast(`❌ Error: ${data.message || 'Error al procesar archivo'}`, "danger");
        }
    } catch (error) {
        showToast("🔌 Error de conexión con el servidor", "danger");
    } finally {
        $btn.prop("disabled", false);
        $progreso.addClass("d-none");
    }
}

/* =========================================
   GESTIÓN DE PLANES Y CONVENIOS
   ========================================= */

async function renderListaPlanesAdmin() {
    const tbody = $("#tablaPlanesAdmin tbody");
    if (!tbody.length) return;

    tbody.empty().append(`<tr><td colspan="4" class="text-center p-4"><span class="spinner-border spinner-border-sm text-primary"></span> Cargando planes...</td></tr>`);

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/plans`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();
        tbody.empty();

        if (response.ok && data.success) {
            currentPlanesFromDB = data.data;
            const searchStr = $("#searchPlan").val().toLowerCase();

            const filtrados = currentPlanesFromDB.filter(p => p.name.toLowerCase().includes(searchStr));

            if (filtrados.length === 0) {
                return tbody.append(`<tr><td colspan="4" class="text-center text-muted p-5">No hay convenios registrados.</td></tr>`);
            }

            filtrados.forEach(plan => {
                const nombrePrevision = plan.insurance ? plan.insurance.name : 'Sin Previsión';

                tbody.append(`
                    <tr>
                        <td><span class="badge bg-secondary">${nombrePrevision}</span></td>
                        <td class="fw-bold text-dark"><i class="bi bi-shield-check text-primary me-2"></i>${plan.name}</td>
                        <td class="text-center"><span class="badge bg-success fs-6">${plan.percentage}%</span></td>
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-outline-primary fw-bold" onclick="cargarPlan('${plan.id}')">
                                <i class="bi bi-pencil-square"></i> Editar
                            </button>
                        </td>
                    </tr>
                `);
            });
        }
    } catch (error) {
        tbody.empty().append(`<tr><td colspan="4" class="text-center text-danger p-4">Error de conexión.</td></tr>`);
    }
}

function nuevoPlan() {
    $("#formPlan")[0].reset();
    $("#planId").val("");
    $(".req-plan").removeClass("is-invalid");
    $("#btnEliminarPlan").hide();
    $("#modalPlan").modal('show');
}

function cargarPlan(id) {
    const plan = currentPlanesFromDB.find(p => p.id === id);
    if (!plan) return;

    $("#planId").val(plan.id);
    $("#planInsurance").val(plan.insurance_id);
    $("#planNombre").val(plan.name);
    $("#planPorcentaje").val(plan.percentage);

    $(".req-plan").removeClass("is-invalid");
    $("#btnEliminarPlan").show();
    $("#modalPlan").modal('show');
}

async function guardarPlan() {
    let hasError = false;
    $(".req-plan").each(function () {
        if ($(this).val().trim() === "") {
            $(this).addClass("is-invalid");
            hasError = true;
        } else {
            $(this).removeClass("is-invalid");
        }
    });

    if (hasError) return showToast("⚠️ Complete todos los campos obligatorios.", "warning");

    const payload = {
        id: $("#planId").val(),
        insurance_id: $("#planInsurance").val(),
        name: $("#planNombre").val().trim(),
        percentage: parseFloat($("#planPorcentaje").val())
    };

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/plans`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        if (response.ok && data.success) {
            $("#modalPlan").modal('hide');
            showToast("✅ Plan guardado exitosamente.", "success");
            renderListaPlanesAdmin();
        } else {
            showToast(`❌ Error: ${data.message}`, "danger");
        }
    } catch (e) {
        showToast("🔌 Error al conectar con el servidor", "danger");
    }
}

async function eliminarPlan() {
    const id = $("#planId").val();
    if (!id) return;
    if (!(await showConfirm("¿Está seguro de eliminar este plan?", { dangerous: true, confirmText: "Eliminar" }))) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/plans/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });

        if (response.ok) {
            $("#modalPlan").modal('hide');
            showToast("Plan eliminado.", "warning");
            renderListaPlanesAdmin();
        }
    } catch (e) {
        showToast("Error al eliminar", "danger");
    }
}

async function cargarInsurancesAdmin() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    try {
        const response = await fetch(`${API_URL}/insurances`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();

        if (response.ok && data.success) {
            catalogInsurances = data.data;

            const select = $("#planInsurance");
            if (select.length) {
                select.empty().append('<option value="">Seleccione Previsión...</option>');
                catalogInsurances.forEach(ins => select.append(`<option value="${ins.id}">${ins.name}</option>`));
            }

            renderListaPrevisionesAdmin();
        }
    } catch (e) { console.error("Error cargando previsiones", e); }
}

function renderListaPrevisionesAdmin() {
    const tbody = $("#tablaPrevisionesAdmin tbody");
    if (!tbody.length) return;

    tbody.empty();
    const searchStr = $("#searchPrevision").val() ? $("#searchPrevision").val().toLowerCase() : "";

    const filtrados = catalogInsurances.filter(i => i.name.toLowerCase().includes(searchStr));

    if (filtrados.length === 0) {
        return tbody.append(`<tr><td colspan="4" class="text-center text-muted p-5">No hay previsiones registradas.</td></tr>`);
    }

    filtrados.forEach(prev => {
        const alcance = prev.laboratory_id
            ? '<span class="badge bg-primary">Local (Esta Sucursal)</span>'
            : '<span class="badge bg-dark">Global (Todas las Sucursales)</span>';

        const esGlobal = prev.laboratory_id === null;

        tbody.append(`
            <tr>
                <td class="fw-bold text-dark"><i class="bi bi-heart-pulse text-danger me-2"></i>${prev.name}</td>
                <td class="text-center">${alcance}</td>
                <td class="text-center pe-4">
                    <button class="btn btn-sm btn-outline-danger fw-bold" onclick="cargarPrevision('${prev.id}')">
                        <i class="bi bi-pencil-square"></i> Editar
                    </button>
                </td>
            </tr>
        `);
    });
}

function nuevaPrevision() {
    $("#formPrevision")[0].reset();
    $("#prevId").val("");
    $(".req-prev").removeClass("is-invalid");
    $("#btnEliminarPrevision").hide();
    $("#modalPrevision").modal('show');
}

function cargarPrevision(id) {
    const prev = catalogInsurances.find(p => p.id === id);
    if (!prev) return;

    $("#prevId").val(prev.id);
    $("#prevNombre").val(prev.name);

    $(".req-prev").removeClass("is-invalid");
    if (prev.laboratory_id === null) {
        $("#btnEliminarPrevision").hide();
    } else {
        $("#btnEliminarPrevision").show();
    }

    $("#modalPrevision").modal('show');
}

async function guardarPrevision() {
    if ($("#prevNombre").val().trim() === "") {
        $("#prevNombre").addClass("is-invalid");
        return showToast("⚠️ El nombre es obligatorio.", "warning");
    }

    const payload = {
        id: $("#prevId").val(),
        name: $("#prevNombre").val().trim()
    };

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/insurances`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        if (response.ok && data.success) {
            $("#modalPrevision").modal('hide');
            showToast("✅ Previsión guardada.", "success");
            cargarInsurancesAdmin();
        } else {
            showToast(`❌ Error: ${data.message}`, "danger");
        }
    } catch (e) {
        showToast("🔌 Error al conectar con el servidor", "danger");
    }
}

async function eliminarPrevision() {
    const id = $("#prevId").val();
    if (!id) return;
    if (!(await showConfirm("¿Está seguro de eliminar esta previsión? Se eliminarán los planes asociados.", { dangerous: true, confirmText: "Eliminar" }))) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/insurances/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });

        if (response.ok) {
            $("#modalPrevision").modal('hide');
            showToast("Previsión eliminada.", "warning");
            cargarInsurancesAdmin();
        }
    } catch (e) {
        showToast("Error al eliminar", "danger");
    }
}

function nuevaSala() {
    cargarSala(null);
}

window.cargarSala = cargarSala;
window.nuevaSala = nuevaSala;
window.guardarSala = guardarSala;
window.eliminarSala = eliminarSala;

async function eliminarSala() {
    const id = $("#salaId").val();

    if (!id) return;
    if (!(await showConfirm("¿Está seguro de eliminar esta Sala/Equipo? Esto podría afectar la agenda histórica.", { dangerous: true, confirmText: "Eliminar" }))) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/machines/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });

        if (response.ok) {
            $("#modalSala").modal('hide');
            showToast("Sala/Equipo eliminado correctamente.", "warning");
            renderListaSalasAdmin();
        } else {
            showToast("Error al eliminar el equipo.", "danger");
        }
    } catch (e) {
        showToast("Error de conexión con el servidor.", "danger");
    }
}

function nuevaMatriz() {
    $("#formMatriz")[0].reset();
    $(".req-matriz").removeClass("is-invalid");
    $("#modalMatriz").modal('show');
}

async function guardarMatriz() {
    let hasError = false;
    $(".req-matriz").each(function () {
        if ($(this).val().trim() === "") { $(this).addClass("is-invalid"); hasError = true; }
        else { $(this).removeClass("is-invalid"); }
    });

    if (hasError) return showToast("⚠️ Complete los campos obligatorios.", "danger");

    const payload = {
        laboratory_type_id: $("#matrizTipo").val(),
        name: $("#matrizNombre").val().trim(),
        address: $("#matrizDireccion").val().trim(),
        city: $("#matrizCiudad").val().trim(),
        phone: $("#matrizTelefono").val().trim()
    };

    const token = localStorage.getItem('ris_token');

    try {
        const response = await fetch(`${API_URL}/laboratories`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': localStorage.getItem('ris_lab_id'), 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (response.ok && data.success) {
            $("#modalMatriz").modal('hide');
            showToast("✅ Nueva Casa Matriz creada con éxito.", "success");

            localStorage.setItem('ris_lab_id', data.data.id);

            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showToast(`❌ Error: ${data.message}`, "danger");
        }
    } catch (e) {
        showToast("Error al guardar en el servidor", "danger");
    }
}

function descargarPlantillaExamenes() {
    let csv = '\uFEFF';
    csv += "group_code;name;fonasa_code;price\n";

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", "Plantilla_Carga_Examenes.csv");
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    showToast("Plantilla vacía descargada. Complete una fila por examen.", "success");
}

/* =========================================
   GESTIÓN DE PLANTILLAS MÉDICAS
   ========================================= */

async function renderListaPlantillasAdmin() {
    const tbody = $("#tablaPlantillasAdmin tbody");
    if (!tbody.length) return;

    tbody.empty().append(`<tr><td colspan="4" class="text-center p-4"><span class="spinner-border spinner-border-sm text-primary"></span> Cargando plantillas...</td></tr>`);

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/templates`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });
        const data = await response.json();
        tbody.empty();

        if (response.ok && data.success) {
            currentPlantillasFromDB = data.data;
            const searchStr = $("#searchPlantilla").val().toLowerCase();

            const filtrados = currentPlantillasFromDB.filter(t =>
                t.title.toLowerCase().includes(searchStr) ||
                t.group_code.toLowerCase().includes(searchStr)
            );

            if (filtrados.length === 0) {
                return tbody.append(`<tr><td colspan="4" class="text-center text-muted p-5">No hay plantillas registradas.</td></tr>`);
            }

            filtrados.forEach(tpl => {
                const badgeColor = risBadgeClassModalidad(tpl.group_code);

                const alcance = tpl.laboratory_id
                    ? '<span class="badge bg-light text-dark border">Local</span>'
                    : '<span class="badge bg-dark">Global</span>';

                tbody.append(`
                    <tr>
                        <td class="ps-4"><span class="badge ${badgeColor}">${tpl.group_code}</span></td>
                        <td class="fw-bold text-dark"><i class="bi bi-file-text text-muted me-2"></i>${tpl.title}</td>
                        <td class="text-center">${alcance}</td>
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-outline-primary fw-bold" onclick="cargarPlantilla('${tpl.id}')">
                                <i class="bi bi-pencil-square"></i> Editar
                            </button>
                        </td>
                    </tr>
                `);
            });
        }
    } catch (error) {
        tbody.empty().append(`<tr><td colspan="4" class="text-center text-danger p-4">Error de conexión.</td></tr>`);
    }
}

function nuevaPlantilla() {
    $("#formPlantilla")[0].reset();
    $("#tplId").val("");
    $(".req-tpl").removeClass("is-invalid");
    $("#btnEliminarPlantilla").hide();
    $("#modalPlantilla").modal('show');
}

async function procesarImportacionPlantillaWord(inputEl) {
    const input = inputEl || document.getElementById('inputImportPlantillaWord');
    if (!input?.files?.length) {
        return;
    }

    const file = input.files[0];
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    if (!['docx', 'doc'].includes(ext)) {
        showToast('Seleccione un archivo Word (.docx).', 'warning');
        input.value = '';
        return;
    }

    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId()) {
        input.value = '';
        return;
    }

    const formData = new FormData();
    formData.append('file', file);

    const headers = typeof adminAuthHeaders === 'function'
        ? adminAuthHeaders()
        : { Authorization: `Bearer ${localStorage.getItem('ris_token')}`, Accept: 'application/json' };

    try {
        showToast('Leyendo documento Word…', 'info');
        const response = await fetch(`${API_URL}/templates/import-word`, {
            method: 'POST',
            headers,
            body: formData,
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'No se pudo importar el archivo.');
        }

        const imported = data.data || {};
        const modalEl = document.getElementById('modalPlantilla');
        if (modalEl && !modalEl.classList.contains('show')) {
            $("#tplId").val("");
            $("#btnEliminarPlantilla").hide();
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
        if (imported.title) {
            $("#tplTitulo").val(imported.title);
        }
        if (imported.group_code) {
            risAsegurarValorModalidad('#tplGrupo', imported.group_code);
        }
        if (imported.content) {
            $("#tplContenido").val(imported.content);
        }
        $(".req-tpl").removeClass('is-invalid');
        showToast('Contenido importado. Revise título y modalidad antes de guardar.', 'success');
    } catch (e) {
        showToast(e.message || 'Error al importar Word.', 'danger');
    } finally {
        input.value = '';
        const other = input.id === 'inputImportPlantillaWord'
            ? document.getElementById('inputImportPlantillaWordModal')
            : document.getElementById('inputImportPlantillaWord');
        if (other) {
            other.value = '';
        }
    }
}

window.procesarImportacionPlantillaWord = procesarImportacionPlantillaWord;

function cargarPlantilla(id) {
    const tpl = currentPlantillasFromDB.find(t => t.id === id);
    if (!tpl) return;

    $("#tplId").val(tpl.id);
    risAsegurarValorModalidad('#tplGrupo', tpl.group_code);
    $("#tplTitulo").val(tpl.title);
    $("#tplContenido").val(tpl.content);

    $(".req-tpl").removeClass("is-invalid");

    if (tpl.laboratory_id === null) {
        $("#btnEliminarPlantilla").hide();
    } else {
        $("#btnEliminarPlantilla").show();
    }

    $("#modalPlantilla").modal('show');
}

async function guardarPlantilla() {
    let hasError = false;
    $(".req-tpl").each(function () {
        if ($(this).val().trim() === "") {
            $(this).addClass("is-invalid");
            hasError = true;
        } else {
            $(this).removeClass("is-invalid");
        }
    });

    if (hasError) return showToast("⚠️ Complete todos los campos obligatorios.", "warning");

    const payload = {
        id: $("#tplId").val(),
        group_code: $("#tplGrupo").val(),
        title: $("#tplTitulo").val().trim(),
        content: $("#tplContenido").val().trim()
    };

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const btn = $("#modalPlantilla .btn-warning");
        btn.prop("disabled", true).text("Guardando...");

        const response = await fetch(`${API_URL}/templates`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        if (response.ok && data.success) {
            $("#modalPlantilla").modal('hide');
            showToast("✅ Plantilla guardada exitosamente.", "success");
            renderListaPlantillasAdmin();
        } else {
            showToast(`❌ Error: ${data.message}`, "danger");
        }
    } catch (e) {
        showToast("🔌 Error de red", "danger");
    } finally {
        $("#modalPlantilla .btn-warning").prop("disabled", false).text("Guardar");
    }
}

async function eliminarPlantilla() {
    const id = $("#tplId").val();
    if (!id) return;
    if (!(await showConfirm("¿Está seguro de eliminar esta plantilla?", { dangerous: true, confirmText: "Eliminar" }))) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/templates/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });

        if (response.ok) {
            $("#modalPlantilla").modal('hide');
            showToast("Plantilla eliminada.", "warning");
            renderListaPlantillasAdmin();
        }
    } catch (e) {
        showToast("Error al eliminar", "danger");
    }
}

// === MÓDULO DE PACIENTES (CRUD) ===

function canEditPatientsRis() {
    const profile = (localStorage.getItem('ris_user_profile') || '').toLowerCase();
    if (!['secretaria', 'secretario'].includes(profile)) {
        return true;
    }
    const labName = (localStorage.getItem('ris_lab_name') || '').toUpperCase();
    return labName.includes('RDOX') && labName.includes('OSORNO');
}

async function cargarPacientes() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const tbody = $("#tablaPacientes tbody"); // Asegúrate de tener una tabla con este ID en tu pestaña de pacientes

    try {
        const response = await fetch(`${API_URL}/patients`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();

        if (response.ok && data.success) {
            currentPacientesAdmin = data.data.data; // Viene paginado usualmente
            renderListaPacientesAdmin();

            // Llenar el select de seguros médicos en el modal
            const selectSeguro = $("#pacSeguro").empty().append('<option value="">Sin Previsión (Particular)</option>');
            catalogInsurances.forEach(ins => {
                selectSeguro.append(`<option value="${ins.id}">${ins.name}</option>`);
            });
        }
    } catch (e) {
        console.error("Error al cargar pacientes", e);
    }
}

function renderListaPacientesAdmin() {
    const tbody = $("#tablaPacientes tbody");
    if (!tbody.length) return; // Por si la tabla aún no existe en el HTML
    tbody.empty();

    if (currentPacientesAdmin.length === 0) {
        tbody.append('<tr><td colspan="5" class="text-center text-muted">No hay pacientes registrados</td></tr>');
        return;
    }

    currentPacientesAdmin.forEach(p => {
        const per = p.persona;
        tbody.append(`
            <tr>
                <td class="fw-bold">${per.rut}</td>
                <td>${per.last_name_1} ${per.last_name_2 || ''}, ${per.names}</td>
                <td>${per.email || '<span class="text-muted small">Sin correo</span>'}</td>
                <td>${per.phone || '-'}</td>
                <td class="text-end">
                    ${canEditPatientsRis()
                        ? `<button class="btn btn-sm btn-outline-primary" onclick="abrirModalPaciente('${p.id}')"><i class="bi bi-pencil"></i> Editar</button>`
                        : '<span class="text-muted small">Solo lectura</span>'}
                </td>
            </tr>
        `);
    });
}

function abrirModalPaciente(id = null) {
    limpiarFormulario(".req-pac");
    $("#pacienteId").val("");
    $("#pacRUT, #pacNombres, #pacApellido1, #pacApellido2, #pacNacimiento, #pacGenero, #pacTelefono, #pacEmail, #pacSeguro").val("");
    $("#pacRUT").prop("disabled", false);
    $("#btnEliminarPaciente").hide();


    if (id) {
        const p = currentPacientesAdmin.find(x => String(x.id) === String(id));
        if (p) {
            const per = p.persona;
            $("#pacienteId").val(p.id);
            $("#pacRUT").val(per.rut).prop("disabled", true); // El RUT no se edita fácilmente
            $("#pacNombres").val(per.names);
            $("#pacApellido1").val(per.last_name_1);
            $("#pacApellido2").val(per.last_name_2);
            if (per.birth_date) $("#pacNacimiento").val(per.birth_date.split('T')[0]);
            $("#pacGenero").val(per.gender);
            $("#pacTelefono").val(per.phone);
            $("#pacEmail").val(per.email);
            $("#pacSeguro").val(p.insurance_id || "");
            $("#btnEliminarPaciente").show();
        }
    }
    toggleFormatoDocumento();
    $("#modalPaciente").modal('show');
}

async function guardarPaciente() {
    if (!canEditPatientsRis()) {
        return showToast('Su perfil no puede editar pacientes en esta sede.', 'warning');
    }
    if (!validarFormulario(".req-pac")) return;

    const id = $("#pacienteId").val();
    const isEdit = id !== "";
    const method = isEdit ? 'PUT' : 'POST';
    const url = isEdit ? `${API_URL}/patients/${id}` : `${API_URL}/patients`;

    // (Fragmento dentro de guardarPaciente)
    const tipoDoc = $("#pacTipoDoc").val();
    const documento = $("#pacRUT").val().trim().toUpperCase();

    // Si es RUT chileno, exigimos validación matemática estricta
    if (tipoDoc === "RUT" && !validarRut(documento)) {
        $("#pacRUT").addClass("is-invalid");
        return showToast("❌ RUT Chileno inválido.", "danger");
    }
    // Si es pasaporte, solo exigimos que tenga al menos 4 caracteres (números o letras)
    else if (tipoDoc === "PASAPORTE" && documento.length < 4) {
        $("#pacRUT").addClass("is-invalid");
        return showToast("❌ El pasaporte debe tener al menos 4 caracteres.", "danger");
    }

    const payload = {
        rut: $("#pacRUT").val().trim(),
        names: $("#pacNombres").val().trim(),
        last_name_1: $("#pacApellido1").val().trim(),
        last_name_2: $("#pacApellido2").val().trim(),
        birth_date: $("#pacNacimiento").val() || null,
        gender: $("#pacGenero").val() || null,
        phone: $("#pacTelefono").val().trim(),
        email: $("#pacEmail").val().trim(),
        insurance_id: $("#pacSeguro").val() || null
    };

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const btn = $("#modalPaciente .btn-primary");

    try {
        btn.prop("disabled", true).html('<span class="spinner-border spinner-border-sm"></span> Guardando...');

        const response = await fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        if (response.ok && data.success) {
            $("#modalPaciente").modal('hide');
            showToast(isEdit ? "Paciente actualizado" : "Paciente creado", "success");
            cargarPacientes();
        } else {
            showToast(`Error: ${data.message || 'Datos inválidos'}`, "danger");
        }
    } catch (e) {
        showToast("Error de conexión", "danger");
    } finally {
        btn.prop("disabled", false).text("Guardar Paciente");
    }
}

async function eliminarPaciente() {
    const id = $("#pacienteId").val();
    if (!id) return;
    if (!(await showConfirm("¿Está absolutamente seguro de eliminar este paciente y todo su historial? Esta acción es irreversible.", { dangerous: true, confirmText: "Eliminar definitivamente" }))) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/patients/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });

        if (response.ok) {
            $("#modalPaciente").modal('hide');
            showToast("Paciente eliminado.", "warning");
            cargarPacientes();
        } else {
            showToast("No se pudo eliminar el paciente. Posiblemente tenga exámenes asociados.", "danger");
        }
    } catch (e) {
        showToast("Error de conexión", "danger");
    }
}

function renderCheckboxesSucursales() {
    const container = $("#userLaboratoriesContainer").empty();

    if (!currentLaboratoriesTree || currentLaboratoriesTree.length === 0) {
        container.html('<span class="text-danger small">No hay sucursales disponibles.</span>');
        return;
    }

    currentLaboratoriesTree.forEach(matriz => {
        let groupHtml = `
            <div class="w-100 mb-2 mt-1">
                <div class="text-primary fw-bold border-bottom pb-1 mb-2" style="font-size: 0.85rem;">
                    <i class="bi bi-diagram-3-fill me-1"></i> ${matriz.name || 'Casa Matriz'}
                </div>
                <div class="d-flex flex-wrap gap-3 ps-3">
        `;

        // 1. Switch para la Sede Principal
        groupHtml += `
            <div class="form-check form-switch">
                <input class="form-check-input chk-lab shadow-sm" type="checkbox" value="${matriz.id}" id="chkLab_${matriz.id}">
                <label class="form-check-label small fw-bold text-dark" for="chkLab_${matriz.id}">Sede Principal</label>
            </div>
        `;

        // 2. Switches para las Sucursales Hijas
        if (matriz.children && matriz.children.length > 0) {
            matriz.children.forEach(suc => {
                groupHtml += `
                    <div class="form-check form-switch">
                        <input class="form-check-input chk-lab shadow-sm" type="checkbox" value="${suc.id}" id="chkLab_${suc.id}">
                        <label class="form-check-label small fw-bold text-secondary" for="chkLab_${suc.id}">${suc.name}</label>
                    </div>
                `;
            });
        }

        groupHtml += `</div></div>`;
        container.append(groupHtml);
    });
}

async function pingDicom(id) {
    const sala = currentMachinesFromDB.find(s => String(s.id) === String(id));
    if (!sala) return;

    if (typeof showToast === 'function') showToast(`Diagnóstico MWL: ${sala.ae_title || sala.name}...`, "info");

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/machines/${id}/ping`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId, 'Accept': 'application/json' }
        });

        const data = await response.json();

        let title = "Revise configuración MWL en el FCR";
        let type = "danger";
        if (data.pacs_mwl?.cfind_ok) {
            title = data.equipment_tcp?.ok ? "C-FIND OK — PACS y worklist" : "C-FIND OK (Fuji sin TCP entrante, normal)";
            type = "success";
        } else if (data.pacs_mwl?.cfind_failed) {
            title = "C-FIND rechazado — revise Local AE en FCR Console";
            type = "danger";
        } else if (data.pacs_mwl?.ok) {
            title = "PACS alcanzable pero sin worklist hoy";
            type = "warning";
        }
        showAlert(data.message || "Sin detalle", title, type);
    } catch (e) {
        showAlert("No se pudo contactar al servidor RIS.", "Error crítico", "danger");
    }
}

// === LÓGICA PARA EXTRANJEROS ===
function toggleFormatoDocumento() {
    const tipo = $("#pacTipoDoc").val();
    const inputDoc = $("#pacRUT");

    inputDoc.val("").removeClass("is-valid is-invalid");

    if (tipo === "PASAPORTE") {
        inputDoc.attr("placeholder", "Ej. AB123456 (Letras y números)");
        inputDoc.off("input"); // Apagamos el formateo de puntos y guion
    } else {
        inputDoc.attr("placeholder", "12.345.678-9");
        inputDoc.on("input", function () {
            // Re-activamos el formateo de RUT chileno
            let actual = $(this).val().replace(/[^0-9kK]/g, '');
            if (actual.length === 0) { $(this).val(""); return; }
            let rutPuntos = ""; let cuerpo = actual.slice(0, -1); let dv = actual.slice(-1).toUpperCase();
            for (let i = cuerpo.length - 1, j = 1; i >= 0; i--, j++) {
                rutPuntos = cuerpo.charAt(i) + rutPuntos;
                if (j % 3 === 0 && i !== 0) rutPuntos = "." + rutPuntos;
            }
            $(this).val(cuerpo.length > 0 ? rutPuntos + "-" + dv : dv);
        });
    }
}

function cargarUsuario(id) {
    abrirModalUsuario(id);
}