<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
        ];

        $healthy = collect($checks)->every(fn (array $c) => $c['status'] === 'ok');

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Database unreachable'];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health:ping:' . uniqid('', true);
            Cache::put($key, '1', 10);
            $ok = Cache::get($key) === '1';
            Cache::forget($key);

            return ['status' => $ok ? 'ok' : 'error', 'driver' => config('cache.default')];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'driver' => config('cache.default')];
        }
    }

    private function checkQueue(): array
    {
        $driver = config('queue.default');

        try {
            $size = Queue::size('default');

            return [
                'status' => 'ok',
                'driver' => $driver,
                'pending_jobs' => $size,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'warning',
                'driver' => $driver,
                'message' => 'Queue driver not verified',
            ];
        }
    }
}
