<?php

namespace Tests\Feature;

use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class AppointmentValidationTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeCloudSync();
    }

    public function test_appointment_store_requires_authentication(): void
    {
        $this->postJson('/api/appointments', [
            'data' => json_encode(['machine_id' => 'x']),
        ])->assertUnauthorized();
    }

    public function test_appointment_store_requires_lab_header(): void
    {
        $this->seedRis();
        $this->actingAsRis();

        $this->postJson('/api/appointments', [
            'data' => json_encode(['machine_id' => 'x']),
        ], [
            'Accept' => 'application/json',
        ])->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_appointment_store_rejects_missing_data_payload(): void
    {
        $this->seedRis();
        $this->actingAsRis();

        $response = $this->postJson('/api/appointments', [], $this->authHeaders());
        $this->assertContains($response->status(), [400, 422, 500]);
    }
}
