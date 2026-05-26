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

        $query = User::with(['persona', 'tipoUsuario', 'laboratories'])
            ->whereHas('tipoUsuario', fn ($q) => $q->where('name', '!=', 'sis_admin'));

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
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
            $persona = Persona::upsertByRut($request->rut, [
                'names' => $request->nombres,
                'last_name_1' => $request->apellidoPaterno,
                'last_name_2' => $request->apellidoMaterno,
            ]);

            // 🔥 CORRECCIÓN: Rescatamos el tipo de usuario desde el arreglo de roles
            $rolesSeleccionados = $request->input('roles', []);

            if (in_array('sis_admin', $rolesSeleccionados, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede asignar el rol Sys. Admin desde la interfaz.',
                ], 422);
            }

            $primerRol = $rolesSeleccionados[0] ?? 'recepcion';

            // Buscar en BD el ID técnico del rol
            $tipoUsuario = \App\Models\TipoUsuario::where('name', $primerRol)->first();

            $existingUser = User::where('persona_id', $persona->id)->first();
            $user = User::updateOrCreate(
                ['persona_id' => $persona->id],
                [
                    'username' => $request->username,
                    'medical_title' => $request->titulo,
                    'dragon_profile' => $request->dragonProfile,
                    // Evitamos el "not null violation" inyectando el ID directamente
                    'tipo_usuario_id' => $tipoUsuario ? $tipoUsuario->id : null,
                ]
            );

            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
            }

            $user->settings = ['roles' => $rolesSeleccionados];
            $user->is_active = true;
            $user->save();

            // === MAPEO DE ROLES KEYCLOAK ===
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

                if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs) && $existingUser) {
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

            // 🔥 LE DAMOS 3 SEGUNDOS DE VENTAJA A LA PERSONA PARA QUE LLEGUE PRIMERO
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\User', 'updated', $user->makeVisible(['password'])->toArray())
                ->delay(now()->addSeconds(3));

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