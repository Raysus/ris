<?php

namespace App\Http\Controllers;

use App\Models\Laboratory;
use App\Models\LaboratoryType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function getAllLaboratories(Request $request)
    {
        $user = $request->user();

        if ($user->tipoUsuario->name === 'sis_admin') {
            $labs = Laboratory::whereNull('parent_id')->with('children')->get();
        } else {
            $assignedLabIds = $user->laboratories()->pluck('laboratories.id')->toArray();

            $matrizIds = Laboratory::whereIn('id', $assignedLabIds)->whereNull('parent_id')->pluck('id')->toArray();
            $parentIds = Laboratory::whereIn('id', $assignedLabIds)->whereNotNull('parent_id')->pluck('parent_id')->toArray();

            $accessibleMatrizIds = array_unique(array_merge($matrizIds, $parentIds));

            $roleName = $user->tipoUsuario->name;
            $matrizAsignadaIds = $matrizIds;

            if ($roleName === 'admin') {
                $labs = Laboratory::whereIn('id', $accessibleMatrizIds)
                    ->with('children')
                    ->get();
            } else {
                $labs = Laboratory::whereIn('id', $accessibleMatrizIds)
                    ->with([
                        'children' => function ($query) use ($assignedLabIds, $matrizAsignadaIds, $roleName) {
                            $query->where(function ($q) use ($assignedLabIds, $matrizAsignadaIds, $roleName) {
                                $q->whereIn('id', $assignedLabIds);
                                if (
                                    !empty($matrizAsignadaIds)
                                    && Laboratory::supportsMultiSiteView($roleName)
                                ) {
                                    $q->orWhereIn('parent_id', $matrizAsignadaIds);
                                }
                            });
                        },
                    ])
                    ->get();
            }
        }

        return response()->json(['success' => true, 'data' => $labs]);
    }

    public function getSettings()
    {
        $labId = config('app.current_lab_id');
        $lab = Laboratory::find($labId);
        $user = auth()->user();

        $query = Laboratory::where('parent_id', $labId);

        if (!in_array($user->tipoUsuario->name, ['sis_admin', 'admin'])) {
            $assignedLabIds = $user->laboratories()->pluck('laboratories.id')->toArray();
            $query->whereIn('id', $assignedLabIds);
        }

        $children = $query->get();
        $types = \DB::table('laboratory_types')->select('id', 'name')->get();

        return response()->json([
            'success' => true,
            'data' => $lab,
            'children' => $children,
            'lab_types' => $types
        ]);
    }

    public function updateSettings(Request $request)
    {
        if (!in_array($request->user()->tipoUsuario->name, ['sis_admin', 'admin'])) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado. Se requieren permisos de Administrador.'], 403);
        }

        $lab = Laboratory::find(config('app.current_lab_id'));

        if ($request->has('name'))
            $lab->name = $request->name;
        if ($request->has('address'))
            $lab->address = $request->address;
        if ($request->has('city'))
            $lab->city = $request->city;
        if ($request->has('phone'))
            $lab->phone = $request->phone;
        if ($request->has('email'))
            $lab->email = $request->email;

        if ($request->has('laboratory_type_id')) {
            $lab->laboratory_type_id = $request->laboratory_type_id;
        }

        if ($request->hasFile('logo')) {
            if ($lab->logo_path) {
                Storage::disk('public')->delete($lab->logo_path);
            }
            $lab->logo_path = $request->file('logo')->store('logos', 'public');
        }

        if ($request->has('settings')) {
            $lab->settings = $request->settings;
        }

        $lab->save();

        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Laboratory', 'updated', $lab->toArray());

        return response()->json(['success' => true, 'data' => $lab]);
    }

    public function storeBranch(Request $request)
    {
        if (!in_array($request->user()->tipoUsuario->name, ['sis_admin', 'admin'])) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        $parentId = config('app.current_lab_id');

        if ($request->id) {
            $branch = Laboratory::where('parent_id', $parentId)->findOrFail($request->id);
        } else {
            $branch = new Laboratory();
            $branch->settings = [];
        }

        $branch->parent_id = $parentId;
        $branch->name = $request->name;
        $branch->address = $request->address;
        $branch->city = $request->city;
        $branch->phone = $request->phone;
        $branch->is_active = $request->is_active ?? true;
        $branch->laboratory_type_id = $request->laboratory_type_id ?? 2;

        $branch->save();
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Laboratory', 'updated', $branch->toArray());
        return response()->json(['success' => true, 'branch' => $branch]);
    }

    public function destroyBranch(Request $request, $id)
    {
        if (!in_array($request->user()->tipoUsuario->name, ['sis_admin', 'admin'])) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        $branch = Laboratory::where('parent_id', config('app.current_lab_id'))->find($id);

        if ($branch) {
            $branch->delete();
            return response()->json(['success' => true]);
        }
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Laboratory', 'deleted', ['id' => $id]);
        return response()->json(['success' => false, 'message' => 'Sucursal no encontrada o no pertenece a esta clínica'], 404);
    }

    public function storeLaboratory(Request $request)
    {
        if ($request->user()->tipoUsuario->name !== 'sis_admin') {
            return response()->json(['success' => false, 'message' => 'No tienes permisos para crear nuevas matrices.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'laboratory_type_id' => 'required|string'
        ]);

        $lab = new \App\Models\Laboratory();
        $lab->name = $request->name;
        $lab->laboratory_type_id = $request->laboratory_type_id;
        $lab->address = $request->address;
        $lab->city = $request->city;
        $lab->phone = $request->phone;
        $lab->parent_id = null;

        $lab->settings = [
            'horaInicio' => '08:00:00',
            'horaFin' => '20:00:00',
            'intervalo' => '00:15:00',
            'colorInforme' => '#000000'
        ];

        $lab->save();

        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Laboratory', 'created', $lab->toArray());

        return response()->json(['success' => true, 'data' => $lab]);
    }
}