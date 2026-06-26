<?php

namespace App\Http\Controllers;

use App\Models\Hl7Message;
use App\Services\Hl7IntegrationService;
use Illuminate\Http\Request;

class Hl7Controller extends Controller
{
    public function inbound(Request $request, Hl7IntegrationService $hl7)
    {
        if (!config('hl7.enabled')) {
            return response()->json(['success' => false, 'message' => 'HL7 deshabilitado.'], 503);
        }

        $secret = config('hl7.inbound_secret');
        if (!is_string($secret) || $secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'HL7 habilitado sin secreto de entrada configurado.',
            ], 503);
        }

        $provided = (string) $request->header('X-HL7-Secret', '');
        if (!hash_equals($secret, $provided)) {
            return response()->json(['success' => false, 'message' => 'No autorizado.'], 401);
        }

        $raw = $request->input('message') ?? $request->getContent();
        if (!$raw || trim($raw) === '') {
            return response()->json(['success' => false, 'message' => 'Mensaje HL7 vacío.'], 422);
        }

        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');
        if (!$labId) {
            return response()->json(['success' => false, 'message' => 'Falta X-Lab-Id.'], 400);
        }

        try {
            $result = $hl7->processInbound($raw, $labId);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function index(Request $request)
    {
        $this->assertAdmin($request);

        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');
        $limit = min((int) $request->query('limit', 50), 200);

        $messages = Hl7Message::query()
            ->when($labId, fn ($q) => $q->where('laboratory_id', $labId))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json(['success' => true, 'data' => $messages]);
    }

    public function resendOru(Request $request, string $appointmentId, Hl7IntegrationService $hl7)
    {
        $this->assertAdmin($request);

        if (!config('hl7.enabled')) {
            return response()->json(['success' => false, 'message' => 'HL7 deshabilitado.'], 503);
        }

        $message = $hl7->sendOruForAppointment($appointmentId);

        return response()->json([
            'success' => true,
            'data' => $message,
        ]);
    }

    private function assertAdmin(Request $request): void
    {
        $role = $request->user()->tipoUsuario->name ?? '';
        if (!in_array($role, ['admin', 'sis_admin'], true)) {
            abort(403, 'Solo administradores.');
        }
    }
}
