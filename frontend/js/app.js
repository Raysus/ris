const LOCAL_BRIDGE_URL = 'http://127.0.0.1:8181';
const urlMiddleware = "https://pacs.healthticloud.cl/dicom-web/studies";

$(document).ready(async function () {

    const token = localStorage.getItem("ris_token");

    if (!token) {
        window.location.href = "index.html";
        return;
    }

    const profileName = localStorage.getItem("ris_user_profile") || "Invitado";
    let permissions = {};
    let userData = {};

    try {
        permissions = JSON.parse(localStorage.getItem("ris_permissions")) || {};
        userData = JSON.parse(localStorage.getItem("ris_user_data")) || {};
    } catch (e) {
        console.error("Error parseando datos de sesión", e);
    }

    const persona = userData.persona || {};
    const nombreMostrado = `${persona.names || userData.names || 'Usuario'} ${persona.last_name_1 || userData.last_name_1 || ''}`;
    $("#userNameDisplay").text(nombreMostrado.trim());
    $("#userRoleDisplay").text(profileName.toUpperCase());

    risApplyLaptopLayout();

    const userRoles = Array.isArray(userData.settings?.roles) ? userData.settings.roles : [];
    const esSysAdmin = typeof risIsSysAdmin === 'function'
        ? risIsSysAdmin()
        : (profileName === 'sis_admin' || localStorage.getItem('ris_all_labs') === 'true');
    const esClinicAdmin = typeof risIsClinicAdmin === 'function'
        ? risIsClinicAdmin()
        : (profileName === 'admin' || esSysAdmin);

    const permisosModulos = {
        "dashboard": ["admin", "sis_admin", "contador"],
        "agenda": ["admin", "recepcion", "secretaria", "secretario", "tens", "sis_admin"],
        "worklist": ["admin", "tecnologo", "tens", "sis_admin", "radiologo"],
        "atencion": ["admin", "tecnologo", "tens", "sis_admin", "radiologo"],
        "radiologist": ["admin", "radiologo", "sis_admin"],
        "transcription": ["admin", "transcriptor", "sis_admin"],
        "validation": ["admin", "radiologo", "sis_admin"],
        "entrega": ["admin", "recepcion", "secretaria", "secretario", "sis_admin"],
        "admin": ["admin", "sis_admin", "contador"],
        "support": [
            "admin", "sis_admin", "recepcion", "secretaria", "secretario",
            "tecnologo", "tens", "radiologo", "transcriptor", "contador"
        ]
    };

    $(".sidebar nav a").each(function () {
        const page = $(this).attr("data-page");
        if (!page || !permisosModulos[page]) return;

        // Soporte: visible para todos los roles autenticados
        if (page === "support") {
            $(this).removeClass("d-none");
            return;
        }

        const tieneRolPermitido = userRoles.some(rol => permisosModulos[page].includes(rol));
        const puedeVer = esClinicAdmin || tieneRolPermitido;

        $(this).toggleClass("d-none", !puedeVer);
    });

    await cargarSelectorLaboratorios();

    if (typeof refreshLabProfileFromApi === 'function') {
        await refreshLabProfileFromApi();
    } else if (typeof applyLabProfileUI === 'function') {
        applyLabProfileUI();
    }
    if (typeof applyOperationalModuleNav === 'function') {
        applyOperationalModuleNav();
    }

    const esTecnologo = userRoles.includes('tecnologo') && !esClinicAdmin;
    const esContador = (userRoles.includes('contador') || profileName === 'contador') && !esClinicAdmin;
    const paginaOperativaTm = typeof getOperationalTechnicianPage === 'function'
        ? getOperationalTechnicianPage()
        : 'worklist';
    const defaultPage = esTecnologo
        ? paginaOperativaTm
        : (esContador ? 'dashboard' : (esSysAdmin ? 'admin' : 'agenda'));
    const lastPage = risResolveVisiblePage(localStorage.getItem("ris_last_page") || defaultPage);
    if (typeof loadPage === "function") {
        loadPage(lastPage);
        $(`.sidebar nav a[data-page="${lastPage}"]`).addClass('active');
    }

    $(document).on('click', '.sidebar nav a, .sidebar .brand-link', function (e) {
        e.preventDefault();
        const page = $(this).data('page');

        if (page) {
            $('.sidebar nav a').removeClass('active');
            $(`.sidebar nav a[data-page="${page}"]`).addClass('active');

            if (typeof loadPage === "function") {
                loadPage(page);
            } else {
                console.error("Error: loadPage no está definida en router.js");
            }
        }
    });

    $('#toggleSidebar').click(function () {
        if (window.innerWidth <= 768) return;
        $('#sidebar').toggleClass('collapsed');
        const isCollapsed = $('#sidebar').hasClass('collapsed');
        localStorage.setItem('ris_sidebar_collapsed', isCollapsed ? 'true' : 'false');
        if (typeof risSyncSidebarAria === 'function') risSyncSidebarAria();
    });

    $('#darkMode').click(function () {
        const htmlElement = document.documentElement;
        const isDark = htmlElement.getAttribute('data-bs-theme') === 'dark';

        if (isDark) {
            htmlElement.setAttribute('data-bs-theme', 'light');
            localStorage.setItem("ris_dark", "false");
            $(this).find('i').removeClass('bi-sun text-warning').addClass('bi-moon-stars');
        } else {
            htmlElement.setAttribute('data-bs-theme', 'dark');
            localStorage.setItem("ris_dark", "true");
            $(this).find('i').removeClass('bi-moon-stars').addClass('bi-sun text-warning');
        }
    });

    if (localStorage.getItem("ris_dark") === "true") {
        document.documentElement.setAttribute('data-bs-theme', 'dark');
        $('#darkMode i').addClass('bi-sun text-warning').removeClass('bi-moon-stars');
    }

    $("#btnLogout").click(async function (e) {
        e.preventDefault();
        if (!(await showConfirm("¿Está seguro que desea cerrar su sesión?", { title: "Cerrar sesión", confirmText: "Salir" }))) return;
        try {
            await fetch(`${API_URL}/logout`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${token}`
                }
            });
        } catch (e) { console.warn("Error avisando al servidor", e); }

        localStorage.removeItem("ris_token");
        localStorage.removeItem("ris_lab_id");
        localStorage.removeItem("ris_user_profile");
        localStorage.removeItem("ris_permissions");
        localStorage.removeItem("ris_lab_name");
        localStorage.removeItem("ris_user_data");
        localStorage.removeItem("ris_lab_profile");
        localStorage.removeItem("ris_lab_type_code");
        localStorage.removeItem("ris_labs_permitidos");
        localStorage.removeItem("ris_all_labs");
        localStorage.removeItem("ris_last_page");
        window.location.href = "index.html";
    });

    if (typeof sincronizarSidebar === "function") {
        sincronizarSidebar();
        window.addEventListener("hashchange", sincronizarSidebar);
    }

    risEnsureCurrentPageVisible();
});

/** En 1366×768 y similares, sidebar colapsado por defecto para ganar ancho útil. */
function risApplyLaptopLayout() {
    const w = window.innerWidth;
    const collapsed = localStorage.getItem('ris_sidebar_collapsed');
    if (collapsed === null && w >= 992 && w <= 1400) {
        $('#sidebar').addClass('collapsed');
        localStorage.setItem('ris_sidebar_collapsed', 'true');
    } else if (collapsed === 'true') {
        $('#sidebar').addClass('collapsed');
    } else if (collapsed === 'false') {
        $('#sidebar').removeClass('collapsed');
    }
    if (typeof risSyncSidebarAria === 'function') risSyncSidebarAria();
}

$(window).on('resize', function () {
    clearTimeout(window._risResizeTimer);
    window._risResizeTimer = setTimeout(function () {
        if (typeof window.risAgendaCalendar !== 'undefined' && window.risAgendaCalendar?.updateSize) {
            window.risAgendaCalendar.updateSize();
        }
    }, 150);
});

function risResolveVisiblePage(preferred) {
    const link = document.querySelector(`#sidebar nav a[data-page="${preferred}"]`);
    if (link && !link.classList.contains('d-none')) {
        return preferred;
    }
    const order = ['dashboard', 'admin', 'agenda', 'worklist', 'atencion', 'radiologist', 'transcription', 'validation', 'entrega'];
    for (const page of order) {
        const el = document.querySelector(`#sidebar nav a[data-page="${page}"]`);
        if (el && !el.classList.contains('d-none')) {
            return page;
        }
    }
    return preferred;
}

function risEnsureCurrentPageVisible() {
    const active = document.querySelector('#sidebar nav a.active')?.getAttribute('data-page')
        || localStorage.getItem('ris_last_page');
    if (!active) return;
    const visible = risResolveVisiblePage(active);
    if (visible === active) return;
    document.querySelectorAll('#sidebar nav a').forEach((a) => a.classList.remove('active'));
    const link = document.querySelector(`#sidebar nav a[data-page="${visible}"]`);
    if (link) link.classList.add('active');
    if (typeof loadPage === 'function') {
        loadPage(visible);
    }
}

const RIS_PERFILES_MULTI_SEDE = ['admin', 'radiologo', 'tecnologo', 'recepcion', 'transcriptor', 'tens', 'contador'];

function risTieneVariasSedesAsignadas() {
    try {
        const permitidos = JSON.parse(localStorage.getItem('ris_labs_permitidos') || '[]');
        if (Array.isArray(permitidos) && permitidos.length > 1 && permitidos[0] !== '*') {
            return true;
        }
    } catch (e) { /* ignore */ }
    return false;
}

function risPuedeElegirTodasMisSucursales() {
    const perfil = localStorage.getItem('ris_user_profile');
    return RIS_PERFILES_MULTI_SEDE.includes(perfil) && risTieneVariasSedesAsignadas();
}

async function cargarSelectorLaboratorios() {
    const token = localStorage.getItem('ris_token');
    const currentLabId = localStorage.getItem('ris_lab_id') || '';
    const userData = JSON.parse(localStorage.getItem('ris_user_data') || '{}');

    try {
        const esSisAdmin = typeof risIsSysAdmin === 'function'
            ? risIsSysAdmin()
            : ((localStorage.getItem('ris_user_profile') === 'sis_admin')
                || localStorage.getItem('ris_all_labs') === 'true');
        const endpoint = esSisAdmin ? `${API_URL}/all-laboratories` : `${API_URL}/laboratories`;

        const response = await fetch(endpoint, {
            headers: {
                'Accept': 'application/json',
                'Authorization': `Bearer ${token}`
            }
        });

        const data = await response.json();

        if (response.ok && data.success) {
            const container = $("#navLabSelectorContainer");
            const selector = $("#navLabSelector");
            selector.empty();

            if (esSisAdmin) {
                selector.append('<option value="">Visión Global (Todo el Sistema)</option>');

                data.data.forEach(padre => {
                    selector.append(`<option value="${padre.id}" class="fw-bold">${padre.name}</option>`);
                    padre.children?.forEach(hijo => {
                        selector.append(`<option value="${hijo.id}">— ${hijo.name}</option>`);
                    });
                });
            } else {
                const labs = data.data;
                const opcionesSede = labs.reduce(
                    (n, m) => n + 1 + (m.children?.length || 0),
                    0
                );
                const tieneVariasSedes = opcionesSede > 1 || labs.length > 1;

                if (risPuedeElegirTodasMisSucursales() || (localStorage.getItem('ris_user_profile') === 'admin' && tieneVariasSedes)) {
                    selector.append('<option value="ALL">Todas mis sucursales</option>');
                }

                if (labs.length > 0) {
                    labs.forEach(matriz => {
                        selector.append(`<option value="${matriz.id}" class="fw-bold">${matriz.name}</option>`);
                        matriz.children?.forEach(sucursal => {
                            selector.append(`<option value="${sucursal.id}">— ${sucursal.name}</option>`);
                        });
                    });
                }
            }

            if (esSisAdmin) {
                if (currentLabId) {
                    selector.val(currentLabId);
                } else {
                    selector.val('');
                    localStorage.removeItem('ris_lab_id');
                }
            } else if (typeof risIsConcreteLabId === 'function' && risIsConcreteLabId(currentLabId)) {
                selector.val(currentLabId);
            } else {
                const primerVal = selector.find('option').filter(function () {
                    const v = $(this).val();
                    return typeof risIsConcreteLabId === 'function' ? risIsConcreteLabId(v) : (v && v !== 'ALL' && v !== '');
                }).first().val();
                if (primerVal) {
                    localStorage.setItem('ris_lab_id', primerVal);
                    selector.val(primerVal);
                }
            }

            container.removeClass('d-none');

            selector.off('change').on('change', function () {
                const val = $(this).val();
                if (val === '' || val === null) {
                    localStorage.removeItem('ris_lab_id');
                } else if (val === 'ALL') {
                    localStorage.setItem('ris_lab_id', 'ALL');
                } else {
                    localStorage.setItem('ris_lab_id', val);
                }

                location.reload();
            });
        }
    } catch (error) {
        console.error("Error cargando laboratorios:", error);
    }
}

async function llamarHardwareLocal(endpoint, datos = {}) {
    try {
        const response = await fetch(`${LOCAL_BRIDGE_URL}${endpoint}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(datos)
        });
        return await response.json();
    } catch (e) {
        console.warn("Bridge local no detectado en esta PC.");
        return { success: false, message: "Bridge no iniciado" };
    }
}