<?php

namespace Tests\Unit;

use App\Models\Laboratory;
use PHPUnit\Framework\TestCase;

class LaboratoryAllowedLabsTest extends TestCase
{
    public function test_supports_multi_site_view_for_operational_roles(): void
    {
        $this->assertTrue(Laboratory::supportsMultiSiteView('admin'));
        $this->assertTrue(Laboratory::supportsMultiSiteView('radiologo'));
        $this->assertTrue(Laboratory::supportsMultiSiteView('tecnologo'));
        $this->assertFalse(Laboratory::supportsMultiSiteView('auxiliar'));
    }

    public function test_multi_site_operational_roles_constant(): void
    {
        $this->assertContains('radiologo', Laboratory::MULTI_SITE_OPERATIONAL_ROLES);
        $this->assertContains('recepcion', Laboratory::MULTI_SITE_OPERATIONAL_ROLES);
    }
}
