$(document).ready(function () {
    const lastPage = localStorage.getItem("ris_last_page") || "agenda";
    if (typeof loadPage === "function") {
        loadPage(lastPage);
    }

    $(document).on('click', '.sidebar nav a', function (e) {
        e.preventDefault();

        const page = $(this).data('page');

        if (page) {
            $('.sidebar nav a').removeClass('active');
            $(this).addClass('active');

            if (typeof loadPage === "function") {
                loadPage(page);
            } else {
                console.error("Error: loadPage no está definida en router.js");
            }
        }
    });

    $('#toggleSidebar').click(function () {
        $('#sidebar').toggleClass('collapsed');
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

    const sesionActual = localStorage.getItem("ris_current_user");

    if (!sesionActual) {
        window.location.href = "index.html";
        return;
    } else {
        const usuario = JSON.parse(sesionActual);

        const appData = JSON.parse(localStorage.getItem("ris_app_data")) || {};
        const personasBD = appData.personas || [];
        const persona = personasBD.find(p => p.rut === usuario.rut) || {};

        const nombreMostrado = `${usuario.titulo || ''} ${persona.nombres || 'Usuario'} ${persona.apellidoPaterno || ''}`;

        $("#userNameDisplay").text(nombreMostrado.trim());
        $("#userRoleDisplay").text((usuario.roles || []).join(", ").toUpperCase());

        // ========================================================
        // MOTOR DE PERMISOS PARA EL MENÚ LATERAL (RBAC)
        // ========================================================
        const permisosModulos = {
            "dashboard": ["admin", "recepcion", "tecnologo", "radiologo", "transcriptor"],
            "agenda": ["admin", "recepcion"],
            "worklist": ["admin", "tecnologo"],
            "radiologist": ["admin", "radiologo"],
            "transcription": ["admin", "transcriptor"],
            "validation": ["admin", "radiologo"],
            "entrega": ["admin", "recepcion"],
            "admin": ["admin"]
        };

        $(".sidebar nav a").each(function () {
            const page = $(this).attr("data-page");

            if (page && permisosModulos[page]) {
                const tienePermiso = usuario.roles.some(rol => permisosModulos[page].includes(rol));

                if (!tienePermiso) {
                    $(this).addClass("d-none");
                } else {
                    $(this).removeClass("d-none");
                }
            }
        });
    }

    $("#btnLogout").click(function () {
        if (confirm("¿Está seguro que desea cerrar su sesión?")) {
            localStorage.removeItem("ris_current_user");
            window.location.href = "index.html";
        }
    });

    document.addEventListener("DOMContentLoaded", () => {
        sincronizarSidebar();
    });

    window.addEventListener("hashchange", () => {
        sincronizarSidebar();
    });
});