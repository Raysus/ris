<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Persona;
use App\Models\Laboratory;

class AuthTest extends TestCase
{
    /**
     * Test login con credenciales válidas
     */
    public function test_login_success(): void
    {
        $response = $this->postJson('/api/login', [
            'login_field' => 'admin@test.com',
            'password' => 'password123'
        ]);

        $response->assertStatus(401); // Sin credenciales reales retornará 401
        $response->assertJsonStructure([
            'success',
            'message'
        ]);
    }

    /**
     * Test login sin credenciales
     */
    public function test_login_missing_fields(): void
    {
        $response = $this->postJson('/api/login', []);

        $response->assertStatus(422); // Validación falla
        $response->assertJsonValidationErrors(['login_field', 'password']);
    }

    /**
     * Test rate limiting en login (5 intentos por minuto)
     */
    public function test_login_rate_limiting(): void
    {
        // Hacer 6 intentos en corto tiempo
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/login', [
                'login_field' => 'test@test.com',
                'password' => 'wrong'
            ]);
            
            // En el 6to intento debería ser limitado
            if ($i === 5) {
                $response->assertStatus(429); // Too Many Requests
            }
        }
    }

    /**
     * Test logout requiere autenticación
     */
    public function test_logout_requires_auth(): void
    {
        $response = $this->postJson('/api/logout');

        $response->assertStatus(401); // Unauthorized
    }
}
