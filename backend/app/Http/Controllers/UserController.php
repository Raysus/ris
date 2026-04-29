<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Persona;
use App\Services\KeycloakService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    protected $keycloakService;

    public function __construct(KeycloakService $keycloakService)
    {
        $this->keycloakService = $keycloakService;
    }

    private function getSecureUserQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');

        // 🔥 CAMBIAR ESTA LÍNEA
        $query = User::with(['persona', 'tipoUsuario', 'laboratories'])
            ->where('username', '!=', 'admin'); // <-- Antes era ->where('id', '!=', 1)

        if ($allowedLabs !== ['*']) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('laboratories', function ($q) use ($allowedLabs) {
                    $q->whereIn('laboratories.id', $allowedLabs);
                });
            }
        }
        return $query;
    }

    public function index()
    {
        $users = $this->getSecureUserQuery()->get();
        return response()->json(['success' => true, 'data' => $users]);
    }

    public function store(Request $request)
    {
        $allowedLabs = config('app.allowed_lab_ids');

        if ($allowedLabs !== ['*'] && $request->has('laboratories')) {
            $unauthorizedLabs = array_diff($request->laboratories, $allowedLabs);
            if (!empty($unauthorizedLabs)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acceso denegado. Intenta asignar una sucursal fuera de su jurisdicción.'
                ], 403);
            }
        }

        return DB::transaction(function () use ($request, $allowedLabs) {

            $roleToTypeId = [
                'admin' => 2,
                'recepcion' => 3,
                'tecnologo' => 4,
                'radiologo' => 5,
                'transcriptor' => 6,
                'auxiliar' => 7,
                'secretario' => 8,
            ];

            $persona = Persona::updateOrCreate(
                ['rut' => $request->rut],
                [
                    'names' => $request->nombres,
                    'last_name_1' => $request->apellidoPaterno,
                    'last_name_2' => $request->apellidoMaterno,
                ]
            );

            $primerRol = $request->roles[0] ?? 'recepcion';
            $tipoUsuarioId = $roleToTypeId[$primerRol] ?? 3;

            $userData = [
                'tipo_usuario_id' => $tipoUsuarioId,
                'username' => $request->username,
                'medical_title' => $request->titulo,
                'pacs_ae' => $request->pacsAE,
                'dragon_profile' => $request->dragonProfile,
                'settings' => ['roles' => $request->roles ?? []],
                'is_active' => true
            ];

            if ($request->filled('password')) {
                $userData['password'] = Hash::make($request->password);
            }

            $existingUser = User::where('persona_id', $persona->id)->first();

            if (!$existingUser) {
                if (!$request->filled('password')) {
                    throw new \Exception("La contraseña es obligatoria para crear un nuevo usuario.");
                }

                $keycloakId = $this->keycloakService->createUser([
                    'username' => $request->username,
                    'nombres' => $request->nombres,
                    'apellidos' => trim($request->apellidoPaterno . ' ' . $request->apellidoMaterno),
                    'password' => $request->password,
                    'rut' => $request->rut,
                    'email' => $request->email ?? null,
                ]);

                // Opcional: si tu tabla 'users' tiene un campo 'keycloak_id', guárdalo.
                // $userData['keycloak_id'] = $keycloakId; 
            }

            if ($request->hasFile('signature')) {
                if ($existingUser && $existingUser->signature_path) {
                    Storage::disk('public')->delete($existingUser->signature_path);
                }
                $userData['signature_path'] = $request->file('signature')->store('signatures', 'public');
            }

            $user = User::updateOrCreate(
                ['persona_id' => $persona->id],
                $userData
            );

            if ($request->has('laboratories')) {
                $syncData = [];

                foreach ($request->laboratories as $index => $labId) {
                    $syncData[$labId] = ['is_primary' => ($index === 0)];
                }

                if ($allowedLabs !== ['*'] && $existingUser) {
                    $existingLabs = $existingUser->laboratories()->pluck('laboratories.id')->toArray();
                    $labsOutsideScope = array_diff($existingLabs, $allowedLabs);

                    foreach ($labsOutsideScope as $outLabId) {
                        $syncData[$outLabId] = ['is_primary' => false];
                    }
                }

                $user->laboratories()->sync($syncData);
            }

            $user->load('persona', 'tipoUsuario', 'laboratories');

            return response()->json(['success' => true, 'user' => $user]);
        });
    }

    public function destroy($id)
    {
        $user = $this->getSecureUserQuery()->findOrFail($id);

        $user->is_active = false;
        $user->save();
        $user->delete();

        return response()->json(['success' => true]);
    }

    public function getRoles()
    {
        $roles = \App\Models\TipoUsuario::where('name', '!=', 'sis_admin')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $roles]);
    }
}