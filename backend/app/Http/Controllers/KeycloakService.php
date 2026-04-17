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
        $response = Http::asForm()->post("{$this->baseUrl}/realms/master/protocol/openid-connect/token", [
            'client_id' => 'admin-cli',
            'username' => $this->adminUser,
            'password' => $this->adminPassword,
            'grant_type' => 'password',
        ]);

        if ($response->successful()) {
            return $response->json()['access_token'];
        }

        Log::error('Error obteniendo token con admin-cli', $response->json());
        throw new \Exception('No se pudo autenticar con admin-cli en Keycloak');
    }

    public function createUser($userData)
    {
        $token = $this->getAdminToken();

        $keycloakUser = [
            'username' => $userData['username'],
            'email' => $userData['email'] ?? null,
            'firstName' => $userData['nombres'],
            'lastName' => $userData['apellidos'],
            'enabled' => true,
            'emailVerified' => true,
            'credentials' => [
                [
                    'type' => 'password',
                    'value' => $userData['password'],
                    'temporary' => false,
                ]
            ],
            'attributes' => [
                'rut' => [$userData['rut']],
            ]
        ];

        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/admin/realms/{$this->targetRealm}/users", $keycloakUser);

        if ($response->created()) {
            $location = $response->header('Location');
            return basename($location);
        }

        Log::error('Error creando usuario', $response->json());
        throw new \Exception('Error al crear usuario: ' . json_encode($response->json()));
    }
}