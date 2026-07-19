/* Worklist DICOM (centros clínicos con MWL) */
function initWorklist() {
    return initAtencionTecnicaModule({
        pageId: 'worklist',
        forceWorklistOnly: true,
        listTitle: 'Lista de trabajo',
        listSubtitle: 'Pacientes confirmados en agenda — envío DICOM a modalidades',
    });
}
