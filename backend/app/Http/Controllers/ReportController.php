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

    /**
     * Nómina diaria de pacientes (formato RDOX / Excel operativo)
     * GET /api/reports/nomina-diaria?date=2026-05-05
     */
    public function getNominaDiaria(Request $request)
    {
        $fecha = $request->query('date', date('Y-m-d'));

        try {
            $carbon = Carbon::parse($fecha);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Fecha inválida.'], 422);
        }

        $appointments = $this->getNominaAppointmentsQuery()
            ->whereDate('start_time', $carbon->toDateString())
            ->orderBy('start_time')
            ->get();

        return response()->json($this->buildNominaPayload($carbon, $appointments));
    }

    /**
     * Nómina mensual — un bloque por día con citas (formato pestañas Excel RDOX)
     * GET /api/reports/nomina-mensual?month=2026-05
     */
    public function getNominaMensual(Request $request)
    {
        $mesFiltro = $request->query('month', date('Y-m'));

        try {
            $inicio = Carbon::parse($mesFiltro . '-01')->startOfMonth();
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Mes inválido.'], 422);
        }

        $fin = $inicio->copy()->endOfMonth();

        $appointments = $this->getNominaAppointmentsQuery()
            ->whereBetween('start_time', [$inicio->copy()->startOfDay(), $fin->copy()->endOfDay()])
            ->orderBy('start_time')
            ->get();

        $porDia = $appointments->groupBy(fn ($app) => Carbon::parse($app->start_time)->format('Y-m-d'));

        Carbon::setLocale('es');
        $dias = [];
        $totalesMes = [
            'pacientes' => 0,
            'total_boleta' => 0,
            'efectivo' => 0,
            'transbank' => 0,
            'transferencia' => 0,
            'bono' => 0,
        ];

        foreach ($porDia as $fecha => $citasDelDia) {
            $carbon = Carbon::parse($fecha);
            $payload = $this->buildNominaPayload($carbon, $citasDelDia);
            $payload['hoja_nombre'] = strtoupper($carbon->translatedFormat('d F Y'));
            $dias[] = $payload;

            $totalesMes['pacientes'] += count($payload['data']);
            foreach (['total_boleta', 'efectivo', 'transbank', 'transferencia', 'bono'] as $key) {
                $totalesMes[$key] += $payload['totales'][$key] ?? 0;
            }
        }

        $lab = $appointments->first()?->laboratory;

        return response()->json([
            'success' => true,
            'mes' => $inicio->format('Y-m'),
            'mes_formato' => strtoupper($inicio->translatedFormat('F Y')),
            'centro' => strtoupper($lab->name ?? 'RDOX PORTAL'),
            'ciudad' => strtoupper($lab->city ?? 'TEMUCO'),
            'dias' => $dias,
            'totales_mes' => $totalesMes,
        ]);
    }

    private function getNominaAppointmentsQuery()
    {
        return $this->getSecureAppointmentQuery()
            ->with([
                'patient.persona',
                'studies.machine',
                'referringDoctor',
                'destinationDoctor.persona',
                'insurance',
                'payments.cashier.persona',
                'supplies',
                'laboratory',
            ])
            ->where('status', '!=', 'anulado');
    }

    private function buildNominaPayload(Carbon $carbon, $appointments): array
    {
        $filas = [];
        $totales = [
            'total_boleta' => 0,
            'efectivo' => 0,
            'transbank' => 0,
            'transferencia' => 0,
            'bono' => 0,
        ];

        foreach ($appointments as $index => $app) {
            $persona = $app->patient?->persona;
            [$rxIntraoral, $coneBeam] = $this->clasificarEstudiosNomina($app->studies);

            $totalBoleta = (float) $app->studies->sum('price');
            foreach ($app->supplies as $sup) {
                $totalBoleta += (float) ($sup->pivot->price_charged ?? 0);
            }

            $pagos = $this->desglosePagosNomina($app);
            if ($totalBoleta <= 0 && $pagos['total_pagado'] > 0) {
                $totalBoleta = $pagos['total_pagado'];
            }

            $nombrePaciente = $persona
                ? trim("{$persona->names} {$persona->last_name_1} {$persona->last_name_2}")
                : 'SIN NOMBRE';

            $edad = '';
            if ($persona?->birth_date) {
                $edad = Carbon::parse($persona->birth_date)->age;
            }

            $radiologo = '';
            if ($app->destinationDoctor?->persona) {
                $p = $app->destinationDoctor->persona;
                $radiologo = strtoupper(trim("{$p->last_name_1} {$p->names}"));
            }

            $operador = '';
            $pagoConCajero = $app->payments->first();
            if ($pagoConCajero?->cashier?->persona) {
                $c = $pagoConCajero->cashier->persona;
                $operador = strtoupper(trim($c->names ?? ''));
            }

            $dentista = '';
            if ($app->referringDoctor) {
                $d = $app->referringDoctor;
                $dentista = strtoupper(trim("{$d->last_name_1} {$d->names}"));
            }

            $institucion = $app->insurance?->name
                ?? ($app->entidad_pagadora ?: 'PARTICULAR');

            $fila = [
                'numero' => $index + 1,
                'nombre_paciente' => $nombrePaciente,
                'rut' => $persona->rut ?? '',
                'edad' => $edad,
                'rx_intracoral' => $rxIntraoral,
                'cone_beam' => $coneBeam,
                'boleta' => $app->transaction_code ?? '',
                'total_boleta' => round($totalBoleta),
                'efectivo' => round($pagos['efectivo']),
                'transbank' => round($pagos['transbank']),
                'transferencia' => round($pagos['transferencia']),
                'bono' => round($pagos['bono']),
                'radiologo' => $radiologo,
                'operador' => $operador,
                'dentistas' => $dentista,
                'institucion' => strtoupper($institucion),
                'observacion' => $this->observacionNomina($app),
            ];

            $filas[] = $fila;
            $totales['total_boleta'] += $fila['total_boleta'];
            $totales['efectivo'] += $fila['efectivo'];
            $totales['transbank'] += $fila['transbank'];
            $totales['transferencia'] += $fila['transferencia'];
            $totales['bono'] += $fila['bono'];
        }

        $lab = $appointments->first()?->laboratory;
        Carbon::setLocale('es');

        return [
            'success' => true,
            'fecha' => $carbon->format('Y-m-d'),
            'fecha_formato' => strtolower($carbon->translatedFormat('d M y')),
            'centro' => strtoupper($lab->name ?? 'RDOX PORTAL'),
            'ciudad' => strtoupper($lab->city ?? 'TEMUCO'),
            'data' => $filas,
            'totales' => $totales,
        ];
    }

    private function clasificarEstudiosNomina($studies): array
    {
        $rx = [];
        $cone = [];

        foreach ($studies as $study) {
            $group = strtoupper($study->machine->group ?? '');
            $nombre = trim(($study->exam_name ?? '') . ($study->sub_exam_name ? ' ' . $study->sub_exam_name : ''));
            if ($nombre === '') {
                continue;
            }

            $esCone = in_array($group, ['CT', 'CBCT', 'CONE', 'TC'], true)
                || preg_match('/cbct|cone.?beam|tomograf/i', $nombre);

            if ($esCone) {
                $cone[] = $nombre;
            } else {
                $rx[] = $nombre;
            }
        }

        return [implode(' / ', $rx), implode(' / ', $cone)];
    }

    private function desglosePagosNomina($appointment): array
    {
        $efectivo = $transbank = $transferencia = $bono = 0.0;
        $totalPagado = 0.0;

        foreach ($appointment->payments as $payment) {
            $monto = (float) $payment->amount;
            $totalPagado += $monto;
            $metodo = strtolower($payment->payment_method ?? '');

            if (str_contains($metodo, 'efectivo')) {
                $efectivo += $monto;
            } elseif (str_contains($metodo, 'tarjeta') || str_contains($metodo, 'transbank')) {
                $transbank += $monto;
            } elseif (str_contains($metodo, 'transfer')) {
                $transferencia += $monto;
            } elseif (str_contains($metodo, 'bono') || str_contains($metodo, 'mixto')) {
                $bono += $monto;
            } else {
                $efectivo += $monto;
            }
        }

        if ($appointment->payments->isEmpty() && $appointment->payment_method) {
            $metodoCita = strtolower($appointment->payment_method);
            $montoEstimado = (float) $appointment->studies->sum('price');
            if (str_contains($metodoCita, 'efectivo')) {
                $efectivo = $montoEstimado;
            } elseif (str_contains($metodoCita, 'tarjeta')) {
                $transbank = $montoEstimado;
            } elseif (str_contains($metodoCita, 'transfer')) {
                $transferencia = $montoEstimado;
            }
            $totalPagado = $montoEstimado;
        }

        return [
            'efectivo' => $efectivo,
            'transbank' => $transbank,
            'transferencia' => $transferencia,
            'bono' => $bono,
            'total_pagado' => $totalPagado,
        ];
    }

    private function observacionNomina($appointment): string
    {
        $partes = array_filter([
            $appointment->tipo_bono && $appointment->tipo_bono !== 'Sin Bono' ? $appointment->tipo_bono : null,
            $appointment->payment_status ? "Pago: {$appointment->payment_status}" : null,
            $appointment->origin ?: null,
        ]);

        return strtoupper(implode(' | ', $partes));
    }
}