<?php

namespace Tests\Feature;

use App\Models\Persona;
use App\Models\User;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

/**
 * QA del input de email al crear/editar usuarios en Administración.
 * El email se guarda en la Persona (no en User) y se normaliza a minúsculas.
 */
class UserEmailTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis();
        $this->loginRis();
    }

    public function test_create_user_stores_email_on_persona(): void
    {
        $resp = $this->withHeaders($this->authHeaders())->postJson('/api/users', [
            'rut' => '16894365-7',
            'nombres' => 'Ana',
            'apellidoPaterno' => 'Pérez',
            'apellidoMaterno' => 'Soto',
            'email' => 'Ana.Perez@Centro.cl',
            'username' => 'aperez',
            'password' => 'secret123',
            'roles' => ['recepcion'],
            'laboratories' => [$this->risLab->id],
        ]);

        $resp->assertOk()->assertJsonPath('success', true);

        $user = User::where('username', 'aperez')->with('persona')->firstOrFail();

        // Se guarda en la Persona y se normaliza a minúsculas.
        $this->assertSame('ana.perez@centro.cl', $user->persona->email);
        // El hash de búsqueda queda poblado.
        $this->assertSame(Persona::hashEmail('ana.perez@centro.cl'), $user->persona->email_hash);
    }

    public function test_create_user_rejects_invalid_email(): void
    {
        $resp = $this->withHeaders($this->authHeaders())->postJson('/api/users', [
            'rut' => '16894365-7',
            'nombres' => 'Ana',
            'apellidoPaterno' => 'Pérez',
            'email' => 'no-es-un-correo',
            'username' => 'aperez',
            'password' => 'secret123',
            'roles' => ['recepcion'],
            'laboratories' => [$this->risLab->id],
        ]);

        $resp->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_create_user_without_email_is_allowed(): void
    {
        $resp = $this->withHeaders($this->authHeaders())->postJson('/api/users', [
            'rut' => '16894365-7',
            'nombres' => 'Ana',
            'apellidoPaterno' => 'Pérez',
            'username' => 'aperez',
            'password' => 'secret123',
            'roles' => ['recepcion'],
            'laboratories' => [$this->risLab->id],
        ]);

        $resp->assertOk()->assertJsonPath('success', true);

        $persona = User::where('username', 'aperez')->firstOrFail()->persona;
        $this->assertNull($persona->email);
        $this->assertNull($persona->email_hash);
    }

    public function test_edit_user_updates_email_without_password(): void
    {
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->postJson('/api/users', [
            'rut' => '16894365-7',
            'nombres' => 'Ana',
            'apellidoPaterno' => 'Pérez',
            'email' => 'ana@centro.cl',
            'username' => 'aperez',
            'password' => 'secret123',
            'roles' => ['recepcion'],
            'laboratories' => [$this->risLab->id],
        ])->assertOk();

        // Editar solo el email (sin enviar contraseña).
        $resp = $this->withHeaders($headers)->postJson('/api/users', [
            'rut' => '16894365-7',
            'nombres' => 'Ana',
            'apellidoPaterno' => 'Pérez',
            'email' => 'nuevo@centro.cl',
            'username' => 'aperez',
            'roles' => ['recepcion'],
            'laboratories' => [$this->risLab->id],
        ]);

        $resp->assertOk()->assertJsonPath('success', true);

        $persona = User::where('username', 'aperez')->firstOrFail()->persona;
        $this->assertSame('nuevo@centro.cl', $persona->email);
        $this->assertSame(Persona::hashEmail('nuevo@centro.cl'), $persona->email_hash);
    }
}
