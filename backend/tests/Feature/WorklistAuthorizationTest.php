<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class WorklistAuthorizationTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis();
    }

    public function test_worklist_denies_user_without_operational_role(): void
    {
        Sanctum::actingAs($this->createRecepcionTestUser());

        $this->withHeaders(['X-Lab-Id' => $this->risLab->id, 'Accept' => 'application/json'])
            ->getJson('/api/worklist')
            ->assertForbidden();
    }

    public function test_worklist_allows_tecnologo(): void
    {
        Sanctum::actingAs(User::where('username', 'friquelme')->firstOrFail());

        $this->withHeaders(['X-Lab-Id' => $this->risLab->id, 'Accept' => 'application/json'])
            ->getJson('/api/worklist')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
