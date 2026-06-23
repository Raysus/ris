<?php

namespace Tests\Feature;

use App\Models\LaboratoryType;
use App\Models\TipoUsuario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class LaboratoryTypesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRis;

    public function test_sis_admin_can_list_and_create_laboratory_types_catalog(): void
    {
        LaboratoryType::query()->delete();

        $sisAdminType = TipoUsuario::where('name', 'sis_admin')->firstOrFail();
        $user = User::where('tipo_usuario_id', $sisAdminType->id)->firstOrFail();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/laboratory-types')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');

        $typeId = LaboratoryType::where('code', 'clinical')->value('id');

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/laboratories', [
            'name' => 'Centro Prueba QA',
            'laboratory_type_id' => $typeId,
            'city' => 'Temuco',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Centro Prueba QA');
    }
}
