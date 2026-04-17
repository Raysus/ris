<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Laboratory;

class CheckLabTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/all-laboratories') || $request->is('all-laboratories') || $request->is('api/login')) {
            return $next($request);
        }

        $labId = $request->header('X-Lab-Id');
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        if ($user->tipo_usuario_id == 1) {
            if (!$labId || $labId === 'ALL') {
                config(['app.allowed_lab_ids' => ['*']]);
                return $next($request);
            }
        }

        $assignedIds = $user->laboratories()->pluck('laboratories.id')->toArray();
        $allowedIds = $assignedIds;

        if ($user->tipo_usuario_id == 2) {
            $matrices = Laboratory::whereIn('id', $assignedIds)->whereNull('parent_id')->pluck('id')->toArray();
            $padres = Laboratory::whereIn('id', $assignedIds)->whereNotNull('parent_id')->pluck('parent_id')->toArray();
            $todasLasMatrices = array_unique(array_merge($matrices, $padres));

            $sucursales = Laboratory::whereIn('parent_id', $todasLasMatrices)->pluck('id')->toArray();
            $allowedIds = array_unique(array_merge($todasLasMatrices, $sucursales));
        }

        if (!$labId || $labId === 'ALL') {
            config(['app.current_lab_id' => null]);
            config(['app.allowed_lab_ids' => $allowedIds]);
            return $next($request);
        }

        $laboratory = Laboratory::with('children')->find($labId);

        if (!$laboratory) {
            return response()->json(['success' => false, 'message' => 'Laboratorio no existe.'], 404);
        }

        $contextIds = [$laboratory->id];

        if (is_null($laboratory->parent_id)) {
            $childrenIds = $laboratory->children->pluck('id')->toArray();
            $contextIds = array_merge($contextIds, $childrenIds);
        }

        $finalAllowedContext = array_intersect($contextIds, $allowedIds);

        if (empty($finalAllowedContext) && $user->tipo_usuario_id != 1) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado a esta sucursal.'], 403);
        }

        config(['app.current_lab_id' => $laboratory->id]);
        config(['app.allowed_lab_ids' => array_values($finalAllowedContext)]);

        return $next($request);
    }
}