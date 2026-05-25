<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Appointment;
use App\Models\Tariff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PaymentController extends Controller
{
    /**
     * Obtener desglose de precios para una cita
     * GET /api/payments/breakdown/{appointment_id}
     */
    public function getBreakdown($appointmentId)
    {
        try {
            $appointment = Appointment::with([
                'studies.exam',
                'insurancePlan',
                'supplies'
            ])->findOrFail($appointmentId);

            // Calcular desglose de exámenes
            $estudios = [];
            $totalArancel = 0;

            foreach ($appointment->studies as $study) {
                $exam = $study->exam;
                $precio = $exam ? $exam->price : 0;
                $totalArancel += $precio;

                $estudios[] = [
                    'id' => $study->id,
                    'nombre' => $exam ? $exam->name : $study->exam_name,
                    'codigo_fonasa' => $exam ? $exam->fonasa_code : $study->fonasa_code,
                    'precio' => $precio,
                    'cantidad' => $study->quantity ?? 1,
                ];
            }

            // Calcular copagos según previsión
            $plan = $appointment->insurancePlan;
            $porcentajeCopago = $plan ? $plan->percentage : 0;
            $copago = ($totalArancel * $porcentajeCopago) / 100;

            // Calcular insumos
            $totalInsumos = 0;
            $insumos = [];
            foreach ($appointment->supplies as $supply) {
                $insumos[] = [
                    'id' => $supply->id,
                    'nombre' => $supply->name,
                    'precio' => $supply->pivot->price_charged ?? 0,
                    'cantidad' => $supply->pivot->quantity ?? 1,
                ];
                $totalInsumos += ($supply->pivot->price_charged ?? 0) * ($supply->pivot->quantity ?? 1);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'appointment_id' => $appointmentId,
                    'estudios' => $estudios,
                    'insumos' => $insumos,
                    'resumen' => [
                        'total_arancel' => $totalArancel,
                        'total_insumos' => $totalInsumos,
                        'porcentaje_copago' => $porcentajeCopago,
                        'copago' => $copago,
                        'subtotal' => $totalArancel + $totalInsumos,
                        'total_a_pagar' => ($totalArancel + $totalInsumos) - $copago,
                    ],
                    'previsión' => [
                        'id' => $plan ? $plan->id : null,
                        'nombre' => $plan ? $plan->name : 'Particular',
                        'porcentaje' => $porcentajeCopago,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener desglose: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Registrar un nuevo pago
     * POST /api/payments
     */
    public function store(Request $request)
    {
        $request->validate([
            'appointment_id' => 'required|uuid|exists:appointments,id',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|string|in:Efectivo,Tarjeta Débito,Tarjeta Crédito,Transferencia,Mixto,Cheque',
            'status' => 'required|string|in:Pendiente,Pago Parcial,Pagado',
            'transaction_code' => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $appointment = Appointment::findOrFail($request->appointment_id);

                // Crear registro de pago
                $payment = Payment::create([
                    'appointment_id' => $appointment->id,
                    'user_id' => auth()->id(),
                    'amount' => $request->amount,
                    'payment_method' => $request->payment_method,
                    'transaction_code' => $request->transaction_code,
                    'status' => $request->status,
                ]);

                // Actualizar estado de la cita si está completamente pagada
                if ($request->status === 'Pagado') {
                    $appointment->update(['payment_status' => 'Pagado']);
                } elseif ($request->status === 'Pago Parcial') {
                    $appointment->update(['payment_status' => 'Parcial']);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Pago registrado exitosamente',
                    'data' => $payment->load('appointment', 'cashier.persona')
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar pago: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Obtener historial de pagos de una cita
     * GET /api/payments/history/{appointment_id}
     */
    public function getHistory($appointmentId)
    {
        try {
            $payments = Payment::where('appointment_id', $appointmentId)
                ->with(['cashier.persona', 'appointment'])
                ->orderBy('created_at', 'desc')
                ->get();

            $totalPagado = $payments->sum('amount');
            $appointment = Appointment::find($appointmentId);
            $totalDeuda = ($appointment->studies->sum('price') ?? 0) - $totalPagado;

            return response()->json([
                'success' => true,
                'data' => [
                    'pagos' => $payments,
                    'resumen' => [
                        'total_pagado' => $totalPagado,
                        'total_deuda' => max(0, $totalDeuda),
                        'estado' => $totalDeuda <= 0 ? 'Pagado' : 'Por Cobrar',
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener historial: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Obtener planes de salud (previsiones)
     * GET /api/payments/insurance-plans
     */
    public function getInsurancePlans(Request $request)
    {
        try {
            $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');
            $insuranceId = $request->query('insurance_id');
            
            $query = \App\Models\InsurancePlan::with('insurance')
                ->where('is_active', true)
                ->where('laboratory_id', $labId);
            
            if ($insuranceId) {
                $query->where('insurance_id', $insuranceId);
            }
            
            $plans = $query->get();

            return response()->json([
                'success' => true,
                'data' => $plans
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Generar comprobante de pago (PDF)
     * GET /api/payments/{id}/receipt
     */
    public function generateReceipt($paymentId)
    {
        try {
            $payment = Payment::with([
                'appointment.patient.persona',
                'appointment.studies.exam',
                'cashier.persona'
            ])->findOrFail($paymentId);

            // Aquí se generaría un PDF con la información del pago
            // Por ahora retornamos los datos
            return response()->json([
                'success' => true,
                'data' => [
                    'comprobante' => [
                        'numero' => 'REC-' . $payment->id,
                        'fecha' => $payment->created_at->format('d/m/Y H:i'),
                        'paciente' => $payment->appointment->patient->persona->names . ' ' . 
                                    $payment->appointment->patient->persona->last_name_1,
                        'monto' => $payment->amount,
                        'metodo' => $payment->payment_method,
                        'estado' => $payment->status,
                        'recibido_por' => $payment->cashier->persona->names ?? 'Sistema',
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Reporte de ingresos por período
     * GET /api/payments/reports/daily
     */
    public function getDailyReport(Request $request)
    {
        $fecha = $request->query('fecha') ? Carbon::parse($request->query('fecha')) : Carbon::now();

        $pagos = Payment::whereDate('created_at', $fecha->toDateString())
            ->with('cashier.persona')
            ->get();

        $resumen = [
            'fecha' => $fecha->format('d/m/Y'),
            'total_pagado' => $pagos->sum('amount'),
            'total_transacciones' => $pagos->count(),
            'por_metodo' => $pagos->groupBy('payment_method')->map->sum('amount'),
            'por_cajero' => $pagos->groupBy('user_id')->map(function ($items) {
                return [
                    'nombre' => $items->first()->cashier->persona->names,
                    'total' => $items->sum('amount'),
                    'cantidad' => $items->count(),
                ];
            })
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'pagos' => $pagos,
                'resumen' => $resumen
            ]
        ]);
    }

    /**
     * Reporte mensual de ingresos
     * GET /api/payments/reports/monthly?mes=2026-05
     */
    public function getMonthlyReport(Request $request)
    {
        $mes = $request->query('mes', Carbon::now()->format('Y-m'));
        $inicio = Carbon::parse($mes . '-01')->startOfMonth();
        $fin = $inicio->copy()->endOfMonth();

        $pagos = Payment::whereBetween('created_at', [$inicio, $fin])
            ->with('cashier.persona')
            ->get();

        $resumen = [
            'mes' => $mes,
            'total_pagado' => $pagos->sum('amount'),
            'total_transacciones' => $pagos->count(),
            'por_metodo' => $pagos->groupBy('payment_method')->map->sum('amount'),
            'por_dia' => $pagos->groupBy(fn ($p) => $p->created_at->format('Y-m-d'))->map->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => ['pagos' => $pagos, 'resumen' => $resumen],
        ]);
    }

    /**
     * Reporte por cajero
     * GET /api/payments/reports/cashier?fecha=2026-05-25
     */
    public function getCashierReport(Request $request)
    {
        $fecha = $request->query('fecha') ? Carbon::parse($request->query('fecha')) : Carbon::now();

        $pagos = Payment::whereDate('created_at', $fecha->toDateString())
            ->with('cashier.persona')
            ->get();

        $porCajero = $pagos->groupBy('user_id')->map(function ($items) {
            $cashier = $items->first()->cashier;
            return [
                'nombre' => $cashier?->persona?->names ?? 'Sistema',
                'total' => $items->sum('amount'),
                'cantidad' => $items->count(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'fecha' => $fecha->format('d/m/Y'),
                'por_cajero' => $porCajero,
                'total_dia' => $pagos->sum('amount'),
            ],
        ]);
    }
}
