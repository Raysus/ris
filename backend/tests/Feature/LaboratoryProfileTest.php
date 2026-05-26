<?php

namespace Tests\Feature;

use App\Models\Laboratory;
use App\Models\LaboratoryType;
use App\Services\LaboratoryProfileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class LaboratoryProfileTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis();
        $this->loginRis();
    }

    public function test_lab_profile_endpoint_for_clinical_lab(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/lab-profile')
            ->assertOk()
            ->assertJsonPath('data.code', 'clinical')
            ->assertJsonPath('data.uses_fonasa', true);
    }

    public function test_fonasa_blocked_for_dental_laboratory(): void
    {
        $dentalType = LaboratoryType::where('code', 'dental')->first()
            ?? LaboratoryType::where('name', 'Centro Dental')->firstOrFail();

        $dentalLab = Laboratory::create([
            'laboratory_type_id' => $dentalType->id,
            'name' => 'Clínica Dental Test',
            'is_active' => true,
        ]);

        $this->attachUserToLab($dentalLab->id);

        $appointment = $this->createTestAppointment(['laboratory_id' => $dentalLab->id]);

        $this->withHeaders($this->authHeaders($dentalLab->id))
            ->getJson("/api/fonasa/appointments/{$appointment->id}/preview")
            ->assertStatus(403);

        $profile = LaboratoryProfileService::resolve($dentalLab->load('type'));
        $this->assertFalse($profile['uses_fonasa']);
        $this->assertFalse($profile['show_insurance_fields']);
        $this->assertFalse($profile['uses_dicom_worklist']);
        $this->assertSame('manual_upload', $profile['dicom_integration_mode']);

        $this->withHeaders($this->authHeaders($dentalLab->id))
            ->getJson('/api/lab-profile')
            ->assertOk()
            ->assertJsonPath('data.uses_dicom_worklist', false);
    }

    public function test_clinical_lab_uses_dicom_worklist_by_default(): void
    {
        $profile = LaboratoryProfileService::resolve();
        $this->assertTrue($profile['uses_dicom_worklist']);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/lab-profile')
            ->assertOk()
            ->assertJsonPath('data.uses_dicom_worklist', true);
    }

    public function test_lab_settings_can_force_manual_dicom_on_clinical(): void
    {
        $clinical = Laboratory::whereHas('type', fn ($q) => $q->where('code', 'clinical'))->first()
            ?? Laboratory::firstOrFail();

        $clinical->settings = ['uses_dicom_worklist' => false];
        $clinical->save();

        $profile = LaboratoryProfileService::resolve($clinical->fresh('type'));
        $this->assertFalse($profile['uses_dicom_worklist']);

        $clinical->settings = null;
        $clinical->save();
    }

    public function test_agenda_catalogs_exclude_fonasa_for_veterinary(): void
    {
        $vetType = LaboratoryType::where('code', 'veterinary')->first()
            ?? LaboratoryType::where('name', 'Veterinario')->firstOrFail();

        $vetLab = Laboratory::create([
            'laboratory_type_id' => $vetType->id,
            'name' => 'Veterinaria Test',
            'is_active' => true,
        ]);

        $this->attachUserToLab($vetLab->id);

        $response = $this->withHeaders($this->authHeaders($vetLab->id))
            ->getJson('/api/agenda-catalogs');

        $response->assertOk()
            ->assertJsonPath('data.lab_profile.code', 'veterinary')
            ->assertJsonPath('data.lab_profile.uses_fonasa', false);

        $names = collect($response->json('data.insurances'))->pluck('name')->map(fn ($n) => strtoupper($n));
        $this->assertFalse($names->contains(fn ($n) => str_contains($n, 'FONASA')));
    }

    private function attachUserToLab(string $labId): void
    {
        $exists = DB::table('laboratory_user')
            ->where('user_id', $this->risUser->id)
            ->where('laboratory_id', $labId)
            ->exists();

        if (!$exists) {
            DB::table('laboratory_user')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $this->risUser->id,
                'laboratory_id' => $labId,
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
