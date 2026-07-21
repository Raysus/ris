<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksRisAuthorization;
use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\Machine;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    use ChecksRisAuthorization;

    private const DASHBOARD_ROLES = ['admin', 'sis_admin', 'contador'];

    private function assertDashboardAccess(Request $request): void
    {
        $this->assertAnyRole($request, self::DASHBOARD_ROLES);
    }

    /**
     * Obtiene una consulta base de citas filtrada por los laboratorios permitidos del usuario.
     */
    private function getSecureAppointmentQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Appointment::query();

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('laboratory_id', $allowedLabs);
            }
        }
        return $query;
    }

    /**
     * Aplica el filtro de laboratorios permitidos a una consulta de Query Builder (DB::table).
     */
    private function applySecureLabFilterToDBQuery($queryBuilder)
    {
        $allowedLabs = config('app.allowed_lab_ids');
        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
            if (empty($allowedLabs)) {
                $queryBuilder->whereRaw('1 = 0');
            } else {
                $queryBuilder->whereIn('appointments.laboratory_id', $allowedLabs);
            }
        }
        return $queryBuilder;
    }

    /**
     * Calcula el monto estimado de una cita (estudios + insumos), igual que nómina/reportes.
     */
    private function calculateAppointmentRevenue(Appointment $appointment): float
    {
        $total = (float) $appointment->studies->sum('price');

        foreach ($appointment->supplies as $supply) {
            $total += (float) ($supply->pivot->price_charged ?? 0);
        }

        return $total;
    }

    /**
     * Ingresos efectivamente cobrados en caja para citas de una fecha.
     */
    private function sumPaymentsForDate(Carbon $date): float
    {
        $allowedLabs = config('app.allowed_lab_ids');

        $query = Payment::query()
            ->whereHas('appointment', function ($q) use ($date, $allowedLabs) {
                $q->whereDate('start_time', $date);
                if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
                    if (empty($allowedLabs)) {
                        $q->whereRaw('1 = 0');
                    } else {
                        $q->whereIn('laboratory_id', $allowedLabs);
                    }
                }
            });

        return (float) $query->sum('amount');
    }

    /**
     * Genera todas las métricas para el Dashboard.
     */
    public function getMetrics(Request $request)
    {
        $this->assertDashboardAccess($request);        // Determinamos la fecha a consultar (hoy por defecto)
        $fechaFiltro = $request->query('date') ? Carbon::parse($request->query('date')) : Carbon::today();
        $ayer = (clone $fechaFiltro)->subDay();

        // 1. OBTENCIÓN DE DATOS BASE
        $query = $this->getSecureAppointmentQuery()
            ->with(['studies', 'supplies', 'machine'])
            ->whereDate('start_time', $fechaFiltro);

        $appointments = $query->get();

        // 2. CÁLCULO DE KPIs BÁSICOS
        $totalPacientes = $appointments->unique('patient_id')->count();

        // Ingresos: pagos registrados en caja; si no hay, monto estimado por estudios/insumos
        $ingresosHoy = $this->sumPaymentsForDate($fechaFiltro);
        if ($ingresosHoy <= 0) {
            $ingresosHoy = $appointments->sum(fn ($app) => $this->calculateAppointmentRevenue($app));
        }

        $ingresosAyer = $this->sumPaymentsForDate($ayer);
        if ($ingresosAyer <= 0) {
            $ingresosAyer = $this->getSecureAppointmentQuery()
                ->with(['studies', 'supplies'])
                ->whereDate('start_time', $ayer)
                ->get()
                ->sum(fn ($app) => $this->calculateAppointmentRevenue($app));
        }

        $tendencia = $ingresosAyer > 0 ? (($ingresosHoy - $ingresosAyer) / $ingresosAyer) * 100 : 0;

        // 3. CÁLCULO DE TAT PROMEDIO (Métrica de Calidad)
        // Calculamos el tiempo desde que se confirma la cita hasta que el informe es firmado (deliverable)
        $terminadasHoy = $appointments->whereIn('status', ['entregable', 'entregado']);
        $tatPromedio = 0;
        if ($terminadasHoy->count() > 0) {
            $minutosTotales = $terminadasHoy->reduce(function ($carry, $app) {
                $inicio = Carbon::parse($app->start_time);
                $fin = Carbon::parse($app->updated_at); // updated_at refleja el último cambio de estado
                return $carry + $inicio->diffInMinutes($fin);
            }, 0);
            $tatPromedio = round($minutosTotales / $terminadasHoy->count());
        }

        // 4. DESGLOSE POR SEGUROS / PREVISIÓN (Métrica Financiera)
        $segurosQuery = DB::table('appointments')
            ->join('insurances', 'appointments.insurance_id', '=', 'insurances.id')
            ->whereDate('appointments.start_time', $fechaFiltro);

        $this->applySecureLabFilterToDBQuery($segurosQuery);

        $seguros = $segurosQuery->select('insurances.name', DB::raw('count(*) as total'))
            ->groupBy('insurances.name')
            ->get();

        // 5. PRODUCCIÓN POR MODALIDAD
        $modalidades = [];
        foreach ($appointments as $app) {
            if ($app->machine) {
                $grupo = $app->machine->group ?? 'Otros';
                $modalidades[$grupo] = ($modalidades[$grupo] ?? 0) + $app->studies->count();
            }
        }

        // 6. ESTADO DEL FLUJO CLÍNICO (Pasos del Paciente)
        $flujo = [
            'Espera/Agendado' => $appointments->whereIn('status', ['agendado', 'confirmado', 'espera', 'pre-agendado'])->count(),
            'En Equipo (DICOM)' => $appointments->whereIn('status', ['dicom_enviado', 'en_atencion'])->count(),
            'Radiólogo' => $appointments->whereIn('status', ['en_informe', 'para_firma', 'pendiente_radiologo'])->count(),
            'Secretaría' => $appointments->where('status', 'en_transcripcion')->count(),
            'Listos/Entregados' => $appointments->whereIn('status', ['entregable', 'entregado'])->count(),
        ];

        $alerts = $this->buildOperationalAlerts($appointments, $flujo, $tatPromedio);

        return response()->json([
            'success' => true,
            'data' => [
                'kpis' => [
                    'pacientes' => $totalPacientes,
                    'examenes' => $appointments->whereNotIn('status', ['agendado', 'confirmado', 'espera'])->count(),
                    'ingresos' => (int) $ingresosHoy,
                    'tendencia' => round($tendencia, 1),
                    'tat_promedio' => $tatPromedio,
                    'seguros' => $seguros
                ],
                'charts' => [
                    'modalidades' => $modalidades,
                    'flujo' => $flujo
                ],
                'alerts' => $alerts,
            ]
        ]);
    }

    private function buildOperationalAlerts($appointments, array $flujo, int $tatPromedio): array
    {
        $alerts = [];
        $tatThreshold = (int) env('DASHBOARD_TAT_ALERT_MINUTES', 180);

        if (($flujo['Secretaría'] ?? 0) > 15) {
            $alerts[] = [
                'level' => 'danger',
                'code' => 'transcription_backlog',
                'title' => 'Cuello de botella en transcripción',
                'message' => "Hay {$flujo['Secretaría']} estudios en secretaría/transcripción.",
            ];
        }

        if (($flujo['Radiólogo'] ?? 0) > 20) {
            $alerts[] = [
                'level' => 'warning',
                'code' => 'radiologist_backlog',
                'title' => 'Cola alta en radiología',
                'message' => "Hay {$flujo['Radiólogo']} estudios pendientes de informe o firma.",
            ];
        }

        $dicomStuck = $appointments->where('status', 'dicom_enviado')
            ->filter(fn ($a) => $a->updated_at && $a->updated_at->lt(now()->subHours(2)))
            ->count();

        if ($dicomStuck > 0) {
            $alerts[] = [
                'level' => 'warning',
                'code' => 'dicom_stuck',
                'title' => 'Estudios en espera de imágenes',
                'message' => "{$dicomStuck} cita(s) llevan más de 2 h en estado DICOM enviado sin avanzar.",
            ];
        }

        if ($tatPromedio > $tatThreshold && $tatPromedio > 0) {
            $alerts[] = [
                'level' => 'warning',
                'code' => 'tat_high',
                'title' => 'TAT por encima del objetivo',
                'message' => "Tiempo promedio de entrega: {$tatPromedio} min (objetivo: {$tatThreshold} min).",
            ];
        }

        $tomorrow = Carbon::tomorrow();
        $unconfirmedTomorrow = Appointment::query()
            ->when(!\App\Models\Laboratory::allowsAllLabs(), function ($q) {
                $labs = config('app.allowed_lab_ids');
                if (empty($labs)) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn('laboratory_id', $labs);
                }
            })
            ->whereBetween('start_time', [$tomorrow->copy()->startOfDay(), $tomorrow->copy()->endOfDay()])
            ->whereIn('status', ['agendado', 'pre-agendado'])
            ->count();

        if ($unconfirmedTomorrow > 0) {
            $alerts[] = [
                'level' => 'info',
                'code' => 'unconfirmed_tomorrow',
                'title' => 'Citas de mañana sin confirmar',
                'message' => "{$unconfirmedTomorrow} cita(s) para mañana aún en estado agendado.",
            ];
        }

        return $alerts;
    }
}