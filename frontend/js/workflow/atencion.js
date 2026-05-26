/* Atención en salas (dental / sin MWL): cita en agenda → atención técnica → subida PACS */
function initAtencion() {
    return initAtencionTecnicaModule({
        pageId: 'atencion',
        forceManualUpload: true,
        listTitle: 'Atención en salas',
        listSubtitle: 'Pacientes confirmados en agenda — suba las imágenes del equipo (CBCT, intraoral, etc.)',
    });
}

/** Desde agenda u otros módulos */
function irAModuloAtencionTecnica() {
    const page = typeof getOperationalTechnicianPage === 'function'
        ? getOperationalTechnicianPage()
        : 'atencion';
    if (typeof loadPage === 'function') {
        loadPage(page);
        if (typeof sincronizarSidebar === 'function') sincronizarSidebar(page);
    }
}

window.irAModuloAtencionTecnica = irAModuloAtencionTecnica;
