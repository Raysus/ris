<?php

namespace Tests\Unit;

use App\Support\ModalityCode;
use PHPUnit\Framework\TestCase;

class ModalityCodeTest extends TestCase
{
    public function test_normalize_group_legacy_aliases(): void
    {
        $this->assertSame('CT', ModalityCode::normalizeGroup('SCANNER'));
        $this->assertSame('MRI', ModalityCode::normalizeGroup('RM'));
        $this->assertSame('DEXA', ModalityCode::normalizeGroup('DENSITO'));
    }

    public function test_cr_and_dx_remain_separate(): void
    {
        $this->assertSame('CR', ModalityCode::normalizeGroup('CR'));
        $this->assertSame('DX', ModalityCode::normalizeGroup('DX'));
        $this->assertSame('CR', ModalityCode::forDicomWorklist('CR'));
        $this->assertSame('DX', ModalityCode::forDicomWorklist('DX'));
        $this->assertSame('DX', ModalityCode::forDicomWorklist('RX'));
    }

    public function test_mamo_maps_to_mg_in_worklist(): void
    {
        $this->assertSame('MAMO', ModalityCode::normalizeGroup('MG'));
        $this->assertSame('MG', ModalityCode::forDicomWorklist('MAMO'));
    }
}
