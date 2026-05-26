<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Hl7Message;
use App\Services\Hl7Parser;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class Hl7IntegrationTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis();
        Config::set('hl7.enabled', true);
        Config::set('hl7.inbound_secret', 'test-hl7-secret');
    }

    public function test_hl7_inbound_creates_appointment_from_orm(): void
    {
        $message = implode("\r", [
            'MSH|^~\\&|HIS|HOSP|RIS|LAB|20260525120000||ORM^O01|MSG001|P|2.5',
            'PID|1||11111111-1^^^RUT||PEREZ^JUAN||19800101|M',
            'ORC|NW|ORD-HL7-001|||||||20260525140000',
            'OBR|1|ORD-HL7-001||8701^RX TORAX||||||||||||||||RX',
        ]);

        $response = $this->postJson('/api/hl7/inbound', [
            'message' => $message,
        ], [
            'X-HL7-Secret' => 'test-hl7-secret',
            'X-Lab-Id' => $this->risLab->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['appointment_id', 'hl7_message_id']);

        $appointment = Appointment::find($response->json('appointment_id'));
        $this->assertNotNull($appointment);
        $this->assertEquals('agendado', $appointment->status);
        $this->assertEquals('ORD-HL7-001', $appointment->accession_number);

        $this->assertDatabaseHas('hl7_messages', [
            'message_control_id' => 'MSG001',
            'status' => 'procesado',
        ]);
    }

    public function test_hl7_parser_extracts_fields(): void
    {
        $raw = "MSH|^~\\&|HIS|HOSP|RIS|LAB|20260525120000||ORM^O01|CTRL99|P|2.5\rPID|1||22222222-2^^^RUT||LOPEZ^ANA\r";
        $parsed = app(Hl7Parser::class)->parse($raw);

        $this->assertEquals('ORM^O01', $parsed['message_type']);
        $this->assertEquals('CTRL99', $parsed['message_control_id']);
        $this->assertEquals('22222222-2', $parsed['patient_id']);
    }

    public function test_admin_lists_hl7_messages(): void
    {
        $this->loginRis();

        Hl7Message::create([
            'laboratory_id' => $this->risLab->id,
            'message_control_id' => 'TEST1',
            'message_type' => 'ORM^O01',
            'direction' => 'inbound',
            'raw_message' => 'MSH|...',
            'status' => 'procesado',
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/integrations/hl7/messages')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }
}
