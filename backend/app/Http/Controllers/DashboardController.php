<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\Machine;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
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

    public function getMetrics(Request $request)
    {
        $fechaFiltro = $request->query('date') ? Carbon::parse($request->query('date')) : Carbon::today();
        $ayer = (clone $fechaFiltro)->subDay();

        $appointments = $this->getSecureAppointmentQuery()
            ->with(['studies', 'machine'])
            ->whereDate('start_time', $fechaFiltro)
            ->where('status', '!=', 'anulado')
            ->get();

        $totalPacientes = $appointments->unique('patient_id')->count();

        $queryIngresosHoy = DB::table('appointment_studies')
            ->join('appointments', 'appointments.id', '=', 'appointment_studies.appointment_id')
            ->whereDate('appointments.start_time', $fechaFiltro)
            ->where('appointments.status', '!=', 'anulado');

        $this->applySecureLabFilterToDBQuery($queryIngresosHoy);
        $ingresosHoy = $queryIngresosHoy->sum(DB::raw('price * quantity'));

        $queryIngresosAyer = DB::table('appointment_studies')
            ->join('appointments', 'appointments.id', '=', 'appointment_studies.appointment_id')
            ->whereDate('appointments.start_time', $ayer)
            ->where('appointments.status', '!=', 'anulado');

        $this->applySecureLabFilterToDBQuery($queryIngresosAyer);
        $ingresosAyer = $queryIngresosAyer->sum(DB::raw('price * quantity'));

        $variacion = $ingresosAyer > 0 ? (($ingresosHoy - $ingresosAyer) / $ingresosAyer) * 100 : 0;

        $modalidades = [];
        foreach ($appointments as $app) {
            if ($app->machine) {
                $grupo = $app->machine->group ?? 'Otros';
                $modalidades[$grupo] = ($modalidades[$grupo] ?? 0) + $app->studies->count();
            }
        }

        $flujo = [
            'Espera/Agendado' => $appointments->whereIn('status', ['agendado', 'confirmado', 'espera'])->count(),
            'En Equipo' => $appointments->where('status', 'dicom_enviado')->count(),
            'Radiólogo' => $appointments->whereIn('status', ['en_informe', 'para_firma'])->count(),
            'Secretaria' => $appointments->where('status', 'en_transcripcion')->count(),
            'Listos/Entregados' => $appointments->whereIn('status', ['entregable', 'entregado'])->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'kpis' => [
                    'pacientes' => $totalPacientes,
                    'examenes' => $appointments->whereNotIn('status', ['agendado', 'confirmado', 'espera'])->count(),
                    'ingresos' => (int) $ingresosHoy,
                    'tendencia' => round($variacion, 1)
                ],
                'charts' => [
                    'flujo' => $flujo,
                    'modalidades' => $modalidades
                ]
            ]
        ]);
    }
}