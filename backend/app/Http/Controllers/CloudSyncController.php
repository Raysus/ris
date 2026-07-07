<?php

namespace App\Http\Controllers;

use App\Jobs\SyncAppointmentBundleToCloud;
use App\Jobs\SyncEntityToCloud;
use App\Models\CloudSyncLog;
use App\Services\CloudCatalogPullService;
use App\Services\CloudSyncRetryService;
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
            'include_users' => 'sometimes|boolean',
            'include_appointments' => 'sometimes|boolean',
            'appointments_from' => 'nullable|date',
            'appointments_to' => 'nullable|date|after_or_equal:appointments_from',
        ]);

        $labId = $validated['laboratory_id']
            ?? config('app.current_lab_id')
            ?? $request->header('X-Lab-Id');

        if ($labId === 'ALL' || $labId === '') {
            $labId = null;
        }

        $includePatients = (bool) ($validated['include_patients'] ?? false);
        $includeUsers = (bool) ($validated['include_users'] ?? true);
        $includeAppointments = (bool) ($validated['include_appointments'] ?? false);
        $appointmentsFrom = $validated['appointments_from'] ?? null;
        $appointmentsTo = $validated['appointments_to'] ?? null;

        if ($includeAppointments) {
            $includePatients = true;
        }

        try {
            if (CloudSyncMode::acceptsInbound()) {
                $result = $pull->pullLocalSnapshot(
                    $labId,
                    $includePatients,
                    $includeUsers,
                    $includeAppointments,
                    $appointmentsFrom,
                    $appointmentsTo,
                );
            } else {
                $result = $pull->pull(
                    $labId,
                    $includePatients,
                    null,
                    $includeUsers,
                    $includeAppointments,
                    $appointmentsFrom,
                    $appointmentsTo,
                );
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
        $retry = app(CloudSyncRetryService::class);
        foreach ($logs as $log) {
            $payload = $retry->payloadForRetry($log);
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

        $retry = app(CloudSyncRetryService::class);
        $payload = $retry->payloadForRetry($log);
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
}
