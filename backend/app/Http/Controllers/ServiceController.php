<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    private function getSecureServiceQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Service::query();

        if ($allowedLabs !== ['*']) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('laboratory_id', $allowedLabs);
            }
        }
        return $query;
    }

    public function index(Request $request)
    {
        $services = $this->getSecureServiceQuery()
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $services]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string'
        ]);

        $allowedLabs = config('app.allowed_lab_ids');
        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');

        if (!empty($validated['id'])) {

            $service = $this->getSecureServiceQuery()->findOrFail($validated['id']);

            $service->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null
            ]);

        } else {

            if (!$labId) {
                return response()->json(['success' => false, 'message' => 'Debe seleccionar un laboratorio.'], 400);
            }

            if ($allowedLabs !== ['*'] && !in_array($labId, $allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado. No puede crear servicios en esta sucursal.'], 403);
            }

            $service = Service::create([
                'laboratory_id' => $labId,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null
            ]);
        }

        return response()->json(['success' => true, 'service' => $service]);
    }

    public function destroy($id)
    {
        $service = $this->getSecureServiceQuery()->find($id);

        if ($service) {
            $service->delete();
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Servicio no encontrado o acceso denegado'], 404);
    }
}