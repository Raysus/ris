/* =========================================
   ENRUTADOR PRINCIPAL (SPA) - router.js
   ========================================= */

function loadPage(page) {
    console.log("Cargando página:", page);

    sincronizarSidebar(page);

    if (typeof showLoader === 'function') showLoader();

    $("#appContent").load("pages/" + page + ".html", function (response, status, xhr) {
        if (typeof hideLoader === 'function') hideLoader();

        localStorage.setItem("ris_last_page", page);

        if (status === "error") {
            $("#appContent").html("<div class='alert alert-danger fw-bold m-4'><i class='bi bi-exclamation-triangle me-2'></i>Error 404: No se pudo cargar el módulo '" + page + ".html'</div>");
            return;
        }

        // ==========================================
        // MOTOR DE INICIALIZACIÓN DE MÓDULOS
        // ==========================================

        // Recepción y Tecnólogos
        if (page === "agenda" && typeof initAgenda === "function") initAgenda();
        if (page === "worklist" && typeof initWorklist === "function") initWorklist();

        // Flujo Médico
        if (page === "radiologist" && typeof initRadiologist === "function") initRadiologist();
        if (page === "transcription" && typeof initTranscription === "function") initTranscription();
        if (page === "validation" && typeof initValidation === "function") initValidation();

        // Módulos Finales y Configuración
        if (page === "entrega" && typeof initEntrega === "function") initEntrega();
        if (page === "admin" && typeof initAdmin === "function") initAdmin();

        if (page === "dashboard" && typeof initDashboard === "function") initDashboard();
    });
}

// ==========================================
// CONTROLADOR DEL MENÚ LATERAL (SIDEBAR)
// ==========================================
function sincronizarSidebar(paginaActiva) {
    const enlaces = document.querySelectorAll('#sidebar nav a');
    enlaces.forEach(enlace => {
        enlace.classList.remove('active');
    });
    const enlaceSeleccionado = document.querySelector(`#sidebar nav a[data-page="${paginaActiva}"]`);

    if (enlaceSeleccionado) {
        enlaceSeleccionado.classList.add('active');
    }
}