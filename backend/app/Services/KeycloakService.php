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
     *
     * @param array $userData 
     * @return string
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

        \Log::info('--- INTENTO DE CREACIÓN EN KEYCLOAK ---');
        \Log::info('Payload enviado:', $keycloakUser);

        $response = \Illuminate\Support\Facades\Http::withoutVerifying()
            ->withToken($token)
            ->post("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users", $keycloakUser);

        \Log::info('Status Keycloak: ' . $response->status());
        \Log::info('Respuesta Keycloak: ' . $response->body());
        \Log::info('---------------------------------------');

        if ($response->created()) {
            return true;
        }

        $errorMessage = $response->json()['errorMessage'] ?? 'Error desconocido';
        throw new \Exception("Keycloak rechazó la creación (Status {$response->status()}): " . $errorMessage);
    }

    /**
     * * @param string
     * @param string $roleName
     */
    public function assignRealmRole($userId, $roleName)
    {
        $token = $this->getAdminToken();

        $roleResponse = Http::withToken($token)
            ->get("{$this->baseUrl}/admin/realms/{$this->targetRealm}/roles/{$roleName}");

        if (!$roleResponse->successful()) {
            Log::warning("Rol '{$roleName}' no encontrado en Keycloak.");
            return false;
        }

        $roleData = $roleResponse->json();

        $assignResponse = Http::withToken($token)
            ->post("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users/{$userId}/role-mappings/realm", [
                [
                    "id" => $roleData['id'],
                    "name" => $roleData['name']
                ]
            ]);

        return $assignResponse->successful();
    }
}