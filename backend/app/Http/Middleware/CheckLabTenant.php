<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Laboratory;
use App\Services\LaboratoryProfileService;

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

        $user->loadMissing('tipoUsuario');
        $roleName = $user->tipoUsuario?->name;

        if ($roleName === 'sis_admin') {
            if (!$labId || $labId === 'ALL') {
                config(['app.allowed_lab_ids' => ['*']]);
                config(['app.lab_profile' => LaboratoryProfileService::profileForCode('clinical')]);
                return $next($request);
            }
        }

        $assignedIds = $user->laboratories()->pluck('laboratories.id')->toArray();
        $allowedIds = $assignedIds;

        if ($roleName === 'admin') {
            $matrices = Laboratory::whereIn('id', $assignedIds)->whereNull('parent_id')->pluck('id')->toArray();
            $padres = Laboratory::whereIn('id', $assignedIds)->whereNotNull('parent_id')->pluck('parent_id')->toArray();
            $todasLasMatrices = array_unique(array_merge($matrices, $padres));

            $sucursales = Laboratory::whereIn('parent_id', $todasLasMatrices)->pluck('id')->toArray();
            $allowedIds = array_unique(array_merge($todasLasMatrices, $sucursales));
        }

        if (!$labId || $labId === 'ALL') {
            config(['app.current_lab_id' => null]);
            config(['app.allowed_lab_ids' => $allowedIds]);
            config(['app.lab_profile' => LaboratoryProfileService::profileForCode('clinical')]);
            return $next($request);
        }

        $laboratory = Laboratory::with(['children', 'type'])->find($labId);

        if (!$laboratory) {
            return response()->json(['success' => false, 'message' => 'Laboratorio no existe.'], 404);
        }

        $contextIds = [$laboratory->id];

        if (is_null($laboratory->parent_id)) {
            $childrenIds = $laboratory->children->pluck('id')->toArray();
            $contextIds = array_merge($contextIds, $childrenIds);
        }

        $finalAllowedContext = array_intersect($contextIds, $allowedIds);

        if (empty($finalAllowedContext) && $roleName !== 'sis_admin') {
            return response()->json(['success' => false, 'message' => 'Acceso denegado a esta sucursal.'], 403);
        }

        config(['app.current_lab_id' => $laboratory->id]);
        config(['app.allowed_lab_ids' => array_values($finalAllowedContext)]);
        config(['app.lab_profile' => LaboratoryProfileService::resolve($laboratory)]);

        return $next($request);
    }
}
