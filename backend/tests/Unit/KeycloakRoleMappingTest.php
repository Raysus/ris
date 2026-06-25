<?php

namespace Tests\Unit;

use App\Services\KeycloakService;
use PHPUnit\Framework\TestCase;

class KeycloakRoleMappingTest extends TestCase
{
    public function test_secretario_maps_to_portal_staff_roles(): void
    {
        $mapped = KeycloakService::mapRisRolesToKeycloak(['recepcion', 'secretario']);

        $this->assertSame(['admin', 'secretaria'], $mapped);
    }

    public function test_recepcion_alone_does_not_grant_portal_staff(): void
    {
        $this->assertSame([], KeycloakService::mapRisRolesToKeycloak(['recepcion']));
    }

    public function test_admin_maps_to_portal_admin(): void
    {
        $this->assertSame(['admin'], KeycloakService::mapRisRolesToKeycloak(['admin']));
    }

    public function test_radiologo_and_tecnologo_map_correctly(): void
    {
        $this->assertSame(['medico'], KeycloakService::mapRisRolesToKeycloak(['radiologo']));
        $this->assertSame(['tecnologo'], KeycloakService::mapRisRolesToKeycloak(['tecnologo']));
    }

    public function test_legacy_secretaria_still_maps_for_existing_users(): void
    {
        $this->assertSame(['admin', 'secretaria'], KeycloakService::mapRisRolesToKeycloak(['secretaria']));
    }
}
