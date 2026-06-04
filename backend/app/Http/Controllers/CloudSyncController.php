<?php

namespace App\Http\Controllers;

use App\Jobs\SyncAppointmentBundleToCloud;
use App\Jobs\SyncEntityToCloud;
use App\Models\Appointment;
use App\Models\CloudSyncLog;
use App\Services\CloudCatalogPullService;
use App\Support\CloudSyncMode;
use Illuminate\Http\Request;

class CloudSyncController extends Controller
{
    private function assertAdmin(Request $request): void
    {
        $role = $request->user()->tipoUsuario->name ?? '';
        if (!in_array($role, ['admin', 'sis_admin'], true)) {
            abort(403, 'Solo administradores pueden gestionar la sincronización cloud.');
        }
    }

    public function status(Request $request)
    {
        $this->assertAdmin($request);

        return response()->json([
            'success' => true,
            'data' => [
                'role' => CloudSyncMode::role(),
                'can_push' => CloudSyncMode::canPushToCloud(),
                'can_pull' => CloudSyncMode::canPushToCloud(),
                'accepts_inbound' => CloudSyncMode::acceptsInbound(),
                'inbound_url' => config('cloud_sync.inbound_url'),
                'export_url' => config('cloud_sync.export_url'),
                'queue_connection' => config('queue.default'),
            ],
        ]);
    }

    public function index(Request $request)
    {
        $this->assertAdmin($request);

        $status = $request->query('status');
        $limit = min((int) $request->query('limit', 50), 200);

        $query = CloudSyncLog::query()->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        $logs = $query->limit($limit)->get();

        $stats = [
            'pending' => CloudSyncLog::where('status', 'pending')->count(),
            'success' => CloudSyncLog::where('status', 'success')->count(),
            'failed' => CloudSyncLog::where('status', 'failed')->count(),
            'skipped' => CloudSyncLog::where('status', 'skipped')->count(),
            'last_24h' => CloudSyncLog::where('created_at', '>=', now()->subDay())->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'logs' => $logs,
                'stats' => $stats,
                'cloud_configured' => CloudSyncMode::canPushToCloud(),
                'role' => CloudSyncMode::role(),
            ],
        ]);
    }

    public function pullCatalog(Request $request, CloudCatalogPullService $pull)
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'laboratory_id' => 'nullable|uuid',
            'include_patients' => 'sometimes|boolean',
        ]);

        $labId = $validated['laboratory_id']
            ?? config('app.current_lab_id')
            ?? $request->header('X-Lab-Id');

        if ($labId === 'ALL' || $labId === '') {
            $labId = null;
        }

        try {
            if (CloudSyncMode::acceptsInbound()) {
                $result = $pull->pullLocalSnapshot($labId, (bool) ($validated['include_patients'] ?? false));
            } else {
                $result = $pull->pull($labId, (bool) ($validated['include_patients'] ?? false));
            }

            return response()->json([
                'success' => true,
                'message' => 'Catálogo sincronizado desde la nube.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function retryFailed(Request $request)
    {
        $this->assertAdmin($request);

        if (!CloudSyncMode::canPushToCloud()) {
            return response()->json([
                'success' => false,
                'message' => 'Envío a nube no configurado (CLOUD_API_BASE / CLOUD_SYNC_SECRET).',
            ], 422);
        }

        $logs = CloudSyncLog::query()
            ->whereIn('status', ['failed', 'pending'])
            ->whereNotNull('payload')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $queued = 0;
        foreach ($logs as $log) {
            $payload = $this->payloadForRetry($log);
            if (!$payload) {
                continue;
            }
            $log->update(['status' => 'pending', 'last_error' => null, 'payload' => $payload]);
            SyncEntityToCloud::dispatch(
                $log->entity_type,
                $log->action,
                $payload,
                $log->id
            );
            $queued++;
        }

        return response()->json([
            'success' => true,
            'message' => "Se encolaron {$queued} registros para enviar a la nube.",
            'data' => ['queued' => $queued],
        ]);
    }

    public function retry(Request $request, string $id)
    {
        $this->assertAdmin($request);

        $log = CloudSyncLog::findOrFail($id);

        if ($log->status === 'success') {
            return response()->json([
                'success' => false,
                'message' => 'Este registro ya fue sincronizado correctamente.',
            ], 400);
        }

        $payload = $this->payloadForRetry($log);
        if (!$payload) {
            return response()->json([
                'success' => false,
                'message' => 'No hay payload almacenado para reintentar.',
            ], 400);
        }

        $log->update(['status' => 'pending', 'last_error' => null, 'payload' => $payload]);

        $appointmentId = $payload['id'] ?? $log->entity_id;
        if ($appointmentId && str_contains((string) $log->entity_type, 'Appointment')) {
            SyncAppointmentBundleToCloud::dispatch($appointmentId, $log->action ?? 'created', $log->id);
        } else {
            SyncEntityToCloud::dispatch(
                $log->entity_type,
                $log->action,
                $payload,
                $log->id
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Reintento de sincronización encolado.',
        ]);
    }

    private function payloadForRetry(CloudSyncLog $log): ?array
    {
        $payload = $log->payload;
        if (!is_array($payload)) {
            return null;
        }

        $type = $log->entity_type ?? '';
        $appointmentId = $payload['id'] ?? $log->entity_id ?? null;

        if ($appointmentId && str_contains($type, 'Appointment')) {
            $appointment = Appointment::with(['patient.persona', 'studies', 'supplies'])->find($appointmentId);
            if ($appointment) {
                return $appointment->toArray();
            }
        }

        return $payload;
    }
}
