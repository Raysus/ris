/* =========================================
   MÓDULO DASHBOARD (dashboard.js)
   ========================================= */

let chartEstadosInstance = null;
let chartModalidadesInstance = null;

function initDashboard() {
    const opcionesFecha = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    $("#dashFechaActual").text(new Date().toLocaleDateString('es-CL', opcionesFecha).toUpperCase());

    actualizarMetricas();
}

async function actualizarMetricas() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id') || '';

    let fechaSel = $("#filtroFechaDash").length ? $("#filtroFechaDash").val() : new Date().toISOString().split('T')[0];

    try {
        const response = await fetch(`${API_URL}/dashboard/metrics?date=${fechaSel}`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const res = await response.json();

        if (res.success) {
            const d = res.data;

            $("#kpiPacientes").text(d.kpis.pacientes);
            $("#kpiExamenes").text(d.kpis.examenes);
            const pendientes = (d.charts.flujo['Radiólogo'] || 0) + (d.charts.flujo['Secretaria'] || 0);
            $("#kpiInformes").text(pendientes);
            $("#kpiIngresos").text(`$${d.kpis.ingresos.toLocaleString('es-CL')}`);

            dibujarGraficoEstados(d.charts.flujo);
            dibujarGraficoModalidades(d.charts.modalidades);
        }
    } catch (e) {
        console.error("Error en dashboard:", e);
    }
}

function dibujarGraficoEstados(datos) {
    const ctx = document.getElementById('chartEstados');
    if (!ctx) return;
    if (chartEstadosInstance) chartEstadosInstance.destroy();

    chartEstadosInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: Object.keys(datos),
            datasets: [{
                data: Object.values(datos),
                backgroundColor: ['#f59e0b', '#0dcaf0', '#dc3545', '#6f42c1', '#198754'],
                borderWidth: 2, borderColor: '#ffffff'
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'right' } } }
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
                label: 'Exámenes',
                data: valoresActivos,
                backgroundColor: '#0d6efd',
                borderRadius: 4, barPercentage: 0.6
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
}