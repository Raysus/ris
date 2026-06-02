<?php

namespace Tests\Feature;

use App\Models\ReferringDoctor;
use App\Support\CloudSyncMode;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class CloudSyncInboundTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cloud_sync.secret' => 'test-sync-secret',
            'cloud_sync.inbound_enabled' => true,
            'cloud_sync.role' => 'cloud',
        ]);
        $this->seedRis();
    }

    public function test_inbound_rejects_invalid_token(): void
    {
        $this->postJson('/api/integrations/cloud-sync/inbound', [
            'model' => 'ReferringDoctor',
            'action' => 'updated',
            'data' => ['id' => '00000000-0000-0000-0000-000000000099', 'names' => 'Test'],
        ])->assertStatus(401);
    }

    public function test_inbound_upserts_referring_doctor(): void
    {
        $id = '11111111-1111-1111-1111-111111111111';

        $this->withToken('test-sync-secret')
            ->postJson('/api/integrations/cloud-sync/inbound', [
                'model' => 'ReferringDoctor',
                'action' => 'updated',
                'data' => [
                    'id' => $id,
                    'rut' => '11.111.111-1',
                    'names' => 'Medico',
                    'last_name_1' => 'Sync',
                    'phone' => '900000000',
                    'email' => 'sync@test.cl',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('referring_doctors', [
            'id' => $id,
            'names' => 'Medico',
        ]);
    }

    public function test_export_returns_catalog(): void
    {
        ReferringDoctor::create([
            'id' => '22222222-2222-2222-2222-222222222222',
            'rut' => '22.222.222-2',
            'names' => 'Export',
            'last_name_1' => 'Doc',
        ]);

        $this->withToken('test-sync-secret')
            ->getJson('/api/integrations/cloud-sync/export')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['referring_doctors', 'exams', 'machines']]);
    }

    public function test_admin_can_pull_catalog_on_cloud(): void
    {
        $this->loginRis();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/integrations/cloud-sync/pull-catalog', [
                'include_patients' => false,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(CloudSyncMode::acceptsInbound());
    }
}
