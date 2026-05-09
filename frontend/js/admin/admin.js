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

function esAdminLogueado() {
    const perfil = localStorage.getItem('ris_user_profile') || '';

    return perfil === 'sis_admin' || perfil === 'admin' || perfil === 'super_admin';
}

function initAdmin() {
    loadRISState();
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

    renderReporteHonorarios();
    renderReporteExamenes();
    setupAdminEvents();
}

function setupAdminSync() {
    window.addEventListener('storage', (e) => {
        if (e.key === 'ris_app_data') {
            loadRISState();
            if ($("#tablaUsuariosAdmin").length) {
                renderListaUsuariosAdmin();
                renderListaInsumosAdmin();
                renderListaSalasAdmin();
                renderCatalogoAdmin();
                renderReporteHonorarios();
                renderReporteExamenes();
            }
        }
    });
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
            $("#uNombres").val(p.nombres);
            $("#uPrimerApellido").val(p.apellidoPaterno);
            $("#uSegundoApellido").val(p.apellidoMaterno);
            showToast("Identidad recuperada de la base de datos.", "info");
        }
    });
    $('button[data-bs-target="#tab-config"]').on('shown.bs.tab', function (e) {
        renderTablaSucursales();
    });
}

async function renderListaServiciosAdmin() {
    const tbody = $("#tablaServiciosAdmin tbody");
    if (!tbody.length) return;

    tbody.empty().append(`<tr><td colspan="4" class="text-center p-3"><span class="spinner-border spinner-border-sm text-info"></span> Cargando servicios...</td></tr>`);

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/services`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
                        <td class="ps-4 fw-bold text-secondary">#${srv.id}</td>
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
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
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
    if (!id || !confirm("⚠️ ¿Estás seguro de eliminar este servicio?")) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/services/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
                'X-Lab-Id': labId
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
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();
        tbody.empty();

        if (response.ok && data.success) {
            currentUsersFromDB = data.data;
            const searchStr = $("#searchUsuario").val().toLowerCase();

            const filtrados = currentUsersFromDB.filter(u => {
                const p = u.persona || {};
                const fullName = `${p.names || ''} ${p.last_name_1 || ''}`.toLowerCase();
                return fullName.includes(searchStr) || (u.username || '').toLowerCase().includes(searchStr);
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

        $("#uTitulo").val(u.medical_title || "");
        $("#uUsername").val(u.username || "");
        $("#uPassword").val("");
        $("#uAeTitle").val(u.pacs_ae || "");
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

    // 3. CAPTURAR SUCURSALES (SWITCHES)
    const sucursalesSeleccionadas = $(".chk-lab:checked").map(function () { return $(this).val(); }).get();
    if (sucursalesSeleccionadas.length === 0) return showToast("⚠️ Debe asignar al menos una sucursal al usuario.", "warning");

    const esRadiologo = rolesSeleccionados.includes('radiologo');
    const aeTitle = $("#uAeTitle").val().trim();

    if (esRadiologo && aeTitle === "") {
        $("#uAeTitle").addClass("is-invalid");
        return showToast("⚠️ Los Radiólogos deben tener un PACS AE Title asignado.", "warning");
    } else {
        $("#uAeTitle").removeClass("is-invalid");
    }

    if (hasError) return showToast("⚠️ Faltan datos obligatorios.", "danger");
    const rut = $("#uRut").val().toUpperCase();
    if (!validarRut(rut)) return showToast("❌ RUT inválido.", "danger");

    const formData = new FormData();
    formData.append('rut', rut);
    formData.append('nombres', $("#uNombres").val().trim());
    formData.append('apellidoPaterno', $("#uPrimerApellido").val().trim());
    formData.append('apellidoMaterno', $("#uSegundoApellido").val().trim());
    formData.append('titulo', $("#uTitulo").val());
    if (!$("#uUsername").prop("disabled")) formData.append('username', $("#uUsername").val().trim());

    formData.append('password', $("#uPassword").val());
    formData.append('pacsAE', aeTitle);
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
            showToast(`✅ Usuario guardado correctamente.`, "success");
            renderListaUsuariosAdmin();
        } else {
            showToast(`❌ Error: ${data.message}`, "danger");
        }
    } catch (e) { showToast("🔌 Error de conexión", "danger"); }
}

async function eliminarUsuario() {
    const id = $("#uId").val();
    if (!id || !confirm("¿Revocar acceso a este usuario en la Base de Datos?")) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/users/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
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
    if (!id || !confirm("¿Eliminar este insumo de la base de datos?")) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/supplies/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
            headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();
        tbody.empty();

        if (response.ok && data.success) {
            currentMachinesFromDB = data.data;
            const searchStr = $("#searchSala").val() ? $("#searchSala").val().toLowerCase() : "";
            const filtradas = currentMachinesFromDB.filter(m => m.name.toLowerCase().includes(searchStr) || (m.group || '').toLowerCase().includes(searchStr));

            if (filtradas.length === 0) return tbody.append(`<tr><td colspan="4" class="text-center text-muted p-4">Sin equipos.</td></tr>`);

            filtradas.forEach(res => {
                let badgeColor = (res.group === 'MRI') ? 'bg-danger' : (res.group === 'CT' ? 'bg-info text-dark' : 'bg-primary');
                tbody.append(`
                    <tr>
                        <td class="ps-4 fw-bold text-secondary">ID: '${res.id}'</td>
                        <td class="fw-bold text-dark">
                            <i class="bi bi-display me-2 text-muted"></i>${res.name}
                            <small class="d-block text-muted" style="font-size:0.7rem">${res.manufacturer || ''} ${res.model_name || ''}</small>
                        </td>
                        <td><span class="badge ${badgeColor} px-3 py-2">${res.group}</span></td>
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-outline-success fw-bold me-1" onclick="pingDicom('${res.id}')" title="Test de conexión DICOM C-ECHO">
                                <i class="bi bi-wifi"></i> Ping
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

    // Limpiar campos DICOM
    $("#salaAeTitle, #salaIp, #salaPort, #salaManufacturer, #salaModel, #salaDescription").val("");

    if (id) {
        const sala = currentMachinesFromDB.find(s => String(s.id) === String(id));
        if (sala) {
            $("#salaId").val(sala.id);
            $("#salaName").val(sala.name);
            $("#salaGroup").val(sala.group);
            $("#salaManufacturer").val(sala.manufacturer);
            $("#salaModel").val(sala.model_name);
            $("#salaDescription").val(sala.description);
            // Cargar campos DICOM
            $("#salaAeTitle").val(sala.ae_title || "");
            $("#salaIp").val(sala.ip_address || "");
            $("#salaPort").val(sala.port || "");
        }
    }
    $("#modalSala").modal('show');
}

async function guardarSala() {
    if (!validarFormulario(".req-sala")) return;

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
    const labId = localStorage.getItem('ris_lab_id');
    const btn = $("#modalSala .btn-warning");

    try {
        btn.prop("disabled", true).html('<span class="spinner-border spinner-border-sm"></span>');

        const response = await fetch(`${API_URL}/machines`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
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
        btn.prop("disabled", false).text("Guardar");
    }
}

async function cargarConfigCentro() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/settings`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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

                const perfil = localStorage.getItem('ris_user_profile') || '';
                if (perfil === 'sis_admin' || perfil === 'super_admin') {
                    $("#btnNuevaMatriz").removeClass("d-none");
                    const selectMatriz = $("#matrizTipo");
                    selectMatriz.empty().append('<option value="">Seleccione Tipo...</option>');
                    catalogLabTypes.forEach(t => selectMatriz.append(`<option value="${t.id}">${t.name}</option>`));
                }
                try {
                    const resAll = await fetch(`${API_URL}/all-laboratories`, {
                        headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
                    });
                    const dataAll = await resAll.json();

                    if (resAll.ok && dataAll.success) {
                        currentSucursalesAdmin = dataAll.data.flatMap(padre => padre.children || []);

                        actualizarOpcionesLaboratorioGlobal(dataAll.data);
                    }
                } catch (err) { console.error("Error cargando todos los laboratorios", err); }
            } else {
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
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
            body: formData
        });
        if (response.ok) showToast("✅ Matriz actualizada.", "success");
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
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
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
    if (!id || !confirm("¿Eliminar sucursal?")) return;
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    try {
        const response = await fetch(`${API_URL}/branches/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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

    tbody.empty().append(`<tr><td colspan="5" class="text-center p-3"><span class="spinner-border spinner-border-sm text-primary"></span> Cargando catálogo...</td></tr>`);

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/exams`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
                    id: ex.id, code: ex.fonasa_code, price: ex.price, subs: ex.sub_exams || []
                };
            });

            let totalExamenes = 0;
            Object.keys(examTypes).forEach(grupo => {
                const examenes = examTypes[grupo].exams || {};
                Object.keys(examenes).forEach(nombreExamen => {
                    const exData = examenes[nombreExamen];
                    if (searchStr && !nombreExamen.toLowerCase().includes(searchStr) && !(exData.code || '').toLowerCase().includes(searchStr)) return;
                    totalExamenes++;

                    let badgeColor = 'bg-secondary';
                    if (grupo === 'RX') badgeColor = 'bg-primary';
                    if (grupo === 'CT') badgeColor = 'bg-info text-dark';
                    if (grupo === 'MRI') badgeColor = 'bg-danger';
                    if (grupo === 'ECO') badgeColor = 'bg-success';

                    tbody.append(`
                        <tr>
                            <td class="ps-4"><span class="badge ${badgeColor}">${grupo}</span></td>
                            <td class="fw-bold text-dark">${nombreExamen}
                                <small class="d-block text-muted" style="font-size: 0.75rem;">${exData.subs.join(", ")}</small>
                            </td>
                            <td class="font-monospace text-secondary">${exData.code || '--'}</td>
                            <td class="text-end fw-bold text-success">$${parseFloat(exData.price).toLocaleString('es-CL')}</td>
                            <td class="text-center pe-4">
                                <button class="btn btn-sm btn-outline-danger fw-bold" onclick="cargarExamen('${exData.id}')">
                                    <i class="bi bi-pencil-square"></i> Editar
                                </button>
                            </td>
                        </tr>
                    `);
                });
            });

            if (totalExamenes === 0) tbody.append(`<tr><td colspan="5" class="text-center text-muted p-4">No se encontraron prestaciones.</td></tr>`);
        }
    } catch (error) {
        tbody.empty().append(`<tr><td colspan="5" class="text-center text-danger p-4">Error de conexión.</td></tr>`);
    }
}

function nuevoExamen() {
    $("#formExamen")[0].reset();
    $("#catId").val("");
    $(".req-cat").removeClass("is-invalid");
    $("#btnEliminarExamen").hide();
    $("#modalExamen").modal('show');
}

function cargarExamen(id) {
    const ex = currentExamsFromDB.find(e => e.id === id);
    if (!ex) return;

    $("#catId").val(ex.id);
    $("#catGrupo").val(ex.group_code);
    $("#catNombre").val(ex.name);
    $("#catCodigo").val(ex.fonasa_code);
    $("#catPrecio").val(ex.price);
    $("#catSubs").val((ex.sub_exams || []).join(", "));

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
        sub_exams: subsArray
    };

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/exams`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
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
    if (!id || !confirm(`¿Eliminar permanentemente este examen del catálogo?`)) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/exams/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        if (response.ok) {
            $("#modalExamen").modal('hide');
            showToast("Examen eliminado del catálogo.", "warning");
            renderCatalogoAdmin();
        }
    } catch (error) { showToast("Error al eliminar", "danger"); }
}

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
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const res = await response.json();
        tbody.empty();

        if (res.success) {
            let granTotalHonorarios = 0;
            const medicosArray = res.data;

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
        }
    } catch (e) {
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

let currentReporteExamenesData = [];
let currentReporteDiasMes = 30;

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
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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

$(document).on('change', '#mesExamenes', renderReporteExamenes);


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

function exportarExcelHonorarios() {
    const mes = $("#mesHonorarios").val();
    descargarCSV(`Liquidacion_Honorarios_${mes}.csv`, 'tablaHonorariosAdmin');
    showToast("Descargando archivo Excel...", "success");
}

function exportarExcelExamenes() {
    if (!currentReporteExamenesData || currentReporteExamenesData.length === 0) {
        return showToast("No hay datos para exportar en este mes.", "warning");
    }

    const mes = $("#mesExamenes").val();
    let csv = '\uFEFF';

    let headers = ['Nombre del Examen', 'Sala / Modalidad', 'Total del Mes'];
    for (let i = 1; i <= currentReporteDiasMes; i++) {
        headers.push(`Día ${i}`);
    }
    csv += headers.join(';') + '\n';

    currentReporteExamenesData.forEach(row => {
        let fila = [
            `"${row.examen}"`,
            `"${row.sala}"`,
            row.total
        ];

        for (let i = 1; i <= currentReporteDiasMes; i++) {
            fila.push(row.dias[i]);
        }

        csv += fila.join(';') + '\n';
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", `Produccion_Diaria_${mes}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    showToast("Generando desglose diario...", "success");
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
                'X-Lab-Id': labId
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

    if (roles.includes('radiologo')) {
        $("#uAeTitle").attr("placeholder", "OBLIGATORIO PARA RADIÓLOGOS");
    } else {
        $("#uAeTitle").attr("placeholder", "Opcional (Ej: RADIOLOGO_01)");
    }
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
                'X-Lab-Id': labId
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
                        <td class="ps-4 text-muted fw-bold">#${plan.id}</td>
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
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
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
    if (!id || !confirm("¿Está seguro de eliminar este plan?")) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/plans/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
                <td class="ps-4 text-muted fw-bold">#${prev.id}</td>
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
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
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
    if (!id || !confirm("¿Está seguro de eliminar esta previsión? Se eliminarán los planes asociados a ella.")) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/insurances/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
    $("#formSala")[0].reset();
    $("#salaId").val("");

    $(".req-sala").removeClass("is-invalid");
    $("#btnEliminarSala").hide();
    $("#modalSala").modal('show');
}

async function eliminarSala() {
    const id = $("#salaId").val();

    if (!id || !confirm("⚠️ ¿Está seguro de eliminar esta Sala/Equipo? Esto podría afectar la agenda histórica.")) {
        return;
    }

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/machines/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
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

    csv += "RX;Radiografía de Tórax AP y Lateral;0401001;15000\n";
    csv += "CT;Tomografía Computarizada de Cerebro sin contraste;0402005;85000\n";
    csv += "MRI;Resonancia Magnética de Columna Lumbar;0403010;150000\n";
    csv += "ECO;Ecografía Abdominal Completa;0404002;35000\n";

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", "Plantilla_Carga_Examenes.csv");
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    showToast("Descargando plantilla de ejemplo...", "success");
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
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
                let badgeColor = 'bg-secondary';
                if (tpl.group_code === 'RX') badgeColor = 'bg-primary';
                if (tpl.group_code === 'CT') badgeColor = 'bg-info text-dark';
                if (tpl.group_code === 'MRI') badgeColor = 'bg-danger';
                if (tpl.group_code === 'ECO') badgeColor = 'bg-success';

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

function cargarPlantilla(id) {
    const tpl = currentPlantillasFromDB.find(t => t.id === id);
    if (!tpl) return;

    $("#tplId").val(tpl.id);
    $("#tplGrupo").val(tpl.group_code);
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
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
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
    if (!id || !confirm("¿Está seguro de eliminar esta plantilla?")) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/templates/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
                    <button class="btn btn-sm btn-outline-primary" onclick="abrirModalPaciente('${p.id}')"><i class="bi bi-pencil"></i> Editar</button>
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
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
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
    if (!id || !confirm("¿Está absolutamente seguro de eliminar este paciente y todo su historial? Esta acción es irreversible.")) return;

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/patients/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
    if (currentSucursalesAdmin.length === 0) {
        container.html('<span class="text-danger small">No hay sucursales disponibles.</span>');
        return;
    }

    currentSucursalesAdmin.forEach(suc => {
        container.append(`
            <div class="form-check form-switch">
                <input class="form-check-input chk-lab" type="checkbox" value="${suc.id}" id="chkLab_${suc.id}">
                <label class="form-check-label small fw-bold text-dark" for="chkLab_${suc.id}">${suc.name}</label>
            </div>
        `);
    });
}

async function pingDicom(id) {
    const sala = currentMachinesFromDB.find(s => String(s.id) === String(id));
    if (!sala) return;

    if (!sala.ip_address || !sala.port) {
        return showToast("Debe configurar la IP y el Puerto editando la sala primero.", "warning");
    }

    if (typeof showToast === 'function') showToast(`Testeando conexión con ${sala.ae_title || sala.name}...`, "info");

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/machines/${id}/ping`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });

        const data = await response.json();

        if (response.ok && data.success) {
            alert(`✅ CONEXIÓN ESTABLECIDA\n\n${data.message}`);
        } else {
            alert(`❌ ERROR DE RED\n\n${data.message}`);
        }
    } catch (e) {
        alert("❌ Error crítico: No se pudo contactar al servidor RIS.");
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