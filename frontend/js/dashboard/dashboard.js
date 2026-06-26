/* =========================================
   MÓDULO DASHBOARD (dashboard.js)
   ========================================= */

let chartEstadosInstance = null;
let chartModalidadesInstance = null;

function initDashboard() {
    const hoy = new Date().toISOString().split("T")[0];
    if ($("#filtroFechaDash").length && !$("#filtroFechaDash").val()) {
        $("#filtroFechaDash").val(hoy);
    }

    const opcionesFecha = { weekday: "long", year: "numeric", month: "long", day: "numeric" };
    $("#dashFechaActual").text(new Date().toLocaleDateString("es-CL", opcionesFecha));

    actualizarMetricas();

    if (window._dashInterval) clearInterval(window._dashInterval);
    window._dashInterval = setInterval(actualizarMetricas, 120000);
}

async function actualizarMetricas() {
    const fechaSel = $("#filtroFechaDash").length
        ? $("#filtroFechaDash").val()
        : new Date().toISOString().split("T")[0];

    $("#dashError").addClass("d-none");

    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId(false)) {
        $("#kpiPacientes, #kpiExamenes, #kpiIngresos, #kpiTat").text("—");
        return;
    }

    const headers = typeof risBuildAuthHeaders === 'function'
        ? risBuildAuthHeaders()
        : { Authorization: `Bearer ${localStorage.getItem('ris_token')}`, Accept: 'application/json' };

    try {
        const response = await fetch(`${API_URL}/dashboard/metrics?date=${fechaSel}`, { headers });
        const res = await response.json();

        if (!response.ok || !res.success) {
            throw new Error(res.message || "No se pudieron cargar las métricas.");
        }

        const d = res.data;
        const kpis = d.kpis || {};
        const flujo = d.charts?.flujo || {};

        $("#kpiPacientes").text(kpis.pacientes ?? 0);
        $("#kpiExamenes").text(kpis.examenes ?? 0);
        $("#kpiIngresos").text("$" + Number(kpis.ingresos ?? 0).toLocaleString("es-CL"));
        $("#kpiTat").text(kpis.tat_promedio ?? 0);

        const informesPendientes = (flujo["Radiólogo"] || 0) + (flujo["Secretaría"] || 0);
        $("#kpiInformes").text(informesPendientes).removeClass("text-danger animate__pulse");

        const tendencia = kpis.tendencia ?? 0;
        const $tend = $("#kpiTendencia");
        if (tendencia > 0) {
            $tend.html(`<i class="bi bi-arrow-up-short text-success"></i> +${tendencia}% vs ayer`).removeClass("text-danger").addClass("text-success");
        } else if (tendencia < 0) {
            $tend.html(`<i class="bi bi-arrow-down-short text-danger"></i> ${tendencia}% vs ayer`).removeClass("text-success").addClass("text-danger");
        } else {
            $tend.text("Sin variación vs ayer").removeClass("text-success text-danger");
        }

        renderAlertasOperativas(d.alerts || [], flujo);

        dibujarGraficoEstados(flujo);
        dibujarGraficoModalidades(d.charts?.modalidades || {});
    } catch (e) {
        console.error("Error en dashboard:", e);
        $("#dashError").removeClass("d-none").text(e.message || "Error al cargar el dashboard.");
        showToast("No se pudieron cargar las métricas del dashboard.", "danger");
    }
}

function renderAlertasOperativas(alerts, flujo) {
    const $container = $("#containerAlertasOperativas");
    const $list = $("#listaAlertasOperativas");

    if (!alerts.length) {
        $container.addClass("d-none");
        $("#kpiInformes").removeClass("text-danger");
        return;
    }

    $container.removeClass("d-none");
    $list.empty();

    const levelClass = { danger: "alert-danger", warning: "alert-warning", info: "alert-info" };
    const levelIcon = { danger: "exclamation-octagon-fill", warning: "exclamation-triangle-fill", info: "info-circle-fill" };

    alerts.forEach((a) => {
        const cls = levelClass[a.level] || "alert-secondary";
        const icon = levelIcon[a.level] || "bell-fill";
        $list.append(`
            <div class="alert ${cls} d-flex align-items-start shadow-sm mb-2" role="alert">
                <i class="bi bi-${icon} fs-5 me-2 mt-1"></i>
                <div>
                    <strong>${typeof risEscapeHtml === 'function' ? risEscapeHtml(a.title) : a.title}</strong><br>
                    <span class="small">${typeof risEscapeHtml === 'function' ? risEscapeHtml(a.message) : a.message}</span>
                </div>
            </div>
        `);
    });

    if ((flujo["Radiólogo"] || 0) + (flujo["Secretaría"] || 0) > 15) {
        $("#kpiInformes").addClass("text-danger");
    } else {
        $("#kpiInformes").removeClass("text-danger");
    }
}

function dibujarGraficoEstados(datos) {
    const ctx = document.getElementById("chartEstados");
    if (!ctx) return;
    if (chartEstadosInstance) chartEstadosInstance.destroy();

    chartEstadosInstance = new Chart(ctx, {
        type: "doughnut",
        data: {
            labels: Object.keys(datos),
            datasets: [{
                data: Object.values(datos),
                backgroundColor: ["#b8860b", "#2d5080", "#c41e3a", "#9a6fa8", "#7d2181"],
                borderWidth: 2,
                borderColor: "#ffffff",
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: "65%",
            plugins: {
                legend: { position: "right" },
                title: { display: false },
            },
        },
    });
}

function dibujarGraficoModalidades(datos) {
    const ctx = document.getElementById("chartModalidades");
    if (!ctx) return;
    if (chartModalidadesInstance) chartModalidadesInstance.destroy();

    const gruposActivos = Object.keys(datos).filter((key) => datos[key] > 0);
    const valoresActivos = gruposActivos.map((key) => datos[key]);

    chartModalidadesInstance = new Chart(ctx, {
        type: "bar",
        data: {
            labels: gruposActivos.length ? gruposActivos : ["Sin datos"],
            datasets: [{
                label: "Exámenes",
                data: valoresActivos.length ? valoresActivos : [0],
                backgroundColor: ["#7d2181", "#9a6fa8", "#2d5080", "#ba0c7f", "#b8860b"],
                borderRadius: 4,
                barPercentage: 0.6,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
        },
    });
}
