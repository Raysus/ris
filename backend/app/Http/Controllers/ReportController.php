<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
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

    public function getHonorarios(Request $request)
    {
        $mesFiltro = $request->query('month');

        if (!$mesFiltro) {
            $mesFiltro = date('Y-m');
        }

        $anio = substr($mesFiltro, 0, 4);
        $mes = substr($mesFiltro, 5, 2);

        $appointments = $this->getSecureAppointmentQuery()
            ->with(['studies', 'destinationDoctor.persona'])
            ->whereIn('status', ['entregable', 'entregado'])
            ->whereYear('updated_at', $anio)
            ->whereMonth('updated_at', $mes)
            ->get();

        $produccionMedicos = [];

        foreach ($appointments as $app) {
            $docId = $app->destination_doctor_id ?? 0;

            $docName = "Dr(a). No Asignado";
            if ($app->destinationDoctor && $app->destinationDoctor->persona) {
                $p = $app->destinationDoctor->persona;
                $docName = "Dr(a). " . trim("{$p->names} {$p->last_name_1}");
            }

            if (!isset($produccionMedicos[$docId])) {
                $produccionMedicos[$docId] = [
                    'nombre' => $docName,
                    'informes' => 0,
                    'examenes' => 0,
                    'totalFacturado' => 0
                ];
            }

            $produccionMedicos[$docId]['informes'] += 1;

            foreach ($app->studies as $study) {
                $produccionMedicos[$docId]['examenes'] += 1;
                $produccionMedicos[$docId]['totalFacturado'] += $study->price;
            }
        }

        $resultado = array_values($produccionMedicos);
        usort($resultado, function ($a, $b) {
            return $b['totalFacturado'] <=> $a['totalFacturado'];
        });

        return response()->json(['success' => true, 'data' => $resultado]);
    }

    public function getExamenesMensuales(Request $request)
    {
        $mesFiltro = $request->query('month');

        if (!$mesFiltro) {
            $mesFiltro = date('Y-m');
        }

        $anio = substr($mesFiltro, 0, 4);
        $mes = substr($mesFiltro, 5, 2);

        $appointments = $this->getSecureAppointmentQuery()
            ->with(['studies', 'machine'])
            ->whereYear('start_time', $anio)
            ->whereMonth('start_time', $mes)
            ->where('status', '!=', 'anulado')
            ->orderBy('start_time', 'asc')
            ->get();

        $agrupado = [];
        $diasDelMes = Carbon::parse($mesFiltro)->daysInMonth;

        foreach ($appointments as $app) {
            $dia = (int) Carbon::parse($app->start_time)->format('d');
            $sala = $app->machine->name ?? 'N/A';

            foreach ($app->studies as $study) {
                $examen = $study->exam_name ?? 'Sin descripción';
                $llave = $examen . '|' . $sala;

                if (!isset($agrupado[$llave])) {
                    $agrupado[$llave] = [
                        'examen' => $examen,
                        'sala' => $sala,
                        'total' => 0,
                        'dias' => array_fill(1, $diasDelMes, 0)
                    ];
                }

                $cantidad = $study->quantity ?? 1;
                $agrupado[$llave]['total'] += $cantidad;
                $agrupado[$llave]['dias'][$dia] += $cantidad;
            }
        }

        $resultado = array_values($agrupado);
        usort($resultado, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        return response()->json([
            'success' => true,
            'dias_del_mes' => $diasDelMes,
            'data' => $resultado
        ]);
    }
}