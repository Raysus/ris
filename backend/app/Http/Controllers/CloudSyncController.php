<?php

namespace App\Http\Controllers;

use App\Jobs\SyncEntityToCloud;
use App\Models\CloudSyncLog;
use Illuminate\Http\Request;

class CloudSyncController extends Controller
{
    private function assertAdmin(Request $request): void
    {
        $role = $request->user()->tipoUsuario->name ?? '';
        if (!in_array($role, ['admin', 'sis_admin'], true)) {
            abort(403, 'Solo administradores pueden consultar la sincronización cloud.');
        }
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
            'last_24h' => CloudSyncLog::where('created_at', '>=', now()->subDay())->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'logs' => $logs,
                'stats' => $stats,
                'cloud_configured' => filled(env('CLOUD_SERVER_URL')) && filled(env('CLOUD_SYNC_SECRET')),
            ],
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

        if (!$log->payload) {
            return response()->json([
                'success' => false,
                'message' => 'No hay payload almacenado para reintentar.',
            ], 400);
        }

        $log->update(['status' => 'pending', 'last_error' => null]);

        SyncEntityToCloud::dispatch(
            $log->entity_type,
            $log->action,
            $log->payload,
            $log->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Reintento de sincronización encolado.',
        ]);
    }
}
