<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\Machine;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Obtiene una consulta base de citas filtrada por los laboratorios permitidos del usuario.
     */
    private function getSecureAppointmentQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Appointment::query();

        if ($allowedLabs !== ['*']) {
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
        if ($allowedLabs !== ['*']) {
            if (empty($allowedLabs)) {
                $queryBuilder->whereRaw('1 = 0');
            } else {
                $queryBuilder->whereIn('appointments.laboratory_id', $allowedLabs);
            }
        }
        return $queryBuilder;
    }

    /**
     * Genera todas las métricas para el Dashboard.
     */
    public function getMetrics(Request $request)
    {
        // Determinamos la fecha a consultar (hoy por defecto)
        $fechaFiltro = $request->query('date') ? Carbon::parse($request->query('date')) : Carbon::today();
        $ayer = (clone $fechaFiltro)->subDay();

        // 1. OBTENCIÓN DE DATOS BASE
        $query = $this->getSecureAppointmentQuery()
            ->with(['studies', 'machine'])
            ->whereDate('start_time', $fechaFiltro);

        $appointments = $query->get();

        // 2. CÁLCULO DE KPIs BÁSICOS
        $totalPacientes = $appointments->unique('patient_id')->count();

        // Ingresos de hoy vs Ayer (Métrica de Tendencia)
        $ingresosHoy = $appointments->sum('total_price');

        $ingresosAyer = $this->getSecureAppointmentQuery()
            ->whereDate('start_time', $ayer)
            ->sum('total_price');

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
            'Espera/Agendado' => $appointments->whereIn('status', ['agendado', 'confirmado', 'espera'])->count(),
            'En Equipo (DICOM)' => $appointments->where('status', 'dicom_enviado')->count(),
            'Radiólogo' => $appointments->whereIn('status', ['en_informe', 'para_firma'])->count(),
            'Secretaría' => $appointments->where('status', 'en_transcripcion')->count(),
            'Listos/Entregados' => $appointments->whereIn('status', ['entregable', 'entregado'])->count(),
        ];

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
                ]
            ]
        ]);
    }
}