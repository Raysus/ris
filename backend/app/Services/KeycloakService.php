<?php

namespace App\Services;

use App\Models\Persona;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KeycloakService
{
    private $baseUrl;
    private $targetRealm;
    private $adminUser;
    private $adminPassword;

    public function __construct()
    {
        $this->baseUrl = env('KEYCLOAK_BASE_URL');
        // Asegúrate de que el realm sea el correcto, si los médicos usan otro realm, cámbialo en el .env
        $this->targetRealm = env('KEYCLOAK_TARGET_REALM', 'patient-portal');
        $this->adminUser = env('KEYCLOAK_ADMIN_USER');
        $this->adminPassword = env('KEYCLOAK_ADMIN_PASSWORD');
    }

    private function getAdminToken()
    {
        $response = Http::withoutVerifying()->asForm()->post("{$this->baseUrl}/realms/master/protocol/openid-connect/token", [
            'client_id' => 'admin-cli',
            'username' => $this->adminUser,
            'password' => $this->adminPassword,
            'grant_type' => 'password',
        ]);

        if ($response->successful()) {
            return $response->json()['access_token'];
        }

        Log::error('Error obteniendo token con admin-cli', [
            'status' => $response->status(),
            'body' => $response->json()
        ]);

        throw new \Exception('No se pudo autenticar con admin-cli en Keycloak.');
    }

    /**
     * Crea un usuario básico (usado habitualmente para pacientes)
     *
     * @param array $userData 
     * @return bool
     */
    public function createUser($userData)
    {
        $token = $this->getAdminToken();

        $rutNormalizado = Persona::normalizeRut($userData['rut']);

        $keycloakUser = [
            'username' => $rutNormalizado,
            'email' => $userData['email'] ?? null,
            'firstName' => $userData['nombres'],
            'lastName' => $userData['apellidos'],
            'enabled' => true,
            'emailVerified' => true,
            'credentials' => [
                [
                    'type' => 'password',
                    'value' => $userData['password'],
                    'temporary' => true,
                ]
            ],
            'attributes' => [
                'rut' => [$rutNormalizado],
            ]
        ];

        Log::info('Keycloak createUser', [
            'username' => $rutNormalizado,
            'realm' => $this->targetRealm,
        ]);

        $response = Http::withoutVerifying()
            ->withToken($token)
            ->post("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users", $keycloakUser);

        Log::info('Keycloak createUser status: ' . $response->status());

        if ($response->created()) {
            return true;
        }

        $errorMessage = $response->json()['errorMessage'] ?? 'Error desconocido';
        throw new \Exception("Keycloak rechazó la creación (Status {$response->status()}): " . $errorMessage);
    }

    /**
     * Crea o actualiza un usuario interno y le asigna su rol (Usado desde UserController)
     * * @param string $username
     * @param string|null $password
     * @param string|null $email
     * @param string $roleName
     */
    /** @return list<string> Roles realm de Keycloak para personal del portal (no paciente). */
    public static function mapRisRolesToKeycloak(array $risRoles): array
    {
        $roles = [];

        if (collect($risRoles)->intersect(['admin', 'secretaria', 'secretario', 'sis_admin'])->isNotEmpty()) {
            $roles[] = 'admin';
        }
        if (collect($risRoles)->intersect(['secretaria', 'secretario'])->isNotEmpty()) {
            $roles[] = 'secretaria';
        }
        if (in_array('radiologo', $risRoles, true)) {
            $roles[] = 'medico';
        }
        if (in_array('tecnologo', $risRoles, true)) {
            $roles[] = 'tecnologo';
        }
        if (in_array('derivante', $risRoles, true)) {
            $roles[] = 'medico_solicitante';
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param  array{
     *   firstName?: string,
     *   lastName?: string,
     *   sendSetupEmail?: bool,
     *   legacyUsername?: string,
     *   risRoles?: array<int, string>,
     *   portalLabId?: int|string|null,
     *   portalSiteFilter?: string|null,
     * }  $options
     */
    public function updateUser($username, $password, $email, $roleName, array $options = [])
    {
        $token = $this->getAdminToken();
        $firstName = $options['firstName'] ?? null;
        $lastName = $options['lastName'] ?? null;
        $sendSetupEmail = (bool) ($options['sendSetupEmail'] ?? false);
        $legacyUsername = $options['legacyUsername'] ?? null;
        $keycloakRoles = !empty($options['risRoles'])
            ? self::mapRisRolesToKeycloak($options['risRoles'])
            : array_filter([$roleName]);
        $portalAttributes = $this->buildPortalAttributes($options);

        // Keycloak usa RUT normalizado (sin puntos), igual que pacientes y portal.
        $keycloakUsername = Persona::normalizeRut((string) $username);

        // 1. Buscar si el usuario ya existe (por RUT o por username legacy del RIS)
        $searchResponse = Http::withoutVerifying()
            ->withToken($token)
            ->get("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users", [
                'username' => $keycloakUsername,
                'exact' => true
            ]);

        $users = $searchResponse->json();
        $userId = null;

        if (empty($users) && filled($legacyUsername) && $legacyUsername !== $keycloakUsername) {
            $legacySearch = Http::withoutVerifying()
                ->withToken($token)
                ->get("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users", [
                    'username' => $legacyUsername,
                    'exact' => true,
                ]);
            $users = $legacySearch->json();
        }

        if (!empty($users)) {
            // EL USUARIO EXISTE: Obtenemos su UUID y lo actualizamos
            $userId = $users[0]['id'];
            $existingUsername = (string) ($users[0]['username'] ?? '');

            if ($existingUsername !== $keycloakUsername) {
                // Keycloak suele tener deshabilitado "Edit username": recrear con RUT.
                $deleteResponse = Http::withoutVerifying()
                    ->withToken($token)
                    ->delete("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users/{$userId}");

                if (!$deleteResponse->successful()) {
                    Log::error('No se pudo eliminar usuario legacy en Keycloak', [
                        'legacyUsername' => $existingUsername,
                        'targetUsername' => $keycloakUsername,
                        'body' => $deleteResponse->body(),
                    ]);
                    throw new \Exception('No se pudo migrar el usuario legacy en Keycloak.');
                }

                $users = [];
            } else {
                $updatePayload = array_filter([
                    'email' => $email,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'attributes' => array_merge(
                        ['rut' => [$keycloakUsername]],
                        $portalAttributes
                    ),
                ], fn ($v) => $v !== null && $v !== '');

                if (!empty($password)) {
                    $updatePayload['credentials'] = [
                        [
                            'type' => 'password',
                            'value' => $password,
                            'temporary' => false,
                        ]
                    ];
                }

                $updateResponse = Http::withoutVerifying()
                    ->withToken($token)
                    ->put("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users/{$userId}", $updatePayload);

                if (!$updateResponse->successful()) {
                    Log::error("Error actualizando usuario en Keycloak", ['body' => $updateResponse->body()]);
                }
            }
        }

        if (empty($users)) {
            // EL USUARIO NO EXISTE: Lo creamos
            if (empty($password)) {
                $password = bin2hex(random_bytes(8));
                $sendSetupEmail = $sendSetupEmail || filled($email);
            }

            $createPayload = array_filter([
                'username' => $keycloakUsername,
                'email' => $email,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'enabled' => true,
                'emailVerified' => empty($email),
                'attributes' => array_merge(
                    ['rut' => [$keycloakUsername]],
                    $portalAttributes
                ),
                'credentials' => [
                    [
                        'type' => 'password',
                        'value' => $password,
                        'temporary' => (bool) $sendSetupEmail,
                    ],
                ],
            ], fn ($v) => $v !== null);

            $createResponse = Http::withoutVerifying()
                ->withToken($token)
                ->post("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users", $createPayload);

            if ($createResponse->created()) {
                $createdInKeycloak = true;
                // Volvemos a buscarlo para obtener su UUID interno generado por Keycloak
                $searchResponse2 = Http::withoutVerifying()
                    ->withToken($token)
                    ->get("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users", [
                        'username' => $keycloakUsername,
                        'exact' => true
                    ]);
                $userId = $searchResponse2->json()[0]['id'];
            } else {
                Log::error("Error creando usuario interno en Keycloak", ['body' => $createResponse->body()]);
                throw new \Exception("Error al crear usuario en Keycloak.");
            }
        }

        // 2. Asignar roles realm (portal lee realm_access.roles del JWT)
        if ($userId && $keycloakRoles !== []) {
            $this->assignRealmRoles($userId, $keycloakRoles, $token);
        }

        // 3. Enviar correo de Keycloak (actualizar contraseña / verificar email)
        if ($userId && $sendSetupEmail && filled($email)) {
            $this->sendExecuteActionsEmail($userId, $token);
        }

        return true;
    }

    /**
     * Dispara el envío de correo de Keycloak (execute-actions-email).
     */
    public function sendExecuteActionsEmail(string $userId, ?string $token = null): bool
    {
        $token = $token ?? $this->getAdminToken();
        $clientId = env('KEYCLOAK_CLIENT_ID', 'patient-portal');
        $lifespan = (int) env('KEYCLOAK_ACTION_EMAIL_LIFESPAN', 43200);

        $actions = ['UPDATE_PASSWORD'];
        if (filter_var(env('KEYCLOAK_SEND_VERIFY_EMAIL', true), FILTER_VALIDATE_BOOLEAN)) {
            $actions[] = 'VERIFY_EMAIL';
        }

        $url = "{$this->baseUrl}/admin/realms/{$this->targetRealm}/users/{$userId}/execute-actions-email"
            . '?client_id=' . urlencode($clientId)
            . '&lifespan=' . $lifespan;

        $response = Http::withoutVerifying()
            ->withToken($token)
            ->withBody(json_encode($actions), 'application/json')
            ->put($url);

        if (!$response->successful()) {
            Log::warning('Keycloak execute-actions-email falló', [
                'userId' => $userId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        Log::info('Keycloak: correo de acciones enviado', ['userId' => $userId, 'actions' => $actions]);

        return true;
    }

    /**
     * Asigna un Rol de Realm a un usuario específico
     * * @param string $userId (UUID de Keycloak)
     * @param string $roleName
     */
    public function assignRealmRole($userId, $roleName)
    {
        return $this->assignRealmRoles($userId, [$roleName]);
    }

    /** @param list<string> $roleNames */
    public function assignRealmRoles($userId, array $roleNames, ?string $token = null): bool
    {
        $token = $token ?? $this->getAdminToken();
        $rolePayload = [];

        foreach (array_values(array_unique(array_filter($roleNames))) as $roleName) {
            $roleResponse = Http::withoutVerifying()
                ->withToken($token)
                ->get("{$this->baseUrl}/admin/realms/{$this->targetRealm}/roles/{$roleName}");

            if (!$roleResponse->successful()) {
                Log::warning("Rol '{$roleName}' no encontrado en Keycloak.");
                continue;
            }

            $roleData = $roleResponse->json();
            $rolePayload[] = [
                'id' => $roleData['id'],
                'name' => $roleData['name'],
            ];
        }

        if ($rolePayload === []) {
            return false;
        }

        $assignResponse = Http::withoutVerifying()
            ->withToken($token)
            ->post("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users/{$userId}/role-mappings/realm", $rolePayload);

        if (!$assignResponse->successful()) {
            Log::error("Fallo al asignar roles Keycloak al usuario {$userId}", [
                'roles' => $roleNames,
                'body' => $assignResponse->body(),
            ]);
        }

        return $assignResponse->successful();
    }

  /** Atributos que el portal lee del JWT (protocol mappers lab_id / site_filter). */
    private function buildPortalAttributes(array $options): array
    {
        $attrs = [];

        if (array_key_exists('portalLabId', $options) && $options['portalLabId'] !== null && $options['portalLabId'] !== '') {
            $attrs['lab_id'] = [(string) $options['portalLabId']];
        }
        if (!empty($options['portalSiteFilter'])) {
            $attrs['site_filter'] = [(string) $options['portalSiteFilter']];
        }

        return $attrs;
    }
}