<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\Laboratory;
use App\Models\Payment;
use App\Models\ReferringDoctor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
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

        $lab = $this->resolveNominaLaboratory($appointments);

        return response()->json([
            'success' => true,
            'mes' => $inicio->format('Y-m'),
            'mes_formato' => strtoupper($inicio->translatedFormat('F Y')),
            'centro' => strtoupper($lab?->name ?? 'CENTRO'),
            'ciudad' => strtoupper($lab?->city ?? ''),
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

        $lab = $this->resolveNominaLaboratory($appointments);
        Carbon::setLocale('es');

        return [
            'success' => true,
            'fecha' => $carbon->format('Y-m-d'),
            'fecha_formato' => strtolower($carbon->translatedFormat('d M y')),
            'centro' => strtoupper($lab?->name ?? 'CENTRO'),
            'ciudad' => strtoupper($lab?->city ?? ''),
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

    /**
     * Laboratorio activo (sede seleccionada) o el de la primera cita del reporte.
     */
    private function resolveNominaLaboratory($appointments): ?Laboratory
    {
        $labId = config('app.current_lab_id');
        if ($labId) {
            $lab = Laboratory::find($labId);
            if ($lab) {
                return $lab;
            }
        }

        return $appointments->first()?->laboratory;
    }

    /**
     * Agenda semanal de citas para un médico destinatario (radiólogo asignado en agenda).
     * GET /api/reports/agenda-semanal?week=2026-07-07&destination_doctor_id=uuid
     */
    public function getAgendaSemanalMedico(Request $request)
    {
        $destinationDoctorId = $request->query('destination_doctor_id');
        if (!$destinationDoctorId) {
            return response()->json([
                'success' => false,
                'message' => 'Seleccione un médico destinatario.',
            ], 422);
        }

        $destinationDoctor = User::with('persona')->find($destinationDoctorId);
        if (!$destinationDoctor) {
            return response()->json(['success' => false, 'message' => 'Médico destinatario no encontrado.'], 404);
        }

        try {
            $ref = Carbon::parse($request->query('week', date('Y-m-d')));
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Fecha inválida.'], 422);
        }

        Carbon::setLocale('es');
        $inicio = $ref->copy()->startOfWeek(Carbon::MONDAY);
        $fin = $ref->copy()->endOfWeek(Carbon::SUNDAY);

        $appointments = $this->getSecureAppointmentQuery()
            ->with([
                'patient.persona',
                'studies',
                'machine',
                'insurance',
                'laboratory',
                'referringDoctor',
                'destinationDoctor.persona',
            ])
            ->where('status', '!=', 'anulado')
            ->whereBetween('start_time', [$inicio->copy()->startOfDay(), $fin->copy()->endOfDay()])
            ->where(function ($query) use ($destinationDoctorId) {
                $query->where('destination_doctor_id', $destinationDoctorId)
                    ->orWhereHas('studies', fn ($studies) => $studies->where('radiologist_user_id', $destinationDoctorId));
            })
            ->orderBy('start_time')
            ->get();

        $dias = [];
        for ($cursor = $inicio->copy(); $cursor->lte($fin); $cursor->addDay()) {
            $fecha = $cursor->format('Y-m-d');
            $dias[$fecha] = [
                'fecha' => $fecha,
                'dia_formato' => ucfirst($cursor->translatedFormat('l j \d\e F')),
                'citas' => [],
            ];
        }

        foreach ($appointments as $app) {
            $fecha = Carbon::parse($app->start_time)->format('Y-m-d');
            if (!isset($dias[$fecha])) {
                continue;
            }

            $persona = $app->patient?->persona;
            $nombrePaciente = $persona
                ? trim("{$persona->names} {$persona->last_name_1} {$persona->last_name_2}")
                : 'Sin nombre';

            $examenes = $app->studies
                ->map(function ($study) {
                    $nombre = trim(($study->exam_name ?? '') . ($study->sub_exam_name ? ' ' . $study->sub_exam_name : ''));
                    return $nombre !== '' ? $nombre : null;
                })
                ->filter()
                ->values()
                ->all();

            $machineIds = collect([$app->machine_id])
                ->merge($app->studies->pluck('machine_id'))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $dias[$fecha]['citas'][] = [
                'hora' => Carbon::parse($app->start_time)->format('H:i'),
                'hora_fin' => $app->end_time ? Carbon::parse($app->end_time)->format('H:i') : '',
                'paciente' => $nombrePaciente,
                'rut' => $persona->rut ?? '',
                'examenes' => $examenes,
                'sala' => $app->machine->name ?? '—',
                'machine_id' => $app->machine_id,
                'machine_ids' => $machineIds,
                'estado' => $this->etiquetaEstadoAgenda($app->status),
                'institucion' => $app->insurance?->name ?? ($app->entidad_pagadora ?: 'Particular'),
                'medico_referente' => $this->nombreMedicoReferenteAgenda($app->referringDoctor),
                'observacion' => trim((string) ($app->origin ?? '')),
            ];
        }

        $lab = $this->resolveNominaLaboratory($appointments);
        $medicoDestinatario = [
            'id' => $destinationDoctor->id,
            'nombre' => $this->nombreRadiologoAgenda($destinationDoctor),
        ];

        return response()->json([
            'success' => true,
            'semana_inicio' => $inicio->format('Y-m-d'),
            'semana_fin' => $fin->format('Y-m-d'),
            'semana_formato' => $inicio->translatedFormat('j \d\e F') . ' — ' . $fin->translatedFormat('j \d\e F Y'),
            'centro' => strtoupper($lab?->name ?? 'CENTRO'),
            'ciudad' => strtoupper($lab?->city ?? ''),
            'medico_destinatario' => $medicoDestinatario,
            'medico' => $medicoDestinatario,
            'total_citas' => $appointments->count(),
            'dias' => array_values($dias),
        ]);
    }

    private function nombreMedicoReferenteAgenda(?ReferringDoctor $doctor): string
    {
        if (!$doctor) {
            return '';
        }

        return trim("{$doctor->names} {$doctor->last_name_1} {$doctor->last_name_2}");
    }

    private function nombreRadiologoAgenda(?User $doctor): string
    {
        if (!$doctor?->persona) {
            return '';
        }

        $p = $doctor->persona;
        $apellidos = trim("{$p->last_name_1} {$p->last_name_2}");
        $nombres = trim((string) ($p->names ?? ''));

        if ($apellidos !== '' && $nombres !== '') {
            return "Dr(a). {$apellidos}, {$nombres}";
        }

        return trim("Dr(a). {$apellidos} {$nombres}");
    }

    private function etiquetaEstadoAgenda(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'pre-agendado' => 'Pre-agendado',
            'agendado' => 'Agendado',
            'confirmado' => 'Confirmado',
            'espera' => 'En espera',
            'atencion' => 'En atención',
            'realizado' => 'Realizado',
            'informe' => 'En informe',
            'entregable' => 'Entregable',
            'entregado' => 'Entregado',
            'cancelado' => 'Cancelado',
            default => ucfirst((string) $status),
        };
    }

    /**
     * Consolidación matriz + sucursales (producción y cobros).
     * GET /api/reports/consolidated-matrix?month=2026-05
     */
    public function getConsolidatedMatrix(Request $request)
    {
        $mes = $request->query('month', date('Y-m'));
        $anio = substr($mes, 0, 4);
        $mesNum = substr($mes, 5, 2);

        $allowedLabs = config('app.allowed_lab_ids');
        $labQuery = Laboratory::query()->orderBy('name');

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
            $labQuery->whereIn('id', $allowedLabs ?: []);
        }

        $laboratories = $labQuery->get();
        $inicio = Carbon::parse($mes . '-01')->startOfMonth();
        $fin = $inicio->copy()->endOfMonth();

        $filas = [];

        foreach ($laboratories as $lab) {
            $citas = Appointment::where('laboratory_id', $lab->id)
                ->whereYear('start_time', $anio)
                ->whereMonth('start_time', $mesNum)
                ->whereNotIn('status', ['anulado', 'cancelado'])
                ->with('studies')
                ->get();

            $produccion = $citas->sum(fn ($a) => $a->studies->sum(fn ($s) => (float) ($s->price ?? 0) * ($s->quantity ?? 1)));

            $cobrado = Payment::whereHas('appointment', fn ($q) => $q->where('laboratory_id', $lab->id))
                ->whereBetween('created_at', [$inicio, $fin])
                ->sum('amount');

            $entregados = $citas->whereIn('status', ['entregable', 'entregado'])->count();

            $filas[] = [
                'laboratory_id' => $lab->id,
                'nombre' => $lab->name,
                'es_matriz' => $lab->parent_id === null,
                'parent_id' => $lab->parent_id,
                'citas' => $citas->count(),
                'entregados' => $entregados,
                'produccion' => $produccion,
                'cobrado' => (float) $cobrado,
            ];
        }

        $totales = [
            'citas' => array_sum(array_column($filas, 'citas')),
            'entregados' => array_sum(array_column($filas, 'entregados')),
            'produccion' => array_sum(array_column($filas, 'produccion')),
            'cobrado' => array_sum(array_column($filas, 'cobrado')),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'mes' => $mes,
                'filas' => $filas,
                'totales' => $totales,
            ],
        ]);
    }
}