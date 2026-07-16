/* =========================================
   Núcleo: Atención técnica (Worklist clínica / Salas dental)
   ========================================= */

let ATENCION_MODULO = {
    pageId: 'worklist',
    forceManualUpload: false,
    forceWorklistOnly: false,
};

let currentAtencionChain = null;
let currentWorklistFromDB = [];
let currentCadenas = {};
let currentSuppliesFromDB = [];
let currentMachinesFromDB = [];
let _atencionRefreshTimer = null;
/** Borradores locales de anamnesis por cadena (paciente+día) mientras se atiende la worklist. */
let anamnesisDrafts = {};

function risUserHasAnyRole(roles) {
    const wanted = (roles || []).map((r) => String(r).toLowerCase());
    const profileName = (localStorage.getItem('ris_user_profile') || '').toLowerCase();
    if (wanted.includes(profileName)) {
        return true;
    }
    try {
        const userData = JSON.parse(localStorage.getItem('ris_user_data') || '{}');
        const userRoles = Array.isArray(userData.settings?.roles) ? userData.settings.roles : [];
        if (userRoles.some((r) => wanted.includes(String(r).toLowerCase()))) {
            return true;
        }
        if (typeof risIsClinicAdmin === 'function' && risIsClinicAdmin() && wanted.includes('admin')) {
            return true;
        }
        if (typeof risIsSysAdmin === 'function' && risIsSysAdmin()
            && (wanted.includes('admin') || wanted.includes('sis_admin'))) {
            return true;
        }
    } catch (e) {
        /* ignore */
    }
    return false;
}

/** Admin / radiólogo: anamnesis opcional al finalizar worklist. */
function risAnamnesisOpcionalWorklist() {
    return risUserHasAnyRole(['admin', 'sis_admin', 'radiologo']);
}

const pesosPrioridad = {
    "Urgencia": 3,
    "Alta": 2,
    "Normal": 1
};

/**
 * @param {{ pageId?: string, forceManualUpload?: boolean, forceWorklistOnly?: boolean }} config
 */
function initAtencionTecnicaModule(config = {}) {
    const profile = typeof getLabProfile === 'function' ? getLabProfile() : { uses_dicom_worklist: true };
    if (config.forceWorklistOnly && profile.uses_dicom_worklist === false) {
        if (typeof loadPage === 'function') {
            loadPage('atencion');
            return Promise.resolve();
        }
    }

    ATENCION_MODULO = {
        pageId: config.pageId || 'worklist',
        forceManualUpload: !!config.forceManualUpload,
        forceWorklistOnly: !!config.forceWorklistOnly,
    };

    if ($('#atencionPageTitle').length && config.listTitle) {
        $('#atencionPageTitle').text(config.listTitle);
    }
    if ($('#atencionPageSubtitle').length && config.listSubtitle) {
        $('#atencionPageSubtitle').text(config.listSubtitle);
    }

    const boot = async () => {
        await cargarMaquinasFiltro();
        cargarInsumosBodega();
        await cargarWorklistDesdeServidor();

        if (_atencionRefreshTimer) clearInterval(_atencionRefreshTimer);
        _atencionRefreshTimer = setInterval(() => {
            if ($("#modalAtencion").is(":visible") || currentAtencionChain) return;
            cargarWorklistDesdeServidor();
        }, 30000);

        $(document).off('hidden.bs.modal.risAnamnesis', '#modalAtencion')
            .on('hidden.bs.modal.risAnamnesis', '#modalAtencion', risPersistirBorradorAnamnesis);
        $(document).off('change.risWorklistEquipos', '.filtro-equipo-worklist')
            .on('change.risWorklistEquipos', '.filtro-equipo-worklist', onWorklistMachineFilterChange);
        if (typeof risEnableTextFilePaste === 'function') {
            risEnableTextFilePaste(['#txtAnamnesis']);
        }
    };

    if (typeof refreshLabProfileFromApi === 'function') {
        refreshLabProfileFromApi().then(boot);
    } else {
        boot();
    }
}

window.initAtencionTecnicaModule = initAtencionTecnicaModule;
window.guardarAnamnesisWorklist = guardarAnamnesisWorklist;
window.onWorklistMachineFilterChange = onWorklistMachineFilterChange;

async function cargarMaquinasFiltro() {
    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId(false)) return;
    const cont = $('#filtroEquiposWorklist');
    if (!cont.length) return;

    let prevSeleccion = [];
    try {
        const raw = sessionStorage.getItem('ris_worklist_machine_filter');
        if (raw) {
            const parsed = JSON.parse(raw);
            prevSeleccion = Array.isArray(parsed) ? parsed.map(String) : (raw ? [String(raw)] : []);
        }
    } catch (_) {
        prevSeleccion = [];
    }

    try {
        const response = await fetch(`${API_URL}/agenda-catalogs`, {
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        const data = await response.json();

        currentMachinesFromDB = [];
        if (response.ok && data.success && data.data?.machines) {
            currentMachinesFromDB = data.data.machines
                .filter((m) => m.is_active !== false)
                .sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'es'));
        }

        if (!currentMachinesFromDB.length) {
            cont.html('<span class="small text-warning">Sin equipos activos en esta sede.</span>');
            return;
        }

        const todosMarcados = prevSeleccion.length === 0;
        const html = currentMachinesFromDB.map((m) => {
            const id = String(m.id);
            const grupo = m.group_code || m.group;
            const label = grupo ? `${m.name} [${grupo}]` : m.name;
            const checked = todosMarcados || prevSeleccion.includes(id) ? 'checked' : '';
            return `
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input filtro-equipo-worklist" type="checkbox"
                        id="filtroEquipoWl_${id}" value="${id}" ${checked}>
                    <label class="form-check-label small" for="filtroEquipoWl_${id}">${label}</label>
                </div>
            `;
        }).join('');

        cont.html(`<span class="small text-muted align-self-center">Equipos:</span>${html}`);
    } catch (e) {
        console.error('Error cargando salas para filtro', e);
        cont.html('<span class="small text-danger">No se pudieron cargar los equipos.</span>');
    }
}

function obtenerEquiposWorklistSeleccionados() {
    const ids = [];
    $('.filtro-equipo-worklist:checked').each(function () {
        const v = String($(this).val() || '').trim();
        if (v) ids.push(v);
    });
    return ids;
}

function guardarFiltroEquiposWorklist() {
    const ids = obtenerEquiposWorklistSeleccionados();
    const total = currentMachinesFromDB.length;
    if (!ids.length || (total > 0 && ids.length >= total)) {
        sessionStorage.removeItem('ris_worklist_machine_filter');
    } else {
        sessionStorage.setItem('ris_worklist_machine_filter', JSON.stringify(ids));
    }
}

function citaPasaFiltroEquipoWorklist(study) {
    const seleccionados = obtenerEquiposWorklistSeleccionados();
    const total = currentMachinesFromDB.length;
    if (!seleccionados.length || (total > 0 && seleccionados.length >= total)) {
        return true;
    }
    const machineId = risMachineIdEstudioWorklist(study);
    return seleccionados.includes(machineId);
}

function onWorklistMachineFilterChange() {
    guardarFiltroEquiposWorklist();
    renderWorklist();
}

function risMachineIdEstudioWorklist(study) {
    if (!study) return '';
    return String(study.machine_id || study.appointment?.machine_id || '');
}

function risNormalizeMachineGroup(group) {
    const g = String(group || '').trim().toUpperCase();
    const map = {
        ECO: 'US',
        SCANNER: 'CT',
        TC: 'CT',
        RM: 'MRI',
        MR: 'MRI',
        MG: 'MAMO',
        DENSITO: 'DEXA',
    };
    return map[g] || g;
}

function risMaquinasMismoGrupo(machineGroup) {
    const norm = risNormalizeMachineGroup(machineGroup);
    if (!norm) return [];
    return currentMachinesFromDB.filter(
        (m) => risNormalizeMachineGroup(m.group) === norm
    );
}

function risMergePreviousReports(chain, paths) {
    if (!chain) return;
    if (!Array.isArray(chain.previousReports)) {
        chain.previousReports = [];
    }
    (paths || []).forEach((path) => {
        if (path && !chain.previousReports.includes(path)) {
            chain.previousReports.push(path);
        }
    });
}

async function cargarInsumosBodega() {
    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId(false)) return;
    try {
        const response = await fetch(`${API_URL}/supplies`, {
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        const data = await response.json();

        if (response.ok && data.data) {
            currentSuppliesFromDB = data.data;
            const select = $("#insumoSelect");
            select.empty().append('<option value="">Seleccione insumo...</option>');
            currentSuppliesFromDB.forEach(ins => {
                if (ins.stock > 0) {
                    select.append(`<option value="${ins.id}" data-stock="${ins.stock}">${ins.category || 'Insumo'} - ${ins.name} (Disp: ${ins.stock})</option>`);
                }
            });
            renderAlertasInsumos();
        }
    } catch (e) { console.error("Error cargando insumos", e); }
}

async function cargarWorklistDesdeServidor() {
    const tbody = $("#worklistTable tbody");
    const labId = typeof risRequireConcreteLabId === 'function'
        ? risRequireConcreteLabId(false)
        : localStorage.getItem('ris_lab_id');

    if (!labId) {
        tbody.html('<tr><td colspan="7" class="text-center text-warning p-4">Seleccione una sede específica en la barra superior.</td></tr>');
        return;
    }

    try {
        const response = await fetch(`${API_URL}/worklist`, {
            headers: typeof risBuildAuthHeaders === 'function'
                ? risBuildAuthHeaders()
                : { Authorization: `Bearer ${localStorage.getItem('ris_token')}`, 'X-Lab-Id': labId, Accept: 'application/json' }
        });
        const data = await response.json();

        if (response.ok && data.data) {
            currentWorklistFromDB = data.data;
            renderWorklist();
            if ($("#modalAtencion").is(":visible") && currentAtencionChain) {
                syncAtencionChainFromWorklist();
                renderAtencionEstudios(currentAtencionChain);
                applyWorklistDicomUI(currentAtencionChain);
                risActualizarBotonesDocumentosWorklist(currentAtencionChain);
                $("#txtAnamnesis").val(risAnamnesisParaCadena(currentAtencionChain));
            }
        } else {
            tbody.html('<tr><td colspan="7" class="text-center text-muted p-4">No se pudo cargar la lista.</td></tr>');
        }
    } catch (e) {
        console.error("Error Worklist:", e);
        tbody.html('<tr><td colspan="7" class="text-center text-danger p-4"><i class="bi bi-wifi-off me-2"></i>Error de conexión al servidor</td></tr>');
    }
}

function risActualizarBotonesDocumentosWorklist(chain) {
    if (!chain) return;
    if (chain.medicalOrder) {
        $("#btnVerOrdenTM").prop("disabled", false).removeClass("btn-outline-success").addClass("btn-success text-white");
    } else {
        $("#btnVerOrdenTM").prop("disabled", true).removeClass("btn-success text-white").addClass("btn-outline-success");
    }
    if (chain.survey) {
        $("#btnVerEncuestaTM").prop("disabled", false).removeClass("btn-outline-danger").addClass("btn-danger text-white");
    } else {
        $("#btnVerEncuestaTM").prop("disabled", true).removeClass("btn-danger text-white").addClass("btn-outline-danger");
    }

    const previos = Array.isArray(chain.previousReports) ? chain.previousReports : [];
    const badge = $("#badgeInformesPrevios");
    if (previos.length > 0) {
        $("#btnVerInformesPreviosTM").prop("disabled", false)
            .removeClass("btn-outline-secondary").addClass("btn-secondary text-white");
        badge.removeClass("d-none").text(previos.length);
    } else {
        $("#btnVerInformesPreviosTM").prop("disabled", true)
            .removeClass("btn-secondary text-white").addClass("btn-outline-secondary");
        badge.addClass("d-none").text("0");
    }
}

function risResolverUrlDocumento(path) {
    if (!path) return null;
    const raw = String(path).trim();
    if (!raw) return null;
    if (raw.startsWith('data:')) return raw;
    if (raw.startsWith('http://') || raw.startsWith('https://')) return raw;
    const normalized = raw.startsWith('/') ? raw : `/${raw}`;
    return `${window.location.origin}${normalized}`;
}

function risAbrirDocumentoEnNuevaVentana(path) {
    const fullUrl = risResolverUrlDocumento(path);
    if (!fullUrl) return false;

    if (fullUrl.startsWith('data:')) {
        const match = fullUrl.match(/data:([^;]+);base64,(.+)/);
        if (!match) return false;
        const mimeType = match[1];
        const byteString = atob(match[2]);
        const ab = new ArrayBuffer(byteString.length);
        const ia = new Uint8Array(ab);
        for (let i = 0; i < byteString.length; i++) {
            ia[i] = byteString.charCodeAt(i);
        }
        const blob = new Blob([ab], { type: mimeType });
        window.open(URL.createObjectURL(blob), '_blank');
        return true;
    }

    window.open(fullUrl, '_blank');
    return true;
}

function getBadgePrioridad(prioridad) {
    if (prioridad === 'Urgencia') return '<span class="badge bg-danger fw-bold shadow-sm" style="animation: pulse 1.5s infinite;">🚨 Urgencia</span>';
    if (prioridad === 'Alta') return '<span class="badge bg-warning text-dark fw-bold">Alta</span>';
    return '<span class="badge bg-light text-secondary border">Normal</span>';
}

function renderWorklist() {
    const tbody = $("#worklistTable tbody");
    const search = $("#searchPatient").val() ? $("#searchPatient").val().toLowerCase() : "";

    tbody.empty();
    currentCadenas = {};

    const estadosVisibles = ['confirmado', 'en_atencion', 'devuelto_worklist', 'dicom_enviado'];

    currentWorklistFromDB.forEach(study => {
        const app = study.appointment;
        if (!app) return;
        if (!estadosVisibles.includes(app.status)) return;
        if (!citaPasaFiltroEquipoWorklist(study)) return;

        const p = app.patient?.persona || {};
        const nombreCompleto = `${p.names || ''} ${p.last_name_1 || ''}`.trim() || 'Paciente';
        const rutSeguro = p.rut || `NORUT-${app.id}`;

        if (search && !nombreCompleto.toLowerCase().includes(search) && !rutSeguro.toLowerCase().includes(search)) return;

        const dateStr = String(app.start_time || new Date().toISOString()).split('T')[0];
        const chainId = `${rutSeguro}_${dateStr}`;

        if (!currentCadenas[chainId]) {
            currentCadenas[chainId] = {
                chainId: chainId,
                paciente: nombreCompleto,
                rut: rutSeguro,
                cleanTime: app.start_time,
                citasIds: new Set(),
                items: [],
                insumosAsignados: [],
                statusGlobal: app.status,
                accessionGlobal: app.accession_number,
                returnReason: app.return_reason,
                // --- NUEVO ---
                medicalOrder: app.medical_order_path,
                survey: app.survey_path,
                previousReports: Array.isArray(app.previous_reports_paths) ? [...app.previous_reports_paths] : [],
                receiptPrinted: Boolean(app.receipt_printed),
            };
        }

        if (app.medical_order_path) {
            currentCadenas[chainId].medicalOrder = app.medical_order_path;
        }
        if (app.survey_path) {
            currentCadenas[chainId].survey = app.survey_path;
        }
        if (app.receipt_printed) {
            currentCadenas[chainId].receiptPrinted = true;
        }
        risMergePreviousReports(currentCadenas[chainId], app.previous_reports_paths);

        currentCadenas[chainId].items.push(study);
        currentCadenas[chainId].citasIds.add(app.id); // Registramos el ID de la cita

        if (app.status === 'dicom_enviado') {
            currentCadenas[chainId].statusGlobal = 'dicom_enviado';
        }
        if (app.accession_number) {
            currentCadenas[chainId].accessionGlobal = app.accession_number;
        }

        if (app.return_reason) {
            currentCadenas[chainId].returnReason = app.return_reason;
        }
    });

    const cadenasArray = Object.values(currentCadenas);

    if (cadenasArray.length === 0) {
        tbody.append(`<tr><td colspan="7" class="text-center text-muted p-5"><i class="bi bi-cup-hot fs-1 d-block mb-3"></i>No hay pacientes en espera en este momento.</td></tr>`);
        return;
    }

    cadenasArray.sort((a, b) => new Date(a.cleanTime).getTime() - new Date(b.cleanTime).getTime());

    cadenasArray.forEach(cadena => {
        const salas = [...new Set(cadena.items.map(i => i.machine_name || 'Sala'))];
        const examenes = cadena.items.map(i => `<i class="bi bi-check2 me-1"></i>${i.exam_name}`).join("<br>");
        const badgesSalas = salas.map(s => `<span class="badge bg-secondary me-1">${s}</span>`).join('');
        const horaStr = new Date(cadena.cleanTime).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        let statusText;
        if (cadena.statusGlobal === 'dicom_enviado') {
            statusText = usesDicomWorklist()
                ? '<span class="badge bg-info text-white"><i class="bi bi-cpu me-1"></i>En Modalidad</span>'
                : '<span class="badge bg-success text-white"><i class="bi bi-cloud-check me-1"></i>Imágenes en PACS</span>';
        } else {
            statusText = usesDicomWorklist()
                ? '<span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>En Espera</span>'
                : '<span class="badge bg-warning text-dark"><i class="bi bi-cloud-upload me-1"></i>Pendiente subida</span>';
        }

        const accLabel = cadena.accessionGlobal
            ? `<div class="small fw-bold text-primary mt-1"><i class="bi bi-upc-scan me-1"></i>A.N: ${cadena.accessionGlobal}</div>` : '';

        const alertaDevolucion = cadena.returnReason
            ? `<div class="small fw-bold text-danger mt-1 bg-danger-subtle p-1 rounded border border-danger">
                 <i class="bi bi-exclamation-triangle-fill"></i> Rechazado: ${cadena.returnReason}
               </div>`
            : '';

        const badgePrioridad = getBadgePrioridad('Normal');

        tbody.append(`
            <tr class="align-middle border-bottom">
                <td class="fw-bold text-primary">${horaStr}</td>
                <td class="fw-bold text-dark">
                    ${cadena.paciente} <br>
                    <small class="text-muted">${cadena.rut}</small>
                    ${accLabel}
                    ${alertaDevolucion} </td>
                <td class="small text-secondary fw-bold">${examenes}</td>
                <td>${badgesSalas}</td>
                <td class="text-center">${badgePrioridad}</td>
                <td class="text-center">${statusText}</td>
                <td class="text-center">
                    <button class="btn btn-primary btn-sm fw-bold px-3 shadow-sm" onclick="abrirAtencion('${cadena.chainId}')">
                        <i class="bi bi-play-circle me-1"></i> Atender
                    </button>
                </td>
            </tr>
        `);
    });
}

function risPersistirBorradorAnamnesis() {
    if (!currentAtencionChain?.chainId) return;
    anamnesisDrafts[currentAtencionChain.chainId] = $("#txtAnamnesis").val();
}

function risAnamnesisParaCadena(chain) {
    if (!chain) return '';
    if (Object.prototype.hasOwnProperty.call(anamnesisDrafts, chain.chainId)) {
        return anamnesisDrafts[chain.chainId];
    }
    for (const item of chain.items || []) {
        const texto = item.anamnesis;
        if (texto && String(texto).trim()) {
            return String(texto).trim();
        }
    }
    return '';
}

function risActualizarAnamnesisEnCadena(chain, texto) {
    if (!chain) return;
    anamnesisDrafts[chain.chainId] = texto;
    (chain.items || []).forEach((item) => {
        item.anamnesis = texto;
    });
}

function abrirAtencion(chainId) {
    risPersistirBorradorAnamnesis();

    currentAtencionChain = currentCadenas[chainId];
    if (!currentAtencionChain) return;

    $("#atencionNombre").text(currentAtencionChain.paciente);
    $("#atencionRut").text(currentAtencionChain.rut);

    $("#atencionId").text(chainId + ` (${currentAtencionChain.citasIds.size} Cita/s)`);

    const salas = [...new Set(currentAtencionChain.items.map(i => i.machine_name || 'Sala'))];
    $("#atencionSala").text(salas.join(" + "));

    renderAtencionEstudios(currentAtencionChain);

    $("#txtAnamnesis").val(risAnamnesisParaCadena(currentAtencionChain));
    $("#insumoSelect").val("");
    $("#insumoQty").val("1");
    renderInsumosUsados();

    configureDicomIntegrationUI();
    applyWorklistDicomUI(currentAtencionChain);
    risActualizarBotonesDocumentosWorklist(currentAtencionChain);

    openModal("modalAtencion");
}

function renderAtencionEstudios(chain) {
    if (!chain?.items?.length) {
        $("#atencionEstudios").empty();
        return;
    }

    const listaHtml = chain.items.map(item => {
        const currentMachineId = risMachineIdEstudioWorklist(item);
        const opciones = risMaquinasMismoGrupo(item.machine_group);
        const puedeCambiar = opciones.length > 1;
        const optionsHtml = opciones.map((m) => {
            const selected = String(m.id) === String(currentMachineId) ? 'selected' : '';
            return `<option value="${m.id}" ${selected}>${m.name}</option>`;
        }).join('');

        return `
        <div class="list-group-item bg-white border-primary border-start border-4 mb-1">
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                <div class="flex-grow-1">
                    <strong class="text-dark">${item.exam_name}</strong>
                    <small class="d-block text-muted">Sub-examen: ${item.sub_exam_name || 'N/A'}</small>
                    <div class="mt-2" style="max-width: 280px;">
                        <label class="form-label small text-muted fw-bold mb-0">Sala</label>
                        <select class="form-select form-select-sm wl-study-machine"
                            data-study-id="${item.id}"
                            data-prev-machine="${currentMachineId}"
                            ${puedeCambiar ? '' : 'disabled title="No hay otra sala del mismo grupo"'}>
                            ${optionsHtml || `<option value="${currentMachineId}">${item.machine_name || 'Sala'}</option>`}
                        </select>
                    </div>
                </div>
                <span class="badge bg-primary rounded-pill align-self-center">Cant: ${item.quantity}</span>
            </div>
        </div>`;
    }).join('');

    $("#atencionEstudios").html(listaHtml);
    $("#atencionEstudios .wl-study-machine")
        .off('change.wlRoom focus.wlRoom')
        .on('focus.wlRoom', function () {
            $(this).data('prev-machine', $(this).val());
        })
        .on('change.wlRoom', onWorklistStudyMachineChange);
}

async function onWorklistStudyMachineChange() {
    const $select = $(this);
    const studyId = $select.data('study-id');
    const machineId = $select.val();
    const prevMachineId = $select.data('prev-machine');

    if (!studyId || !machineId || String(machineId) === String(prevMachineId)) {
        return;
    }

    $select.prop('disabled', true);

    try {
        const response = await fetch(`${API_URL}/worklist/studies/${studyId}/reassign-machine`, {
            method: 'POST',
            headers: typeof risBuildAuthHeaders === 'function'
                ? risBuildAuthHeaders({ 'Content-Type': 'application/json' })
                : {},
            body: JSON.stringify({ machine_id: machineId }),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'No se pudo cambiar la sala');
        }

        const item = (currentAtencionChain?.items || []).find((s) => String(s.id) === String(studyId));
        if (item) {
            item.machine_id = data.machine_id;
            item.machine_name = data.machine_name;
            item.machine_group = data.machine_group;
        }

        $select.data('prev-machine', machineId);
        const salas = [...new Set((currentAtencionChain?.items || []).map(i => i.machine_name || 'Sala'))];
        $("#atencionSala").text(salas.join(" + "));

        if (typeof showToast === 'function') {
            showToast(`Sala actualizada: ${data.machine_name}`, 'success');
        }

        await cargarWorklistDesdeServidor();
        if (currentAtencionChain) {
            renderAtencionEstudios(currentAtencionChain);
        }
    } catch (e) {
        console.error(e);
        $select.val(prevMachineId);
        if (typeof showToast === 'function') {
            showToast(`❌ ${e.message}`, 'danger');
        }
    } finally {
        const item = (currentAtencionChain?.items || []).find((s) => String(s.id) === String(studyId));
        const opciones = risMaquinasMismoGrupo(item?.machine_group);
        $select.prop('disabled', opciones.length <= 1);
    }
}

function usesDicomWorklist() {
    if (ATENCION_MODULO.forceManualUpload) return false;
    if (ATENCION_MODULO.forceWorklistOnly) return true;
    const p = typeof getLabProfile === 'function' ? getLabProfile() : {};
    return p.uses_dicom_worklist !== false;
}

function configureDicomIntegrationUI() {
    const manual = !usesDicomWorklist();
    $("#dicomWorklistPanel").toggleClass("d-none", manual);
    $("#dicomManualUploadPanel").toggleClass("d-none", !manual);
}

function hasWorklistAccession(acc) {
    return acc != null && String(acc).trim() !== '';
}

/** Cadena con MWL ya enviada o devuelta por radiólogo (puede reenviarse). */
function chainAllowsWorklistResend(chain) {
    if (!chain) return false;
    if (hasWorklistAccession(chain.accessionGlobal)) return true;
    return chain.statusGlobal === 'dicom_enviado';
}

function syncAtencionChainFromWorklist() {
    if (!currentAtencionChain?.chainId) return;
    const refreshed = currentCadenas[currentAtencionChain.chainId];
    if (refreshed) {
        currentAtencionChain = refreshed;
    }
}

function applyWorklistDicomUI(chain) {
    if (!chain) return;
    const manual = !usesDicomWorklist();
    if (manual) {
        setDicomUI(chain.statusGlobal === 'dicom_enviado', chain.accessionGlobal);
        return;
    }
    const resend = chainAllowsWorklistResend(chain);
    setDicomUI(resend, chain.accessionGlobal);
}

function setDicomUI(enviado, acc = null) {
    const manual = !usesDicomWorklist();
    const hasAcc = hasWorklistAccession(acc);
    const worklistResend = !manual && (enviado || hasAcc);

    if (hasAcc) {
        $("#globalAccessionDisplay").removeClass("d-none").text("Accession: " + acc);
    } else {
        $("#globalAccessionDisplay").addClass("d-none").text("");
    }

    if (manual) {
        if (enviado) {
            $("#btnDicomUpload").prop("disabled", true).removeClass("btn-info").addClass("btn-secondary")
                .html('<i class="bi bi-check-circle"></i> IMÁGENES EN PACS');
            $("#dicomStatus").html('<span class="text-success fw-bold">● Estudio cargado en Orthanc</span>');
        } else {
            $("#btnDicomUpload").prop("disabled", false).removeClass("btn-secondary").addClass("btn-info text-white")
                .html('<i class="bi bi-cloud-upload me-2"></i> SUBIR IMÁGENES A PACS');
            $("#dicomStatus").html('<span class="text-muted">Suba el archivo exportado del equipo (CBCT, etc.)</span>');
        }
        return;
    }

    if (worklistResend) {
        $("#btnDicom").prop("disabled", false)
            .removeClass("btn-secondary btn-info text-white")
            .addClass("btn-warning text-dark")
            .html('<i class="bi bi-arrow-repeat me-1"></i> REENVIAR A EQUIPOS');
        $("#dicomStatus").html(
            '<span class="text-success fw-bold">● Worklist en PACS</span> '
            + '<span class="text-muted">— pulse para reenviar al equipo</span>'
        );
        return;
    }

    $("#btnDicom").prop("disabled", false)
        .removeClass("btn-secondary btn-warning text-dark")
        .addClass("btn-info text-white")
        .html('<i class="bi bi-broadcast me-2"></i> CREAR A.N. Y ENVIAR A EQUIPOS');
    $("#dicomStatus").html('<span class="text-muted">Esperando envío DICOM...</span>');
}

async function subirDicomManual() {
    if (!currentAtencionChain) return;

    const input = document.getElementById('dicomManualFile');
    if (!input?.files?.length) {
        return showToast('Seleccione un archivo .dcm o .zip.', 'warning');
    }

    const btn = $("#btnDicomUpload");
    const citas = Array.from(currentAtencionChain.citasIds);

    try {
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Subiendo a PACS...');

        let ultimoAccession = null;
        let exitos = 0;
        const [primeraCita, ...restoCitas] = citas;

        const form = new FormData();
        form.append('dicom_file', input.files[0]);

        const response = await fetch(`${API_URL}/appointments/${primeraCita}/upload-dicom`, {
            method: 'POST',
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {},
            body: form,
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Error al subir el estudio');
        }

        ultimoAccession = data.accession;
        exitos = 1;

        for (const citaId of restoCitas) {
            const linkRes = await fetch(`${API_URL}/appointments/${citaId}/mark-dicom-received`, {
                method: 'POST',
                headers: typeof risBuildAuthHeaders === 'function'
                    ? risBuildAuthHeaders({ 'Content-Type': 'application/json' })
                    : {},
                body: JSON.stringify({ accession: ultimoAccession }),
            });
            if (linkRes.ok) exitos++;
        }

        if (exitos > 0) {
            currentAtencionChain.statusGlobal = 'dicom_enviado';
            currentAtencionChain.accessionGlobal = ultimoAccession;
            setDicomUI(true, ultimoAccession);
            input.value = '';
            showToast(`✅ ${exitos} estudio(s) subido(s) a Orthanc.`, 'success');
            await cargarWorklistDesdeServidor();
        }
    } catch (e) {
        console.error(e);
        showToast(`❌ ${e.message}`, 'danger');
    } finally {
        btn.prop('disabled', false);
        applyWorklistDicomUI(currentAtencionChain);
    }
}

async function enviarADicom() {
    if (!currentAtencionChain) return;

    const btn = $("#btnDicom");
    const citasInvolucradas = Array.from(currentAtencionChain.citasIds);
    const eraReenvio = chainAllowsWorklistResend(currentAtencionChain);

    let ultimoAccession = null;
    let exitos = 0;
    let fallidos = 0;
    let ultimoError = '';
    let comprobantesImpresos = 0;
    let comprobantesOmitidos = 0;
    let comprobanteError = '';

    try {
        btn.prop("disabled", true).html('<span class="spinner-border spinner-border-sm me-2"></span>Sincronizando...');

        for (const citaId of citasInvolucradas) {
            try {
                const response = await fetch(`${API_URL}/appointments/${citaId}/dicom`, {
                    method: 'POST',
                    headers: typeof risBuildAuthHeaders === 'function'
                        ? risBuildAuthHeaders({ 'Content-Type': 'application/json' })
                        : {}
                });

                if (response.ok) {
                    const data = await response.json();
                    ultimoAccession = data.accession || data.accession_number;
                    if (data.worklist) {
                        window._lastWorklistModalityHint = data;
                    }
                    const receipt = data.receipt || {};
                    if (receipt.printed && !receipt.skipped) {
                        comprobantesImpresos++;
                        if (currentAtencionChain) currentAtencionChain.receiptPrinted = true;
                    } else if (receipt.skipped && receipt.printed) {
                        comprobantesOmitidos++;
                        if (currentAtencionChain) currentAtencionChain.receiptPrinted = true;
                    } else if (receipt.attempted && !receipt.printed) {
                        comprobanteError = receipt.message || 'Error de impresora térmica';
                    }
                    exitos++;
                } else {
                    const raw = await response.text();
                    let msg = raw;
                    try {
                        const parsed = JSON.parse(raw);
                        msg = parsed.message || raw;
                    } catch (_) { /* texto plano */ }
                    ultimoError = msg;
                    console.error(`Error en Cita ID ${citaId}:`, msg);
                    fallidos++;
                }
            } catch (err) {
                ultimoError = err.message || 'Error de red';
                fallidos++;
            }
        }

        if (exitos > 0) {
            currentAtencionChain.statusGlobal = 'dicom_enviado';
            currentAtencionChain.accessionGlobal = ultimoAccession;
            applyWorklistDicomUI(currentAtencionChain);
        }

        if (exitos > 0 && fallidos === 0) {
            const hint = window._lastWorklistModalityHint?.worklist;
            let extra = '';
            if (hint) {
                extra = ` Estación «${hint.scheduled_station_ae}» (${hint.modality}). En el equipo MWL: ${hint.pacs_dicom_host}:${hint.pacs_dicom_port}, AE «${hint.pacs_dicom_aet}».`;
            }
            if (comprobantesImpresos > 0) {
                extra += ` Comprobante térmico impreso (${comprobantesImpresos}).`;
            } else if (comprobantesOmitidos > 0) {
                extra += ' Comprobante ya estaba impreso.';
            } else if (comprobanteError) {
                extra += ` (Epson: ${comprobanteError.length > 80 ? comprobanteError.slice(0, 80) + '…' : comprobanteError})`;
            }
            showToast(
                (eraReenvio
                    ? `📡 Worklist reenviada al PACS (${exitos} cita(s)).`
                    : `📡 Orden en PACS (${exitos} cita(s)).`)
                    + extra,
                comprobanteError && comprobantesImpresos === 0 ? 'warning' : 'success'
            );
        } else if (exitos > 0 && fallidos > 0) {
            showToast(`⚠️ Sincronización incompleta: ${exitos} OK, ${fallidos} errores.`, "warning");
        } else {
            const detalle = ultimoError
                ? (ultimoError.length > 180 ? ultimoError.slice(0, 180) + '…' : ultimoError)
                : 'No se pudo comunicar con Orthanc (PACS).';
            showToast(`❌ ${detalle}`, "danger");
            applyWorklistDicomUI(currentAtencionChain);
        }

        await cargarWorklistDesdeServidor();

    } catch (e) {
        console.error("Error de red:", e);
        showToast("🔌 Error de conexión con el servidor central.", "danger");
        applyWorklistDicomUI(currentAtencionChain);
    } finally {
        if (exitos === 0) {
            btn.prop("disabled", false);
            applyWorklistDicomUI(currentAtencionChain);
        }
    }
}

function agregarInsumoAAtencion() {
    const select = $("#insumoSelect option:selected");
    const id = select.val();
    const maxStock = parseInt(select.data("stock") || 0);
    const qty = parseInt($("#insumoQty").val()) || 1;

    if (!id) return;
    if (qty > maxStock) return showToast(`Solo quedan ${maxStock} unidades disponibles.`, "warning");

    const nombre = select.text().split(' (')[0];

    const existente = currentAtencionChain.insumosAsignados.find(i => String(i.id) === String(id));
    if (existente) {
        existente.qty += qty;
    } else {
        currentAtencionChain.insumosAsignados.push({ id: id, nombre: nombre, qty: qty });
    }

    renderInsumosUsados();
    $("#insumoSelect").val("");
    $("#insumoQty").val("1");
}

function renderInsumosUsados() {
    const tbody = $("#tablaInsumosUsados tbody");
    tbody.empty();

    if (!currentAtencionChain || currentAtencionChain.insumosAsignados.length === 0) {
        tbody.append('<tr><td colspan="3" class="text-center text-muted small py-3">Sin insumos registrados.</td></tr>');
        return;
    }

    currentAtencionChain.insumosAsignados.forEach((ins, index) => {
        tbody.append(`
            <tr>
                <td class="ps-3 fw-bold text-dark">${ins.nombre}</td>
                <td class="text-center"><span class="badge bg-secondary rounded-pill px-2">${ins.qty}</span></td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-danger py-0 px-1 border-0" onclick="quitarInsumoAtencion(${index})">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </td>
            </tr>
        `);
    });
}

function quitarInsumoAtencion(index) {
    if (currentAtencionChain) {
        currentAtencionChain.insumosAsignados.splice(index, 1);
        renderInsumosUsados();
    }
}

function renderAlertasInsumos() {
    let container = $("#alertasInsumosContainer");
    container.empty();
    let alertasHtml = '';

    currentSuppliesFromDB.forEach(insumo => {
        if (insumo.stock <= 10) {
            alertasHtml += `
                <span class="badge bg-danger text-white me-2 mb-2 p-2 shadow-sm">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    ${insumo.name}: Quedan ${insumo.stock}
                </span>`;
        }
    });

    if (alertasHtml !== '') {
        container.html(`
            <div class="alert border-danger shadow-sm py-2 mb-0 d-flex align-items-center" style="background-color: #fff5f5;">
                <i class="bi bi-boxes fs-3 me-3 text-danger pulse-icon"></i>
                <div>
                    <strong class="d-block text-danger mb-1">Alertas de Inventario Clínico</strong>
                    <div>${alertasHtml}</div>
                </div>
            </div>
            <style>.pulse-icon { animation: pulse 1.5s infinite; } @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }</style>
        `);
    }
}

async function guardarAnamnesisWorklist() {
    if (!currentAtencionChain) return;

    const anamnesis = $("#txtAnamnesis").val().trim();
    if (!anamnesis && !risAnamnesisOpcionalWorklist()) {
        return showToast('Escriba los síntomas o la anamnesis antes de guardar.', 'warning');
    }

    const btn = $("#btnGuardarAnamnesis");
    const labelOriginal = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

    const citasInvolucradas = Array.from(currentAtencionChain.citasIds);

    try {
        for (const citaId of citasInvolucradas) {
            const response = await fetch(`${API_URL}/appointments/${citaId}/save-anamnesis`, {
                method: 'POST',
                headers: typeof risBuildAuthHeaders === 'function'
                    ? risBuildAuthHeaders({ 'Content-Type': 'application/json' })
                    : { 'Content-Type': 'application/json' },
                body: JSON.stringify({ anamnesis }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                throw new Error(data.message || `Error al guardar (${response.status})`);
            }
        }

        risActualizarAnamnesisEnCadena(currentAtencionChain, anamnesis);
        if (typeof showToast === 'function') {
            showToast('Síntomas / anamnesis guardados.', 'success');
        }
    } catch (error) {
        console.error('guardarAnamnesisWorklist:', error);
        if (typeof showToast === 'function') {
            showToast(`No se pudo guardar: ${error.message}`, 'danger');
        }
    } finally {
        btn.prop('disabled', false).html(labelOriginal);
    }
}

async function finalizarAtencion() {
    if (!currentAtencionChain) return;

    const anamnesis = $("#txtAnamnesis").val().trim();
    if (!anamnesis && !risAnamnesisOpcionalWorklist()) {
        return showToast("⚠️ La anamnesis/notas técnicas son obligatorias.", "warning");
    }

    const btn = $("#btnFinalizar");
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Finalizando...');

    const citasInvolucradas = Array.from(currentAtencionChain.citasIds);

    try {
        const payload = {
            anamnesis: anamnesis,
            supplies: currentAtencionChain.insumosAsignados.map(ins => ({ id: ins.id, quantity: ins.qty })),
            status: 'en_informe'
        };

        for (const citaId of citasInvolucradas) {
            const response = await fetch(`${API_URL}/appointments/${citaId}/complete-worklist`, {
                method: 'POST',
                headers: typeof risBuildAuthHeaders === 'function'
                    ? risBuildAuthHeaders({ 'Content-Type': 'application/json' })
                    : {},
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.message || `Error del servidor: ${response.status}`);
            }
        }

        closeModal("modalAtencion");
        if (currentAtencionChain?.chainId) {
            delete anamnesisDrafts[currentAtencionChain.chainId];
        }
        cargarWorklistDesdeServidor();
        cargarInsumosBodega();
        if (typeof showToast === 'function') showToast("✅ Estudios finalizados y derivados al Radiólogo.", "success");

    } catch (error) {
        console.error("Error en finalizarAtencion:", error);
        if (typeof showToast === 'function') showToast(`❌ Error: ${error.message}`, "danger");
    } finally {
        btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> FINALIZAR Y ENVIAR A RADIÓLOGO');
    }
}

function abrirDocWorklist(tipo) {
    if (!currentAtencionChain) return;

    if (tipo === 'previos') {
        const paths = Array.isArray(currentAtencionChain.previousReports)
            ? currentAtencionChain.previousReports
            : [];
        if (!paths.length) {
            return showToast("No hay informes previos escaneados.", "warning");
        }
        paths.forEach((path, index) => {
            setTimeout(() => risAbrirDocumentoEnNuevaVentana(path), index * 350);
        });
        if (paths.length > 1 && typeof showToast === 'function') {
            showToast(`Abriendo ${paths.length} documento(s) de informes previos.`, "info");
        }
        return;
    }

    const path = tipo === 'orden' ? currentAtencionChain.medicalOrder : currentAtencionChain.survey;
    if (!path) {
        const label = tipo === 'orden' ? 'orden médica' : 'encuesta';
        return showToast(`Este documento (${label}) no fue escaneado aún.`, "warning");
    }

    if (!risAbrirDocumentoEnNuevaVentana(path)) {
        showToast("No se pudo abrir el documento.", "danger");
    }
}

async function escanearDocumentoWorklist(tipo) {
    if (!currentAtencionChain) return;

    const isEncuesta = tipo === 'encuesta';
    const btn = isEncuesta ? $("#btnEscanearEncuestaTM") : $("#btnEscanearInformesPreviosTM");
    const textoOriginal = btn.html();

    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Escaneando...');

    try {
        const bridgeUrl = typeof LOCAL_BRIDGE_URL !== 'undefined' ? LOCAL_BRIDGE_URL : 'http://127.0.0.1:8181';
        const response = await fetch(`${bridgeUrl}/escanear`);
        const data = await response.json();

        if (!response.ok || !data.success || !data.file) {
            throw new Error(data.message || 'Error al escanear');
        }

        await subirDocumentoWorklistToCitas(tipo, data.file);

        const msg = isEncuesta
            ? 'Encuesta digitalizada y guardada.'
            : 'Hoja de informe previo agregada.';
        if (typeof showToast === 'function') showToast(`✅ ${msg}`, 'success');
    } catch (error) {
        console.error('Error del puente:', error);
        if (typeof showToast === 'function') {
            showToast("❌ No se detectó el escáner. Asegúrese de tener el RIS Bridge abierto en su PC.", "danger");
        }
    } finally {
        btn.prop('disabled', false).html(textoOriginal);
    }
}

async function subirDocumentoWorklistToCitas(tipo, base64Data) {
    const citas = currentAtencionChain?.citasIds
        ? Array.from(currentAtencionChain.citasIds)
        : [];

    if (!citas.length) {
        throw new Error('No hay citas asociadas al paciente.');
    }

    const apiType = tipo === 'encuesta' ? 'survey' : 'previous_report';
    let ultimoPath = null;
    let ultimosPrevios = [];

    for (const citaId of citas) {
        const response = await fetch(`${API_URL}/appointments/${citaId}/worklist-document`, {
            method: 'POST',
            headers: typeof risBuildAuthHeaders === 'function'
                ? risBuildAuthHeaders({ 'Content-Type': 'application/json' })
                : {},
            body: JSON.stringify({
                type: apiType,
                document_base64: base64Data,
            }),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Error al guardar el documento');
        }

        ultimoPath = data.path;
        if (Array.isArray(data.previous_reports_paths)) {
            ultimosPrevios = data.previous_reports_paths;
        }
        if (data.survey_path) {
            currentAtencionChain.survey = data.survey_path;
        }
    }

    if (tipo === 'encuesta' && ultimoPath) {
        currentAtencionChain.survey = ultimoPath;
    } else if (tipo === 'previos') {
        risMergePreviousReports(currentAtencionChain, ultimosPrevios.length ? ultimosPrevios : [ultimoPath]);
    }

    risActualizarBotonesDocumentosWorklist(currentAtencionChain);
    await cargarWorklistDesdeServidor();
}
async function devolverAAgenda() {
    if (!currentAtencionChain) return;

    const motivo = await showPrompt(
        "Indique el motivo por el cual devuelve al paciente a recepción:\n(Ej: Paciente no tomó agua, orden incorrecta)",
        { title: "Devolver a recepción" }
    );
    if (motivo === null) return;
    if (motivo.trim() === "") return showToast("⚠️ Debe ingresar un motivo.", "warning");

    const btn = $("#modalAtencion .btn-outline-danger");

    const citasInvolucradas = currentAtencionChain.citasIds
        ? Array.from(currentAtencionChain.citasIds)
        : [currentAtencionChain.cita.id];

    try {
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Devolviendo...');

        for (const citaId of citasInvolucradas) {
            const response = await fetch(`${API_URL}/appointments/${citaId}/status`, {
                method: 'PUT',
                headers: typeof risBuildAuthHeaders === 'function'
                    ? risBuildAuthHeaders({ 'Content-Type': 'application/json' })
                    : {},
                body: JSON.stringify({
                    status: 'agendado',
                    needs_review: true,
                    return_reason: motivo
                })
            });

            if (!response.ok) {
                const errorData = await response.text();
                console.error(`Error Backend (Cita ${citaId}):`, errorData);
                throw new Error(`Fallo en el servidor: ${response.status}`);
            }
        }

        closeModal("modalAtencion");
        cargarWorklistDesdeServidor();
        if (typeof showToast === 'function') showToast("Paciente devuelto a Recepción exitosamente.", "warning");

    } catch (e) {
        console.error("Error completo:", e);
        if (typeof showToast === 'function') showToast("❌ Error al devolver. Revisa la consola (F12).", "danger");
    } finally {
        btn.prop('disabled', false).html('<i class="bi bi-reply-all"></i> Devolver a Recepción');
    }
}

function verificarStockCritico() {
    const criticos = currentSuppliesFromDB.filter(i => i.stock <= 5);
    if (criticos.length > 0) {
        $("#alertasInsumosContainer").html(`
            <div class="alert alert-warning py-2 shadow-sm small">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Stock Crítico:</strong> ${criticos.map(i => i.name).join(", ")}
            </div>
        `);
    }
}