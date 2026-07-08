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
        $this->assertSame('20260616019ED251', $a);
        $this->assertSame('20260616019ED258', $b);
        $this->assertLessThanOrEqual(WorklistTagNormalizer::SH_MAX, strlen($a));
    }

    public function test_patient_name_not_truncated_to_legacy_limits(): void
    {
        $normalizer = new WorklistTagNormalizer;
        $fullName = 'GONZALEZ RODRIGUEZ^MARIA FERNANDA';

        $tags = $normalizer->normalize(
            [
                'PatientName' => $fullName,
                'PatientID' => '123456789',
                'AccessionNumber' => 'ACC123',
                'RequestedProcedureID' => 'ACC123',
            ],
            [[
                'Modality' => 'CR',
                'ScheduledStationAETitle' => 'FCR_PANO',
                'ScheduledProcedureStepStartDate' => '20260708',
                'ScheduledProcedureStepStartTime' => '120000',
                'ScheduledProcedureStepID' => '1',
            ]],
            'ACC123',
            'wlmscpfs',
            true,
        );

        $this->assertSame($fullName, $tags['PatientName']);
    }
}
