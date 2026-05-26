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
        $isSysAdmin = $user->hasFullLabAccess();

        if ($isSysAdmin && (!$labId || $labId === 'ALL' || $labId === '')) {
            config(['app.current_lab_id' => null]);
            config(['app.allowed_lab_ids' => ['*']]);
            config(['app.lab_profile' => LaboratoryProfileService::profileForCode('clinical')]);
            return $next($request);
        }

        $allowedIds = Laboratory::resolveAllowedLabIdsForUser($user);

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
