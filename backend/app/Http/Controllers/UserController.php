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

        $request->validate([
            'rut' => 'required|string',
            'nombres' => 'required|string|max:255',
            'apellidoPaterno' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'password' => 'nullable|string|min:6',
        ]);

        return DB::transaction(function () use ($request, $allowedLabs) {
            $persona = Persona::updateOrCreate(
                ['rut' => $request->rut],
                [
                    'names' => $request->nombres,
                    'last_name_1' => $request->apellidoPaterno,
                    'last_name_2' => $request->apellidoMaterno,
                ]
            );

            $existingUser = User::where('persona_id', $persona->id)->first();
            $user = User::updateOrCreate(
                ['persona_id' => $persona->id],
                [
                    'username' => $request->username,
                    'medical_title' => $request->titulo,
                    'pacs_ae' => $request->pacsAE,
                    'dragon_profile' => $request->dragonProfile,
                    'tipo_usuario_id' => $request->input('tipo_usuario_id'),
                ]
            );

            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
            }

            $rolesSeleccionados = $request->input('roles', []);
            $user->settings = ['roles' => $rolesSeleccionados];
            $user->is_active = true;
            $user->save();

            // === 🔥 AJUSTE EXACTO: MAPEO DE ROLES KEYCLOAK 🔥 ===
            $keycloakRole = 'user'; // Rol por defecto
            if (collect($rolesSeleccionados)->intersect(['admin', 'secretaria', 'transcriptor'])->isNotEmpty()) {
                $keycloakRole = 'admin';
            } elseif (in_array('radiologo', $rolesSeleccionados)) {
                $keycloakRole = 'medico';
            } elseif (in_array('tecnologo', $rolesSeleccionados)) {
                $keycloakRole = 'tecnologo';
            } elseif (in_array('derivante', $rolesSeleccionados)) {
                $keycloakRole = 'medico_solicitante';
            }

            // Sincronización con Keycloak incluyendo el rol mapeado
            if (config('app.env') !== 'local' && $request->filled('password')) {
                // Se envía el $keycloakRole como parámetro adicional al servicio
                $this->keycloakService->updateUser($user->username, $request->password, $user->email, $keycloakRole);
            }
            // === FIN AJUSTE KEYCLOAK ===

            // Gestión de laboratorios (Sucursales)
            if ($request->has('laboratories')) {
                $labIds = $request->input('laboratories', []);
                $syncData = [];
                foreach ($labIds as $index => $labId) {
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

            // Sincronización a la nube vía Redis
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Persona', 'updated', $persona->toArray());
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\User', 'updated', $user->makeVisible(['password'])->toArray());

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