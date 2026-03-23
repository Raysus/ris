/* =========================================
   MÓDULO DE ADMINISTRACIÓN (admin.js)
   Pestañas: Usuarios, Insumos, Salas, Exámenes, Configuración
   ========================================= */

function initAdmin() {
    loadRISState();

    renderListaUsuariosAdmin();
    renderListaInsumosAdmin();
    renderListaSalasAdmin();
    renderCatalogoAdmin();
    cargarConfigCentro();

    const fechaActual = new Date();
    const mesActual = `${fechaActual.getFullYear()}-${String(fechaActual.getMonth() + 1).padStart(2, '0')}`;
    $("#mesHonorarios").val(mesActual);
    $("#mesExamenes").val(mesActual);

    renderReporteHonorarios();
    renderReporteExamenes();

    setupAdminEvents();
    setupAdminSync();
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
}

/* =========================================
   1. GESTIÓN DE USUARIOS
   ========================================= */
function renderListaUsuariosAdmin() {
    const tbody = $("#tablaUsuariosAdmin tbody");
    if (!tbody.length) return;
    tbody.empty();

    const searchStr = $("#searchUsuario").val().toLowerCase();
    const usuariosFiltrados = (window.RIS.users || []).filter(u => {
        const p = (window.RIS.personas || []).find(per => per.rut === u.rut) || {};
        const fullName = `${p.nombres || ''} ${p.apellidoPaterno || ''}`.toLowerCase();
        return fullName.includes(searchStr) || u.rut.toLowerCase().includes(searchStr) || u.username.toLowerCase().includes(searchStr);
    });

    if (usuariosFiltrados.length === 0) {
        tbody.append(`<tr><td colspan="5" class="text-center text-muted p-4">No se encontraron usuarios.</td></tr>`);
        return;
    }

    usuariosFiltrados.forEach(u => {
        const p = (window.RIS.personas || []).find(per => per.rut === u.rut) || {};
        const rolesBadges = (u.roles || []).map(r => {
            let bg = 'bg-secondary';
            if (r === 'admin') bg = 'bg-dark';
            if (r === 'radiologo') bg = 'bg-danger';
            if (r === 'tecnologo') bg = 'bg-info text-dark';
            if (r === 'recepcion') bg = 'bg-success';
            if (r === 'transcriptor') bg = 'bg-warning text-dark';
            return `<span class="badge ${bg} me-1 mb-1" style="text-transform:uppercase;">${r}</span>`;
        }).join('');

        tbody.append(`
            <tr>
                <td class="ps-4">
                    <div class="fw-bold text-dark">${u.titulo || ''} ${p.nombres || 'Sin Nombre'} ${p.apellidoPaterno || ''}</div>
                    <small class="text-muted"><i class="bi bi-person-badge me-1"></i>${u.username}</small>
                </td>
                <td class="fw-bold text-secondary">${u.rut}</td>
                <td>${rolesBadges}</td>
                <td class="small text-muted">
                    ${u.pacsAE ? `<div class="mb-1"><i class="bi bi-display me-1"></i>AE: ${u.pacsAE}</div>` : ''}
                    ${u.dragonProfile ? `<div><i class="bi bi-mic me-1"></i>Mic: ${u.dragonProfile}</div>` : ''}
                    ${!u.pacsAE && !u.dragonProfile ? '--' : ''}
                </td>
                <td class="text-center pe-4">
                    <button class="btn btn-sm btn-outline-primary fw-bold" onclick="cargarUsuario('${u.rut}')">
                        <i class="bi bi-pencil-square"></i> Editar
                    </button>
                </td>
            </tr>
        `);
    });
}

function nuevoUsuario() {
    $("#adminForm")[0].reset();
    $("#uRut").prop("disabled", false).removeClass("is-valid is-invalid");
    $(".req-user").removeClass("is-valid is-invalid");
    $(".role-check").prop("checked", false);
    $("#btnEliminarUsuario").hide();
    $("#modalUsuario").modal('show');
}

function cargarUsuario(rut) {
    const u = window.RIS.users.find(user => user.rut === rut);
    const p = (window.RIS.personas || []).find(per => per.rut === rut) || {};
    if (!u) return;

    $("#uRut").val(u.rut).prop("disabled", true).removeClass("is-invalid is-valid");
    $("#uNombres").val(p.nombres || "");
    $("#uPrimerApellido").val(p.apellidoPaterno || "");
    $("#uSegundoApellido").val(p.apellidoMaterno || "");

    $("#uTitulo").val(u.titulo || "");
    $("#uUsername").val(u.username || "");
    $("#uPassword").val(u.password || "");
    $("#uAeTitle").val(u.pacsAE || "");
    $("#uDragonProfile").val(u.dragonProfile || "");

    $(".role-check").prop("checked", false);
    (u.roles || []).forEach(rol => $(`.role-check[value="${rol}"]`).prop("checked", true));

    $(".req-user").removeClass("is-valid is-invalid");
    $("#btnEliminarUsuario").show();
    $("#modalUsuario").modal('show');
}

function guardarUsuario() {
    let hasError = false;
    $(".req-user").each(function () {
        if ($(this).val().trim() === "") { $(this).addClass("is-invalid"); hasError = true; }
        else { $(this).removeClass("is-invalid").addClass("is-valid"); }
    });

    const rolesSeleccionados = [];
    $(".role-check:checked").each(function () { rolesSeleccionados.push($(this).val()); });

    if (rolesSeleccionados.length === 0 || hasError) return showToast("⚠️ Faltan datos obligatorios o roles.", "danger");

    const rut = $("#uRut").val().toUpperCase();
    if (!validarRut(rut)) return showToast("❌ RUT inválido.", "danger");

    const personaData = { rut, nombres: $("#uNombres").val().trim(), apellidoPaterno: $("#uPrimerApellido").val().trim(), apellidoMaterno: $("#uSegundoApellido").val().trim() };
    if (!window.RIS.personas) window.RIS.personas = [];
    const pIdx = window.RIS.personas.findIndex(p => p.rut === rut);
    if (pIdx > -1) window.RIS.personas[pIdx] = { ...window.RIS.personas[pIdx], ...personaData };
    else window.RIS.personas.push(personaData);

    const userData = { rut, titulo: $("#uTitulo").val(), roles: rolesSeleccionados, username: $("#uUsername").val().trim(), password: $("#uPassword").val(), pacsAE: $("#uAeTitle").val().trim(), dragonProfile: $("#uDragonProfile").val().trim() };
    if (!window.RIS.users) window.RIS.users = [];
    const uIdx = window.RIS.users.findIndex(u => u.rut === rut);
    if (uIdx > -1) window.RIS.users[uIdx] = userData;
    else window.RIS.users.push(userData);

    saveRISState();
    renderListaUsuariosAdmin();
    $("#modalUsuario").modal('hide');
    showToast(`✅ Usuario guardado correctamente.`, "success");
}

function eliminarUsuario() {
    const rut = $("#uRut").val();
    if (confirm("¿Revocar acceso a este usuario? (La identidad en la BD se mantendrá).")) {
        window.RIS.users = window.RIS.users.filter(u => u.rut !== rut);
        saveRISState();
        renderListaUsuariosAdmin();
        $("#modalUsuario").modal('hide');
        showToast("Acceso revocado.", "warning");
    }
}

/* =========================================
   2. GESTIÓN DE INSUMOS E INVENTARIO
   ========================================= */
function renderListaInsumosAdmin() {
    const tbody = $("#tablaInsumosAdmin tbody");
    if (!tbody.length) return;
    tbody.empty();

    const searchStr = $("#searchInsumo").val().toLowerCase();
    const inventario = window.RIS.inventoryZero || {};
    let totalItems = 0;

    for (const categoria in inventario) {
        inventario[categoria].forEach(ins => {
            if (searchStr && !ins.nombre.toLowerCase().includes(searchStr)) return;
            totalItems++;

            const pct = (ins.stock / ins.total) * 100;
            let badgeClass = 'bg-success';
            let statusText = 'Stock Óptimo';

            if (pct <= 10) { badgeClass = 'bg-danger pulse-danger'; statusText = 'CRÍTICO'; }
            else if (pct <= 30) { badgeClass = 'bg-warning text-dark'; statusText = 'Stock Bajo'; }

            tbody.append(`
                <tr>
                    <td class="ps-4 fw-bold text-secondary">${categoria}</td>
                    <td class="fw-bold text-dark">${ins.nombre}</td>
                    <td class="text-center fs-5 fw-bold ${pct <= 10 ? 'text-danger' : 'text-primary'}">${ins.stock}</td>
                    <td class="text-center text-muted">${ins.total}</td>
                    <td class="text-center pe-4">
                        <span class="badge ${badgeClass} mb-2 d-block">${statusText}</span>
                        <button class="btn btn-sm btn-outline-dark fw-bold w-100" onclick="cargarInsumo('${categoria}', '${ins.id}')">
                            <i class="bi bi-arrow-repeat"></i> Reponer
                        </button>
                    </td>
                </tr>
            `);
        });
    }

    if (totalItems === 0) tbody.append(`<tr><td colspan="5" class="text-center text-muted p-4">No se encontraron insumos.</td></tr>`);
}

function nuevoInsumo() {
    $("#formInsumo")[0].reset();
    $("#insId").val("");
    $("#insCategoria").prop("disabled", false);
    $(".req-ins").removeClass("is-invalid");
    $("#btnEliminarInsumo").hide();
    $("#modalInsumo").modal('show');
}

function cargarInsumo(categoria, id) {
    const insumo = window.RIS.inventoryZero[categoria].find(i => i.id === id);
    if (!insumo) return;

    $("#insId").val(insumo.id);
    $("#insCategoria").val(categoria).prop("disabled", true);
    $("#insNombre").val(insumo.nombre);
    $("#insStock").val(insumo.stock);
    $("#insTotal").val(insumo.total);

    $(".req-ins").removeClass("is-invalid");
    $("#btnEliminarInsumo").show();
    $("#modalInsumo").modal('show');
}

function guardarInsumo() {
    let hasError = false;
    $(".req-ins").each(function () {
        if ($(this).val().trim() === "") { $(this).addClass("is-invalid"); hasError = true; }
        else { $(this).removeClass("is-invalid"); }
    });
    if (hasError) return showToast("⚠️ Faltan datos del insumo.", "danger");

    const categoria = $("#insCategoria").val();
    const id = $("#insId").val() || "ins_" + Date.now();
    const insumoData = { id: id, nombre: $("#insNombre").val().trim(), stock: parseInt($("#insStock").val()), total: parseInt($("#insTotal").val()) };

    if (insumoData.stock > insumoData.total) return showToast("⚠️ El stock actual no puede superar el máximo.", "warning");

    if (!window.RIS.inventoryZero[categoria]) window.RIS.inventoryZero[categoria] = [];

    const idx = window.RIS.inventoryZero[categoria].findIndex(i => i.id === id);
    if (idx > -1) window.RIS.inventoryZero[categoria][idx] = insumoData;
    else window.RIS.inventoryZero[categoria].push(insumoData);

    saveRISState();
    renderListaInsumosAdmin();
    $("#modalInsumo").modal('hide');
    showToast(`✅ Inventario actualizado.`, "success");
}

function eliminarInsumo() {
    const categoria = $("#insCategoria").val();
    const id = $("#insId").val();
    if (confirm("¿Eliminar este insumo definitivamente?")) {
        window.RIS.inventoryZero[categoria] = window.RIS.inventoryZero[categoria].filter(i => i.id !== id);
        saveRISState();
        renderListaInsumosAdmin();
        $("#modalInsumo").modal('hide');
        showToast("Insumo eliminado.", "warning");
    }
}

/* =========================================
   3. GESTIÓN DE SALAS Y EQUIPOS
   ========================================= */
function renderListaSalasAdmin() {
    const tbody = $("#tablaSalasAdmin tbody");
    if (!tbody.length) return;
    tbody.empty();

    const searchStr = $("#searchSala").val().toLowerCase();
    if (!window.RIS.resources) window.RIS.resources = [];

    const salasFiltradas = window.RIS.resources.filter(res => {
        return res.title.toLowerCase().includes(searchStr) || res.id.toLowerCase().includes(searchStr) || res.group.toLowerCase().includes(searchStr);
    });

    if (salasFiltradas.length === 0) return tbody.append(`<tr><td colspan="4" class="text-center text-muted p-4">No se encontraron salas o equipos.</td></tr>`);

    salasFiltradas.forEach(res => {
        let badgeColor = 'bg-secondary';
        if (res.group === 'RX') badgeColor = 'bg-primary';
        if (res.group === 'CT') badgeColor = 'bg-info text-dark';
        if (res.group === 'MRI') badgeColor = 'bg-danger';
        if (res.group === 'ECO') badgeColor = 'bg-success';

        tbody.append(`
            <tr>
                <td class="ps-4 fw-bold text-secondary">${res.id}</td>
                <td class="fw-bold text-dark"><i class="bi bi-display me-2 text-muted"></i>${res.title}</td>
                <td><span class="badge ${badgeColor} px-3 py-2">${res.group}</span></td>
                <td class="text-center pe-4">
                    <button class="btn btn-sm btn-outline-info fw-bold text-dark" onclick="cargarSala('${res.id}')">
                        <i class="bi bi-pencil-square"></i> Editar Configuración
                    </button>
                </td>
            </tr>
        `);
    });
}

function nuevaSala() {
    $("#formSala")[0].reset();
    $("#salaId").prop("disabled", false).removeClass("is-invalid");
    $(".req-sala").removeClass("is-invalid");
    $("#btnEliminarSala").hide();
    $("#modalSala").modal('show');
}

function cargarSala(id) {
    const sala = window.RIS.resources.find(r => r.id === id);
    if (!sala) return;

    $("#salaId").val(sala.id).prop("disabled", true);
    $("#salaNombre").val(sala.title);
    $("#salaGrupo").val(sala.group);

    $(".req-sala").removeClass("is-invalid");
    $("#btnEliminarSala").show();
    $("#modalSala").modal('show');
}

function guardarSala() {
    let hasError = false;
    $(".req-sala").each(function () {
        if ($(this).val().trim() === "") { $(this).addClass("is-invalid"); hasError = true; }
        else { $(this).removeClass("is-invalid"); }
    });
    if (hasError) return showToast("⚠️ Faltan datos obligatorios.", "danger");

    const idStr = $("#salaId").val().trim().replace(/\s+/g, '_').toLowerCase();
    const salaData = { id: idStr, title: $("#salaNombre").val().trim(), group: $("#salaGrupo").val() };

    if (!window.RIS.resources) window.RIS.resources = [];
    const idx = window.RIS.resources.findIndex(r => r.id === idStr);
    const isNew = !$("#salaId").prop("disabled");

    if (isNew && idx > -1) return showToast("⚠️ Ya existe una sala con este ID.", "warning");
    if (idx > -1) window.RIS.resources[idx] = salaData; else window.RIS.resources.push(salaData);

    saveRISState();
    renderListaSalasAdmin();
    $("#modalSala").modal('hide');
    showToast(`✅ Sala guardada correctamente.`, "success");
}

function eliminarSala() {
    const id = $("#salaId").val();
    const enUso = (window.RIS.agenda || []).some(a => a.machine === id && a.status !== 'anulado');
    if (enUso) return showToast("⚠️ No se puede eliminar. Esta sala tiene turnos activos en la Agenda.", "danger");

    if (confirm("¿Eliminar esta sala del sistema?")) {
        window.RIS.resources = window.RIS.resources.filter(r => r.id !== id);
        saveRISState();
        renderListaSalasAdmin();
        $("#modalSala").modal('hide');
        showToast("Sala eliminada.", "warning");
    }
}

/* =========================================
   4. CATÁLOGO DE EXÁMENES (NUEVA PESTAÑA)
   ========================================= */
function renderCatalogoAdmin() {
    const tbody = $("#tablaCatalogoAdmin tbody");
    if (!tbody.length) return;
    tbody.empty();

    const searchStr = $("#searchCat").val().toLowerCase();
    const examTypes = window.RIS.examTypes || {};
    let totalExamenes = 0;

    Object.keys(examTypes).forEach(grupo => {
        const examenes = examTypes[grupo].exams || {};
        Object.keys(examenes).forEach(nombreExamen => {
            const data = examenes[nombreExamen];
            if (searchStr && !nombreExamen.toLowerCase().includes(searchStr) && !data.code.toLowerCase().includes(searchStr)) return;
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
                        <small class="d-block text-muted" style="font-size: 0.75rem;">${(data.subs || []).join(", ")}</small>
                    </td>
                    <td class="font-monospace text-secondary">${data.code}</td>
                    <td class="text-end fw-bold text-success">$${(data.price || 0).toLocaleString('es-CL')}</td>
                    <td class="text-center pe-4">
                        <button class="btn btn-sm btn-outline-danger fw-bold" onclick="cargarExamen('${grupo}', '${nombreExamen}')">
                            <i class="bi bi-pencil-square"></i> Editar
                        </button>
                    </td>
                </tr>
            `);
        });
    });

    if (totalExamenes === 0) tbody.append(`<tr><td colspan="5" class="text-center text-muted p-4">No se encontraron prestaciones en el catálogo.</td></tr>`);
}

function nuevoExamen() {
    $("#formExamen")[0].reset();
    $("#catGrupoOriginal").val("");
    $("#catNombreOriginal").val("");
    $(".req-cat").removeClass("is-invalid");
    $("#btnEliminarExamen").hide();
    $("#modalExamen").modal('show');
}

function cargarExamen(grupo, nombreExamen) {
    const data = window.RIS.examTypes[grupo].exams[nombreExamen];
    if (!data) return;

    $("#catGrupoOriginal").val(grupo);
    $("#catNombreOriginal").val(nombreExamen);

    $("#catGrupo").val(grupo);
    $("#catNombre").val(nombreExamen);
    $("#catCodigo").val(data.code);
    $("#catPrecio").val(data.price || 0);
    $("#catSubs").val((data.subs || []).join(", "));

    $(".req-cat").removeClass("is-invalid");
    $("#btnEliminarExamen").show();
    $("#modalExamen").modal('show');
}

function guardarExamen() {
    let hasError = false;
    $(".req-cat").each(function () {
        if ($(this).val().trim() === "") { $(this).addClass("is-invalid"); hasError = true; }
        else { $(this).removeClass("is-invalid"); }
    });
    if (hasError) return showToast("⚠️ Complete los datos requeridos.", "danger");

    const grupoViejo = $("#catGrupoOriginal").val();
    const nombreViejo = $("#catNombreOriginal").val();

    const grupoNuevo = $("#catGrupo").val();
    const nombreNuevo = $("#catNombre").val().trim();
    const codigoNuevo = $("#catCodigo").val().trim();
    const precioNuevo = parseInt($("#catPrecio").val()) || 0;

    let subsArray = $("#catSubs").val().split(',').map(s => s.trim()).filter(s => s !== "");

    if (!window.RIS.examTypes[grupoNuevo]) window.RIS.examTypes[grupoNuevo] = { exams: {} };

    if (nombreViejo && (nombreViejo !== nombreNuevo || grupoViejo !== grupoNuevo)) {
        if (window.RIS.examTypes[grupoViejo] && window.RIS.examTypes[grupoViejo].exams[nombreViejo]) {
            delete window.RIS.examTypes[grupoViejo].exams[nombreViejo];
        }
    }

    window.RIS.examTypes[grupoNuevo].exams[nombreNuevo] = {
        subs: subsArray.length > 0 ? subsArray : [],
        code: codigoNuevo,
        price: precioNuevo
    };

    saveRISState();
    renderCatalogoAdmin();
    $("#modalExamen").modal('hide');
    showToast("✅ Arancel guardado con éxito.", "success");
}

function eliminarExamen() {
    const grupo = $("#catGrupoOriginal").val();
    const nombre = $("#catNombreOriginal").val();

    if (confirm(`¿Eliminar permanentemente el examen "${nombre}" del catálogo?`)) {
        if (window.RIS.examTypes[grupo] && window.RIS.examTypes[grupo].exams[nombre]) {
            delete window.RIS.examTypes[grupo].exams[nombre];
            saveRISState();
            renderCatalogoAdmin();
            $("#modalExamen").modal('hide');
            showToast("Examen eliminado del catálogo.", "warning");
        }
    }
}

/* =========================================
   5. CONFIGURACIÓN DEL CENTRO (NUEVA PESTAÑA)
   ========================================= */
function cargarConfigCentro() {
    const cfg = window.RIS.config || {};
    $("#cfgNombre").val(cfg.clinicName || "Centro de Diagnóstico RIS PRO");
    $("#cfgDireccion").val(cfg.clinicAddress || "Av. Las Araucarias 1020, Temuco, Chile");
    $("#cfgHoraInicio").val(cfg.horaInicio || "08:00");
    $("#cfgHoraFin").val(cfg.horaFin || "20:00");
    $("#cfgIntervalo").val(cfg.intervalo || "00:15:00");
}

function guardarConfigCentro() {
    if (!window.RIS.config) window.RIS.config = {};

    window.RIS.config.clinicName = $("#cfgNombre").val().trim() || "Clínica";
    window.RIS.config.clinicAddress = $("#cfgDireccion").val().trim() || "Dirección";
    window.RIS.config.horaInicio = $("#cfgHoraInicio").val() || "08:00";
    window.RIS.config.horaFin = $("#cfgHoraFin").val() || "20:00";
    window.RIS.config.intervalo = $("#cfgIntervalo").val() || "00:15:00";

    if (window.RIS.config.horaInicio.length === 5) window.RIS.config.horaInicio += ":00";
    if (window.RIS.config.horaFin.length === 5) window.RIS.config.horaFin += ":00";

    saveRISState();
    showToast("✅ Ajustes Generales y Operativos guardados con éxito.", "success");
}

/* =========================================
   6. REPORTES Y HONORARIOS MÉDICOS
   ========================================= */
function renderReporteHonorarios() {
    const tbody = $("#tablaHonorariosAdmin tbody");
    if (!tbody.length) return;
    tbody.empty();

    const mesSeleccionado = $("#mesHonorarios").val();
    const porcentajeComision = parseFloat($("#porcentajeComision").val()) / 100;
    if (!mesSeleccionado || isNaN(porcentajeComision)) return;

    const produccionMedicos = {};
    let granTotalHonorarios = 0;

    (window.RIS.worklist || []).forEach(item => {
        if (!item.firmado || !item.fechaFirma) return;

        const partesFecha = item.fechaFirma.split(/[/, -]/);
        let mesFirma, anioFirma;
        const anioAprox = partesFecha.find(p => p.length === 4);

        if (anioAprox) {
            anioFirma = anioAprox;
            const idxAnio = partesFecha.indexOf(anioAprox);
            mesFirma = partesFecha[idxAnio - 1].padStart(2, '0');
        } else return;

        const mesAnioItem = `${anioFirma}-${mesFirma}`;

        if (mesAnioItem === mesSeleccionado) {
            const radiologo = item.medicoFirmante || "Dr. Radiólogo Jefe";
            if (!produccionMedicos[radiologo]) produccionMedicos[radiologo] = { nombre: radiologo, informes: 0, examenes: 0, totalFacturado: 0 };

            produccionMedicos[radiologo].informes += 1;
            item.studies.forEach(estudio => {
                produccionMedicos[radiologo].examenes += (estudio.qty || 1);
                const precio = estudio.price || 35000;
                produccionMedicos[radiologo].totalFacturado += (precio * (estudio.qty || 1));
            });
        }
    });

    const medicosArray = Object.values(produccionMedicos).sort((a, b) => b.totalFacturado - a.totalFacturado);

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
                <td class="text-end text-muted">$${med.totalFacturado.toLocaleString('es-CL')}</td>
                <td class="text-end pe-4 fw-bold text-success fs-6">$${honorarios.toLocaleString('es-CL')}</td>
            </tr>
        `);
    });

    $("#totalHonorariosGlobal").text(`$${granTotalHonorarios.toLocaleString('es-CL')}`);
}

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

/* =========================================
   7. REPORTE DE EXÁMENES MENSUALES
   ========================================= */
function renderReporteExamenes() {
    const tbody = $("#tablaExamenesAdmin tbody");
    if (!tbody.length) return;
    tbody.empty();

    const mesSeleccionado = $("#mesExamenes").val();
    if (!mesSeleccionado) return;

    let totalExamenes = 0;

    (window.RIS.worklist || []).forEach(w => {
        const wFecha = w.start ? w.start.substring(0, 7) : "";
        if (wFecha === mesSeleccionado) {
            const rut = w.patient && w.patient.rut ? w.patient.rut : "Sin RUT";
            const personaBD = (window.RIS.personas || []).find(p => p.rut === rut);
            let nombrePaciente = w.patient ? w.patient.name : 'Desconocido';

            if (personaBD) nombrePaciente = `${personaBD.nombres} ${personaBD.apellidoPaterno}`;

            const pacienteDisplay = `${nombrePaciente} (${rut})`;
            const sala = w.machine || 'N/A';
            const radiologo = w.medicoFirmante || 'Pendiente de firma';
            const fechaDisplay = w.start ? w.start.split('T')[0] : 'Sin fecha';

            let estadoTraduccion = w.status;
            let badgeClass = "bg-secondary";
            if (w.status === "agendado") { estadoTraduccion = "Agendado"; badgeClass = "bg-light text-dark border"; }
            if (w.status === "en_sala") { estadoTraduccion = "En Atención"; badgeClass = "bg-warning text-dark"; }
            if (w.status === "dicom_enviado") { estadoTraduccion = "Imagen Tomada"; badgeClass = "bg-info text-dark"; }
            if (w.status === "entregable" || w.status === "firmado") { estadoTraduccion = "Finalizado / Firmado"; badgeClass = "bg-success"; }

            w.studies.forEach(est => {
                totalExamenes++;
                tbody.append(`
                    <tr>
                        <td class="text-muted">${fechaDisplay}</td>
                        <td class="fw-bold">${pacienteDisplay}</td>
                        <td>${est.exam || est.description || 'Sin descripción'}</td>
                        <td>${sala}</td>
                        <td><span class="badge ${badgeClass}">${estadoTraduccion}</span></td>
                        <td class="small">${radiologo}</td>
                    </tr>
                `);
            });
        }
    });

    if (totalExamenes === 0) tbody.append(`<tr><td colspan="6" class="text-center text-muted p-5"><i class="bi bi-folder-x fs-1 d-block mb-2"></i>No hay exámenes registrados en este mes.</td></tr>`);
    $("#totalExamenesGlobal").text(totalExamenes);
}

/* =========================================
   8. MOTOR DE EXPORTACIÓN A EXCEL (CSV)
   ========================================= */
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
    const mes = $("#mesExamenes").val();
    descargarCSV(`Examenes_Realizados_${mes}.csv`, 'tablaExamenesAdmin');
    showToast("Descargando archivo Excel...", "success");
}