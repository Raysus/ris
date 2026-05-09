<?php

namespace App\Services;

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

        $rutNormalizado = strtoupper(str_replace(['.', ' '], '', $userData['rut']));

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

        Log::info('--- INTENTO DE CREACIÓN EN KEYCLOAK ---');
        Log::info('Payload enviado:', $keycloakUser);

        $response = Http::withoutVerifying()
            ->withToken($token)
            ->post("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users", $keycloakUser);

        Log::info('Status Keycloak: ' . $response->status());
        Log::info('---------------------------------------');

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
    public function updateUser($username, $password, $email, $roleName)
    {
        $token = $this->getAdminToken();

        // 1. Buscar si el usuario ya existe
        $searchResponse = Http::withoutVerifying()
            ->withToken($token)
            ->get("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users", [
                'username' => $username,
                'exact' => true
            ]);

        $users = $searchResponse->json();
        $userId = null;

        if (!empty($users)) {
            // EL USUARIO EXISTE: Obtenemos su UUID y lo actualizamos
            $userId = $users[0]['id'];
            $updatePayload = ['email' => $email];

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
        } else {
            // EL USUARIO NO EXISTE: Lo creamos
            if (empty($password)) {
                throw new \Exception("Se requiere una contraseña inicial para crear al usuario en Keycloak.");
            }

            $createPayload = [
                'username' => $username,
                'email' => $email,
                'enabled' => true,
                'credentials' => [
                    [
                        'type' => 'password',
                        'value' => $password,
                        'temporary' => false,
                    ]
                ]
            ];

            $createResponse = Http::withoutVerifying()
                ->withToken($token)
                ->post("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users", $createPayload);

            if ($createResponse->created()) {
                // Volvemos a buscarlo para obtener su UUID interno generado por Keycloak
                $searchResponse2 = Http::withoutVerifying()
                    ->withToken($token)
                    ->get("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users", [
                        'username' => $username,
                        'exact' => true
                    ]);
                $userId = $searchResponse2->json()[0]['id'];
            } else {
                Log::error("Error creando usuario interno en Keycloak", ['body' => $createResponse->body()]);
                throw new \Exception("Error al crear usuario en Keycloak.");
            }
        }

        // 2. Asignar el Rol
        if ($userId && $roleName) {
            $this->assignRealmRole($userId, $roleName);
        }

        return true;
    }

    /**
     * Asigna un Rol de Realm a un usuario específico
     * * @param string $userId (UUID de Keycloak)
     * @param string $roleName
     */
    public function assignRealmRole($userId, $roleName)
    {
        $token = $this->getAdminToken();

        // 1. Obtener la ID técnica del Rol en Keycloak
        $roleResponse = Http::withoutVerifying()
            ->withToken($token)
            ->get("{$this->baseUrl}/admin/realms/{$this->targetRealm}/roles/{$roleName}");

        if (!$roleResponse->successful()) {
            Log::warning("Rol '{$roleName}' no encontrado en Keycloak. Verifique que exista en el Realm.");
            return false;
        }

        $roleData = $roleResponse->json();

        // 2. Mapear el Rol al Usuario
        $assignResponse = Http::withoutVerifying()
            ->withToken($token)
            ->post("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users/{$userId}/role-mappings/realm", [
                [
                    "id" => $roleData['id'],
                    "name" => $roleData['name']
                ]
            ]);

        if (!$assignResponse->successful()) {
            Log::error("Fallo al asignar el rol '{$roleName}' al usuario {$userId}", ['body' => $assignResponse->body()]);
        }

        return $assignResponse->successful();
    }
}