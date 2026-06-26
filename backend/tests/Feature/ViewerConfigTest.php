<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class ViewerConfigTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis();
        Sanctum::actingAs(User::where('username', 'rgutierrez')->firstOrFail());
    }

    public function test_viewer_config_does_not_expose_viewer_token(): void
    {
        putenv('VIEWER_TOKEN=super-secret-viewer-token');
        $_ENV['VIEWER_TOKEN'] = 'super-secret-viewer-token';

        $response = $this->withHeaders(['X-Lab-Id' => $this->risLab->id])
            ->getJson('/api/viewer-config');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertArrayNotHasKey('viewer_token', $response->json('data'));
    }
}
