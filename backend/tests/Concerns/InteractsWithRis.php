<?php

namespace Tests\Concerns;

use App\Models\Appointment;
use App\Models\AppointmentStudy;
use App\Models\Exam;
use App\Models\Laboratory;
use App\Models\Machine;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\TipoUsuario;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoModulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use App\Jobs\SyncEntityToCloud;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Sanctum;

trait InteractsWithRis
{
    use RefreshDatabase;

    protected User $risUser;
    protected Laboratory $risLab;
    protected string $risToken;

    protected function fakeCloudSync(): void
    {
        Queue::fake([SyncEntityToCloud::class]);
    }

    /**
     * @param  class-string<Model>  $class
     */
    protected function createModelWithId(string $class, string $id, array $attributes): Model
    {
        $model = new $class();
        $model->setAttribute($model->getKeyName(), $id);
        $model->fill($attributes);
        $model->save();

        return $model->fresh();
    }

    protected function seedRis(bool $withDemoModules = false): void
    {
        $this->fakeCloudSync();
        $this->seed(DatabaseSeeder::class);

        if ($withDemoModules) {
            $this->seed(DemoModulesSeeder::class);
        }

        $this->risLab = Laboratory::where('name', 'Siresa')->firstOrFail();
        $this->ensureSiresaTestCatalog();

        $this->risUser = User::where('username', 'rgutierrez')->firstOrFail();
    }

    protected function ensureSiresaTestCatalog(): void
    {
        $lab = $this->risLab ?? Laboratory::where('name', 'Siresa')->firstOrFail();

        $friquelme = User::where('username', 'friquelme')->first();
        if ($friquelme && !$friquelme->laboratories()->where('laboratories.id', $lab->id)->exists()) {
            DB::table('laboratory_user')->insert([
                'id' => (string) Str::uuid(),
                'laboratory_id' => $lab->id,
                'user_id' => $friquelme->id,
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!Machine::where('laboratory_id', $lab->id)->exists()) {
            Machine::create([
                'laboratory_id' => $lab->id,
                'name' => 'Sala Test RX',
                'group' => 'RX',
                'ae_title' => 'TEST_AE',
                'ip_address' => '127.0.0.1',
                'port' => 104,
                'is_active' => true,
            ]);
        }

        if (!Exam::where('laboratory_id', $lab->id)->exists()) {
            Exam::create([
                'laboratory_id' => $lab->id,
                'group_code' => 'RX',
                'name' => 'RX TORAX PA LAT',
                'fonasa_code' => '8701',
                'price' => 25000,
                'is_active' => true,
            ]);
        }
    }

    protected function createRecepcionTestUser(): User
    {
        return $this->createRoleTestUser('recepcion');
    }

    protected function createRoleTestUser(string $role): User
    {
        $lab = $this->risLab ?? Laboratory::where('name', 'Siresa')->firstOrFail();
        $tipo = TipoUsuario::where('name', $role)->firstOrFail();

        $persona = Persona::create([
            'rut' => '18888888-' . random_int(1, 9),
            'names' => ucfirst($role),
            'last_name_1' => 'Prueba',
        ]);

        $user = User::create([
            'persona_id' => $persona->id,
            'tipo_usuario_id' => $tipo->id,
            'username' => $role . '_test_' . random_int(1000, 9999),
            'password' => Hash::make('password123'),
            'settings' => ['roles' => [$role]],
            'is_active' => true,
        ]);

        DB::table('laboratory_user')->insert([
            'id' => (string) Str::uuid(),
            'laboratory_id' => $lab->id,
            'user_id' => $user->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    protected function loginRis(string $username = 'rgutierrez', string $password = 'rgutierrez'): string
    {
        $response = $this->postJson('/api/login', [
            'login_field' => $username,
            'password' => $password,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->risToken = $response->json('access_token');

        return $this->risToken;
    }

    protected function authHeaders(?string $labId = null): array
    {
        return [
            'Authorization' => 'Bearer ' . ($this->risToken ?? $this->loginRis()),
            'Accept' => 'application/json',
            'X-Lab-Id' => $labId ?? $this->risLab->id,
        ];
    }

    protected function actingAsRis(?User $user = null): User
    {
        $user ??= $this->risUser ?? User::where('username', 'rgutierrez')->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function createTestAppointment(array $overrides = []): Appointment
    {
        $this->fakeCloudSync();

        $lab = $this->risLab ?? Laboratory::firstOrFail();
        $machine = Machine::where('laboratory_id', $lab->id)->firstOrFail();
        $exam = Exam::where('laboratory_id', $lab->id)->first();

        $persona = Persona::create([
            'rut' => '99999999-' . random_int(1, 9),
            'names' => 'Paciente',
            'last_name_1' => 'Prueba',
            'email' => 'paciente.test@example.com',
        ]);

        $patient = Paciente::create([
            'laboratory_id' => $lab->id,
            'persona_id' => $persona->id,
        ]);

        $start = now()->addHours(2);

        $appointment = Appointment::create(array_merge([
            'laboratory_id' => $lab->id,
            'patient_id' => $patient->id,
            'machine_id' => $machine->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes(30),
            'status' => 'agendado',
            'payment_status' => 'Pendiente',
            'origin' => 'Hospitalizado',
        ], $overrides));

        if ($exam) {
            AppointmentStudy::create([
                'appointment_id' => $appointment->id,
                'machine_id' => $machine->id,
                'exam_id' => $exam->id,
                'exam_name' => $exam->name,
                'fonasa_code' => $exam->fonasa_code,
                'price' => $exam->price,
                'quantity' => 1,
                'status' => 'espera',
            ]);
        }

        return $appointment->fresh(['studies.exam', 'patient.persona']);
    }
}
