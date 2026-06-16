<?php

namespace Tests\Unit;

use App\Services\WorklistTagNormalizer;
use PHPUnit\Framework\TestCase;

class WorklistTagNormalizerTest extends TestCase
{
    public function test_compact_accession_preserves_unique_suffix_same_day(): void
    {
        $a = WorklistTagNormalizer::compactAccession('ACC-20260616-019ED251');
        $b = WorklistTagNormalizer::compactAccession('ACC-20260616-019ED258');

        $this->assertNotSame($a, $b);
        $this->assertSame('60616019ED251', $a);
        $this->assertSame('60616019ED258', $b);
        $this->assertLessThanOrEqual(WorklistTagNormalizer::SH_MAX, strlen($a));
    }
}
