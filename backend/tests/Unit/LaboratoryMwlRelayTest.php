<?php

namespace Tests\Unit;

use App\Models\Laboratory;
use App\Support\CloudSyncMode;
use App\Support\LaboratoryMwlRelay;
use App\Support\OrthancUrl;
use Tests\TestCase;

class LaboratoryMwlRelayTest extends TestCase
{
    public function test_resolves_url_from_laboratory_settings(): void
    {
        $lab = new Laboratory([
            'settings' => ['local_mwl_relay_url' => 'http://lab.example/api/integrations/local-mwl/relay'],
        ]);

        $this->assertSame(
            'http://lab.example/api/integrations/local-mwl/relay',
            LaboratoryMwlRelay::resolveUrl($lab)
        );
    }

    public function test_should_relay_from_cloud_when_lab_has_relay_url(): void
    {
        config([
            'cloud_sync.role' => 'cloud',
            'services.mwl.provider' => 'cloud',
            'services.mwl.url' => '',
        ]);

        $lab = new Laboratory([
            'settings' => ['local_mwl_relay_url' => 'http://siresa.example/relay'],
        ]);

        $this->assertTrue(CloudSyncMode::isCloud());
        $this->assertFalse(OrthancUrl::usesLocalWorklist());
        $this->assertTrue(LaboratoryMwlRelay::shouldRelayFromCloud($lab));
    }

    public function test_should_not_relay_when_server_has_local_mwl(): void
    {
        config([
            'cloud_sync.role' => 'cloud',
            'services.mwl.provider' => 'orthanc',
            'services.mwl.orthanc_mode' => 'files',
        ]);

        $lab = new Laboratory([
            'settings' => ['local_mwl_relay_url' => 'http://siresa.example/relay'],
        ]);

        $this->assertFalse(LaboratoryMwlRelay::shouldRelayFromCloud($lab));
    }
}
