/* =========================================
   MÓDULO SOPORTE (ayuda + tickets)
   ========================================= */

let _supportTicketsMine = [];
let _supportTicketsQueue = [];
let _supportCurrentTicketId = null;
let _supportIsStaff = false;

const SUPPORT_STATUS_LABEL = {
    abierto: 'Abierto',
    en_curso: 'En curso',
    resuelto: 'Resuelto',
    cerrado: 'Cerrado',
};

const SUPPORT_PRIORITY_LABEL = {
    baja: 'Baja',
    normal: 'Normal',
    alta: 'Alta',
};

const SUPPORT_MODULE_LABEL = {
    general: 'General',
    agenda: 'Agenda',
    worklist: 'Lista de trabajo',
    radiologist: 'Radiólogo',
    transcription: 'Transcripción',
    validation: 'Validación',
    entrega: 'Entrega',
    admin: 'Administración',
    visor: 'Visor / PACS',
    bridge: 'Bridge / escáner',
};

function supportEscape(text) {
    if (typeof risEscapeHtml === 'function') return risEscapeHtml(text);
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function supportAuthHeaders(extra = {}) {
    return typeof risBuildAuthHeaders === 'function'
        ? risBuildAuthHeaders(extra)
        : { ...extra, Authorization: `Bearer ${localStorage.getItem('ris_token') || ''}` };
}

function supportIsStaffUser() {
    try {
        const profile = String(localStorage.getItem('ris_user_profile') || '').toLowerCase();
        const userData = JSON.parse(localStorage.getItem('ris_user_data') || '{}');
        const roles = Array.isArray(userData.settings?.roles)
            ? userData.settings.roles.map((r) => String(r).toLowerCase())
            : [];
        if (typeof risIsSysAdmin === 'function' && risIsSysAdmin()) return true;
        if (typeof risIsClinicAdmin === 'function' && risIsClinicAdmin()) return true;
        return profile === 'admin' || profile === 'sis_admin'
            || roles.includes('admin') || roles.includes('sis_admin');
    } catch (_) {
        return false;
    }
}

function supportStatusBadge(status) {
    const map = {
        abierto: 'primary',
        en_curso: 'warning',
        resuelto: 'success',
        cerrado: 'secondary',
    };
    const cls = map[status] || 'secondary';
    return `<span class="badge text-bg-${cls}">${supportEscape(SUPPORT_STATUS_LABEL[status] || status)}</span>`;
}

function supportPriorityBadge(priority) {
    const map = { baja: 'secondary', normal: 'info', alta: 'danger' };
    const cls = map[priority] || 'secondary';
    return `<span class="badge text-bg-${cls}">${supportEscape(SUPPORT_PRIORITY_LABEL[priority] || priority)}</span>`;
}

function supportFormatDate(iso) {
    if (!iso) return '—';
    try {
        const d = new Date(iso);
        if (Number.isNaN(d.getTime())) return supportEscape(iso);
        return d.toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' });
    } catch (_) {
        return supportEscape(iso);
    }
}

function initSupport() {
    _supportIsStaff = supportIsStaffUser();
    if (_supportIsStaff) {
        $('#tab-cola-wrap').removeClass('d-none');
    }

    $('#btnNuevoTicket').off('click.support').on('click.support', () => {
        $('#formNuevoTicket')[0]?.reset();
        $('#ticketPriority').val('normal');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNuevoTicket')).show();
    });

    $('#formNuevoTicket').off('submit.support').on('submit.support', async (e) => {
        e.preventDefault();
        await supportCreateTicket();
    });

    $('#btnRefreshCola').off('click.support').on('click.support', () => supportLoadQueue());
    $('#filtroColaEstado').off('change.support').on('change.support', () => supportRenderQueue());

    $('#btnGuardarTicketAdmin').off('click.support').on('click.support', () => supportSaveAdminUpdate());

    document.getElementById('tab-mis-tickets')?.addEventListener('shown.bs.tab', () => supportLoadMine());
    document.getElementById('tab-cola')?.addEventListener('shown.bs.tab', () => supportLoadQueue());

    // Precargar lista propia en segundo plano
    supportLoadMine();
    if (_supportIsStaff) supportLoadQueue();
}

async function supportCreateTicket() {
    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId()) {
        return;
    }

    const payload = {
        subject: $('#ticketSubject').val()?.trim(),
        module: $('#ticketModule').val(),
        priority: $('#ticketPriority').val() || 'normal',
        body: $('#ticketBody').val()?.trim(),
    };

    if (!payload.subject || !payload.body) {
        showToast('Complete asunto y descripción', 'warning');
        return;
    }

    const btn = $('#btnSubmitTicket').prop('disabled', true);
    try {
        const response = await fetch(`${API_URL}/support/tickets`, {
            method: 'POST',
            headers: supportAuthHeaders({ 'Content-Type': 'application/json', Accept: 'application/json' }),
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'No se pudo crear la solicitud');
        }
        bootstrap.Modal.getInstance(document.getElementById('modalNuevoTicket'))?.hide();
        showToast('Solicitud enviada', 'success');
        if (typeof risPollSupportAlerts === 'function') {
            risPollSupportAlerts(false);
        }
        await supportLoadMine();
        const tab = document.getElementById('tab-mis-tickets');
        if (tab && !tab.classList.contains('active')) {
            bootstrap.Tab.getOrCreateInstance(tab).show();
        }
    } catch (err) {
        showToast(err.message || 'Error al enviar', 'danger');
    } finally {
        btn.prop('disabled', false);
    }
}

async function supportLoadMine() {
    const tbody = $('#tablaMisTickets tbody');
    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId(false)) {
        tbody.html('<tr><td colspan="6" class="text-center text-warning p-4">Seleccione una sede específica.</td></tr>');
        return;
    }

    tbody.html('<tr><td colspan="6" class="text-center text-muted p-4">Cargando…</td></tr>');
    try {
        const response = await fetch(`${API_URL}/support/tickets`, {
            headers: supportAuthHeaders({ Accept: 'application/json' }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Error al cargar');
        }
        _supportTicketsMine = data.data || [];
        supportRenderMine();
    } catch (err) {
        tbody.html(`<tr><td colspan="6" class="text-center text-danger p-4">${supportEscape(err.message)}</td></tr>`);
    }
}

function supportRenderMine() {
    const tbody = $('#tablaMisTickets tbody');
    if (!_supportTicketsMine.length) {
        tbody.html('<tr><td colspan="6" class="text-center text-muted p-4">No tiene solicitudes aún.</td></tr>');
        return;
    }
    tbody.empty();
    _supportTicketsMine.forEach((t) => {
        tbody.append(`
            <tr>
                <td class="ps-3 fw-semibold">${supportEscape(t.subject)}</td>
                <td>${supportEscape(SUPPORT_MODULE_LABEL[t.module] || t.module)}</td>
                <td>${supportPriorityBadge(t.priority)}</td>
                <td>${supportStatusBadge(t.status)}</td>
                <td class="small text-secondary">${supportFormatDate(t.created_at)}</td>
                <td class="text-center pe-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-ticket-id="${supportEscape(t.id)}" data-ticket-mode="mine">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </td>
            </tr>
        `);
    });
    tbody.find('[data-ticket-id]').on('click', function () {
        supportOpenTicket($(this).data('ticket-id'), $(this).data('ticket-mode') === 'queue');
    });
}

async function supportLoadQueue() {
    if (!_supportIsStaff) return;
    const tbody = $('#tablaColaTickets tbody');
    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId(false)) {
        tbody.html('<tr><td colspan="7" class="text-center text-warning p-4">Seleccione una sede específica.</td></tr>');
        return;
    }

    tbody.html('<tr><td colspan="7" class="text-center text-muted p-4">Cargando…</td></tr>');
    try {
        const response = await fetch(`${API_URL}/support/tickets?queue=1`, {
            headers: supportAuthHeaders({ Accept: 'application/json' }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Error al cargar cola');
        }
        _supportTicketsQueue = data.data || [];
        supportRenderQueue();
    } catch (err) {
        tbody.html(`<tr><td colspan="7" class="text-center text-danger p-4">${supportEscape(err.message)}</td></tr>`);
    }
}

function supportRenderQueue() {
    const tbody = $('#tablaColaTickets tbody');
    const filtro = $('#filtroColaEstado').val();
    const rows = _supportTicketsQueue.filter((t) => !filtro || t.status === filtro);

    if (!rows.length) {
        tbody.html('<tr><td colspan="7" class="text-center text-muted p-4">Sin tickets en este filtro.</td></tr>');
        return;
    }

    tbody.empty();
    rows.forEach((t) => {
        tbody.append(`
            <tr>
                <td class="ps-3 fw-semibold">${supportEscape(t.subject)}</td>
                <td class="small">${supportEscape(t.creator_name || '—')}</td>
                <td>${supportEscape(SUPPORT_MODULE_LABEL[t.module] || t.module)}</td>
                <td>${supportPriorityBadge(t.priority)}</td>
                <td>${supportStatusBadge(t.status)}</td>
                <td class="small text-secondary">${supportFormatDate(t.created_at)}</td>
                <td class="text-center pe-3">
                    <button type="button" class="btn btn-sm btn-primary" data-ticket-id="${supportEscape(t.id)}" data-ticket-mode="queue">
                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                    </button>
                </td>
            </tr>
        `);
    });
    tbody.find('[data-ticket-id]').on('click', function () {
        supportOpenTicket($(this).data('ticket-id'), true);
    });
}

async function supportOpenTicket(id, asStaff) {
    _supportCurrentTicketId = id;
    try {
        const response = await fetch(`${API_URL}/support/tickets/${encodeURIComponent(id)}`, {
            headers: supportAuthHeaders({ Accept: 'application/json' }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'No se pudo abrir el ticket');
        }
        const t = data.data;
        $('#modalDetalleTicketTitle').text(t.subject || 'Detalle');
        $('#detalleTicketBody').html(`
            <div class="d-flex flex-wrap gap-2 mb-3">
                ${supportStatusBadge(t.status)}
                ${supportPriorityBadge(t.priority)}
                <span class="badge text-bg-light text-dark border">${supportEscape(SUPPORT_MODULE_LABEL[t.module] || t.module)}</span>
            </div>
            <p class="small text-secondary mb-1">
                Creado: ${supportFormatDate(t.created_at)}
                ${t.creator_name ? ` · ${supportEscape(t.creator_name)}` : ''}
            </p>
            <div class="border rounded p-3 bg-light mb-3">
                <div class="fw-semibold small mb-1">Descripción</div>
                <div class="text-break" style="white-space: pre-wrap;">${supportEscape(t.body)}</div>
            </div>
            ${t.admin_reply ? `
                <div class="border rounded p-3 border-primary-subtle">
                    <div class="fw-semibold small mb-1 text-primary">Respuesta del equipo</div>
                    <div class="text-break" style="white-space: pre-wrap;">${supportEscape(t.admin_reply)}</div>
                    ${t.assigned_name ? `<div class="small text-secondary mt-2">Atendido por: ${supportEscape(t.assigned_name)}</div>` : ''}
                </div>
            ` : '<p class="small text-muted mb-0">Aún sin respuesta del equipo.</p>'}
        `);

        const showAdmin = _supportIsStaff && asStaff;
        $('#detalleTicketAdminFooter').toggleClass('d-none', !showAdmin);
        if (showAdmin) {
            $('#ticketAdminReply').val(t.admin_reply || '');
            $('#ticketAdminStatus').val(t.status || 'abierto');
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDetalleTicket')).show();
    } catch (err) {
        showToast(err.message || 'Error', 'danger');
    }
}

async function supportSaveAdminUpdate() {
    if (!_supportCurrentTicketId) return;
    const payload = {
        status: $('#ticketAdminStatus').val(),
        admin_reply: $('#ticketAdminReply').val()?.trim() || null,
    };

    $('#btnGuardarTicketAdmin').prop('disabled', true);
    try {
        const response = await fetch(`${API_URL}/support/tickets/${encodeURIComponent(_supportCurrentTicketId)}`, {
            method: 'PATCH',
            headers: supportAuthHeaders({ 'Content-Type': 'application/json', Accept: 'application/json' }),
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'No se pudo guardar');
        }
        showToast('Ticket actualizado', 'success');
        bootstrap.Modal.getInstance(document.getElementById('modalDetalleTicket'))?.hide();
        if (typeof risPollSupportAlerts === 'function') {
            risPollSupportAlerts(false);
        }
        await Promise.all([supportLoadMine(), supportLoadQueue()]);
    } catch (err) {
        showToast(err.message || 'Error al guardar', 'danger');
    } finally {
        $('#btnGuardarTicketAdmin').prop('disabled', false);
    }
}

window.initSupport = initSupport;
