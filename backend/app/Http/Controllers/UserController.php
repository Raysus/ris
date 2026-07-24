<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksRisAuthorization;
use App\Models\User;
use App\Models\Persona;
use App\Services\KeycloakService;
use App\Jobs\RelayUserBundleToLocalLab;
use App\Jobs\SyncUserBundleToCloud;
use App\Support\CloudSyncMode;
use App\Observers\AppointmentObserver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    use ChecksRisAuthorization;

    protected $keycloakService;

    public function __construct(KeycloakService $keycloakService)
    {
        $this->keycloakService = $keycloakService;
    }

    private function getSecureUserQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');

        $query = User::with(['persona', 'tipoUsuario', 'laboratories'])
            ->where('username', '!=', 'admin')
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

    public function index(Request $request)
    {
        $this->assertAdmin($request);
        $users = $this->getSecureUserQuery()->get();
        return response()->json(['success' => true, 'data' => $users]);
    }

    public function store(Request $request)
    {
        $this->assertAdmin($request);
        $allowedLabs = config('app.allowed_lab_ids');

        $personaForUpdate = Persona::findByRut($request->rut);
        $existingByPersona = $personaForUpdate
            ? User::where('persona_id', $personaForUpdate->id)->first()
            : null;
        $isUpdate = $request->filled('id') || $existingByPersona !== null;

        $request->validate([
            'rut' => 'required|string',
            'nombres' => 'required|string|max:255',
            'apellidoPaterno' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'username' => 'required|string|max:255',
            'password' => $isUpdate ? 'nullable|string|min:8' : 'required|string|min:8',
        ], [
            'rut.required' => 'El RUT es obligatorio.',
            'nombres.required' => 'Los nombres son obligatorios.',
            'apellidoPaterno.required' => 'El apellido paterno es obligatorio.',
            'username.required' => 'El nombre de usuario es obligatorio.',
            'email.email' => 'El correo electrónico no es válido.',
            'password.required' => 'La contraseña es obligatoria para usuarios nuevos.',
            'password.min.string' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        AppointmentObserver::$suppressRelatedSync = true;

        $userAction = 'updated';

        try {
            [$user, $persona, $userAction] = DB::transaction(function () use ($request, $allowedLabs) {
            $email = $request->filled('email')
                ? strtolower(trim((string) $request->email))
                : null;

            $persona = Persona::upsertByRut($request->rut, [
                'names' => $request->nombres,
                'last_name_1' => $request->apellidoPaterno,
                'last_name_2' => $request->apellidoMaterno,
                'email' => $email,
            ]);

            // 🔥 CORRECCIÓN: Rescatamos el tipo de usuario desde el arreglo de roles
            $rolesSeleccionados = $request->input('roles', []);

            if (in_array('sis_admin', $rolesSeleccionados, true)) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'No se puede asignar el rol Sys. Admin desde la interfaz.',
                ], 422));
            }

            $primerRol = $rolesSeleccionados[0] ?? 'recepcion';

            // Buscar en BD el ID técnico del rol
            $tipoUsuario = \App\Models\TipoUsuario::where('name', $primerRol)->first();

            $existingUser = User::where('persona_id', $persona->id)->first();
            $userAction = $existingUser ? 'updated' : 'created';

            $userAttributes = [
                'username' => $request->username,
                'medical_title' => $request->titulo,
                'dragon_profile' => $request->dragonProfile,
                'tipo_usuario_id' => $tipoUsuario ? $tipoUsuario->id : null,
            ];

            if ($request->filled('password')) {
                $userAttributes['password'] = $request->password;
            }

            $user = User::updateOrCreate(
                ['persona_id' => $persona->id],
                $userAttributes
            );

            $user->settings = ['roles' => $rolesSeleccionados];
            $user->is_active = true;

            if ($request->hasFile('signature')) {
                $request->validate([
                    'signature' => 'image|mimes:png,jpeg,jpg|max:4096',
                ]);
                if ($user->signature_path) {
                    Storage::disk('public')->delete($user->signature_path);
                }
                $user->signature_path = $request->file('signature')->store('signatures', 'public');
            }

            $user->save();

            // === MAPEO DE ROLES KEYCLOAK ===
            $keycloakRole = 'user'; // compat legacy (un solo rol)
            if (collect($rolesSeleccionados)->intersect(['admin', 'secretario', 'sis_admin'])->isNotEmpty()) {
                $keycloakRole = 'admin';
            } elseif (in_array('radiologo', $rolesSeleccionados)) {
                $keycloakRole = 'medico';
            } elseif (in_array('tecnologo', $rolesSeleccionados)) {
                $keycloakRole = 'tecnologo';
            } elseif (in_array('derivante', $rolesSeleccionados)) {
                $keycloakRole = 'medico_solicitante';
            }

            [$portalLabId, $portalSiteFilter] = [null, null];

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
            [$portalLabId, $portalSiteFilter] = $this->resolvePortalAccessForUser($user);

            // Sincronización con Keycloak (rol, credenciales y EMAIL).
            if (config('app.env') !== 'local' && $this->shouldSyncKeycloak()
                && ($request->filled('password') || filled($email))) {
                try {
                    $this->keycloakService->updateUser(
                        $persona->rut,
                        $request->password,
                        $email,
                        $keycloakRole,
                        [
                            'firstName' => $request->nombres,
                            'lastName' => trim($request->apellidoPaterno . ' ' . ($request->apellidoMaterno ?? '')),
                            'legacyUsername' => $user->username,
                            'risRoles' => $rolesSeleccionados,
                            'portalLabId' => $portalLabId,
                            'portalSiteFilter' => $portalSiteFilter,
                        ]
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        'Sync de usuario/email a Keycloak falló: ' . $e->getMessage()
                    );
                }
            }
            // === FIN AJUSTE KEYCLOAK ===

            return [$user, $persona, $userAction];
            });
        } finally {
            AppointmentObserver::$suppressRelatedSync = false;
        }

        if (CloudSyncMode::isCloud()) {
            RelayUserBundleToLocalLab::dispatch($user->id, $userAction);
        } elseif (CloudSyncMode::canPushToCloud()) {
            SyncUserBundleToCloud::dispatch($user->id, $userAction);
        }

        return response()->json([
            'success' => true,
            'user' => $user,
            'keycloak_synced' => $this->shouldSyncKeycloak(),
        ]);
    }
    public function destroy(Request $request, $id)
    {
        $this->assertAdmin($request);
        $user = $this->getSecureUserQuery()->findOrFail($id);

        $user->is_active = false;
        $user->save();
        $user->delete();

        return response()->json(['success' => true]);
    }

    public function getRoles(Request $request)
    {
        $this->assertAdmin($request);
        $roles = \App\Models\TipoUsuario::assignableQuery()
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $roles]);
    }

    private function shouldSyncKeycloak(): bool
    {
        return filled(env('KEYCLOAK_BASE_URL'))
            && filled(env('KEYCLOAK_ADMIN_USER'))
            && filled(env('KEYCLOAK_ADMIN_PASSWORD'));
    }

    /**
     * Mapeo sede RIS → atributos del portal (Keycloak lab_id / site_filter).
     *
     * @return array{0: int|string|null, 1: string|null}
     */
    private function resolvePortalAccessForUser(User $user): array
    {
        $user->loadMissing('laboratories');
        $labName = strtoupper((string) ($user->laboratories->first()?->name ?? ''));

        if (str_contains($labName, 'SIRESA')) {
            return [5, 'SIRESA'];
        }

        if (str_contains($labName, 'ECOTEMUCO')) {
            return [6, 'IMEX TEMUCO'];
        }

        return [null, null];
    }
}