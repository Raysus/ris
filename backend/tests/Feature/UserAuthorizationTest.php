<?php

namespace Tests\Feature;

use App\Models\Laboratory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SyncEntityToCloud;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SyncEntityToCloud::class]);
        $this->seed(DatabaseSeeder::class);
    }

    public function test_tecnologo_cannot_create_users(): void
    {
        $lab = Laboratory::where('name', 'Siresa')->firstOrFail();
        $tecnologo = User::where('username', 'friquelme')->firstOrFail();
        Sanctum::actingAs($tecnologo);

        $response = $this->withHeaders(['X-Lab-Id' => $lab->id])
            ->postJson('/api/users', [
                'rut' => '11.111.111-1',
                'nombres' => 'Nuevo',
                'apellidoPaterno' => 'Usuario',
                'username' => 'nuevo_user',
                'password' => 'secret1234',
                'roles' => ['recepcion'],
                'laboratories' => [$lab->id],
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_list_users(): void
    {
        $lab = Laboratory::where('name', 'Siresa')->firstOrFail();
        $admin = User::where('username', 'rgutierrez')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->withHeaders(['X-Lab-Id' => $lab->id])
            ->getJson('/api/users');

        $response->assertOk()->assertJsonPath('success', true);
    }
}
