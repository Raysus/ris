/* =========================================
   MÓDULO DASHBOARD (dashboard.js)
   ========================================= */

let chartEstadosInstance = null;
let chartModalidadesInstance = null;

function initDashboard() {
    loadRISState();

    const opcionesFecha = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    $("#dashFechaActual").text(new Date().toLocaleDateString('es-CL', opcionesFecha).toUpperCase());

    actualizarMetricas();
    setupDashboardSync();
}

function setupDashboardSync() {
    window.addEventListener('storage', (e) => {
        if (e.key === 'ris_app_data') {
            loadRISState();
            if ($("#chartEstados").length) actualizarMetricas();
        }
    });

    window.addEventListener('ris_updated', () => {
        if ($("#chartEstados").length) actualizarMetricas();
    });
}

function actualizarMetricas() {
    const hoyStr = new Date().toLocaleDateString('es-CL');

    const agendaHoy = (window.RIS.agenda || []).filter(a => new Date(a.start).toLocaleDateString('es-CL') === hoyStr);
    const worklistGlobal = window.RIS.worklist || [];

    const rutsUnicos = new Set(agendaHoy.map(a => a.patient.rut));
    $("#kpiPacientes").text(rutsUnicos.size);

    let examenesRealizados = 0;
    worklistGlobal.forEach(w => {
        if (['en_informe', 'en_transcripcion', 'para_firma', 'entregable', 'entregado'].includes(w.status)) {
            w.studies.forEach(s => examenesRealizados += (s.qty || 1));
        }
    });
    $("#kpiExamenes").text(examenesRealizados);

    const pendientes = worklistGlobal.filter(w => ['en_informe', 'en_transcripcion', 'para_firma'].includes(w.status)).length;
    $("#kpiInformes").text(pendientes);

    let ingresosTotales = 0;
    agendaHoy.forEach(a => {
        if (a.status !== 'anulado') {
            a.studies.forEach(s => ingresosTotales += (s.price * (s.qty || 1)));
            if (a.insumos) a.insumos.forEach(ins => ingresosTotales += ins.price);
        }
    });
    $("#kpiIngresos").text(`$${ingresosTotales.toLocaleString('es-CL')}`);

    const conteoEstados = {
        'Espera/Agendado': agendaHoy.filter(a => ['agendado', 'confirmado', 'espera'].includes(a.status)).length,
        'En Equipo': worklistGlobal.filter(w => w.status === 'dicom_enviado').length,
        'Radiólogo': worklistGlobal.filter(w => w.status === 'en_informe' || w.status === 'para_firma').length,
        'Secretaria': worklistGlobal.filter(w => w.status === 'en_transcripcion').length,
        'Listos/Entregados': worklistGlobal.filter(w => w.status === 'entregable' || w.status === 'entregado').length
    };

    dibujarGraficoEstados(conteoEstados);


    const produccionModalidad = {};
    (window.RIS.resources || []).forEach(res => produccionModalidad[res.group] = 0);

    agendaHoy.forEach(a => {
        if (a.status !== 'anulado') {
            const sala = window.RIS.resources.find(r => r.id === a.machine);
            if (sala) {
                a.studies.forEach(s => {
                    produccionModalidad[sala.group] += (s.qty || 1);
                });
            }
        }
    });

    dibujarGraficoModalidades(produccionModalidad);
}

function dibujarGraficoEstados(datos) {
    const ctx = document.getElementById('chartEstados');
    if (!ctx) return;

    if (chartEstadosInstance) chartEstadosInstance.destroy();

    const labels = Object.keys(datos);
    const data = Object.values(datos);

    chartEstadosInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: ['#f59e0b', '#0dcaf0', '#dc3545', '#6f42c1', '#198754'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8 } }
            },
            cutout: '65%'
        }
    });
}

function dibujarGraficoModalidades(datos) {
    const ctx = document.getElementById('chartModalidades');
    if (!ctx) return;

    if (chartModalidadesInstance) chartModalidadesInstance.destroy();

    const gruposActivos = Object.keys(datos).filter(key => datos[key] > 0);
    const valoresActivos = gruposActivos.map(key => datos[key]);

    chartModalidadesInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: gruposActivos,
            datasets: [{
                label: 'Exámenes Agendados/Realizados',
                data: valoresActivos,
                backgroundColor: '#0d6efd',
                borderRadius: 4,
                barPercentage: 0.6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } },
                x: { grid: { display: false } }
            }
        }
    });
}