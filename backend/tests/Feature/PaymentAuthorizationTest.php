<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class PaymentAuthorizationTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis();
    }

    public function test_payment_breakdown_denied_without_financial_role(): void
    {
        Sanctum::actingAs($this->createRoleTestUser('radiologo'));
        $appointment = $this->createTestAppointment();

        $this->withHeaders(['X-Lab-Id' => $this->risLab->id, 'Accept' => 'application/json'])
            ->getJson("/api/payments/breakdown/{$appointment->id}")
            ->assertForbidden();
    }

    public function test_payment_breakdown_allowed_for_admin(): void
    {
        Sanctum::actingAs(User::where('username', 'rgutierrez')->firstOrFail());
        $appointment = $this->createTestAppointment();

        $this->withHeaders(['X-Lab-Id' => $this->risLab->id, 'Accept' => 'application/json'])
            ->getJson("/api/payments/breakdown/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
