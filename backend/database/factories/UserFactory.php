<?php

namespace Database\Factories;

use App\Models\Persona;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        $persona = Persona::create([
            'rut' => fake()->unique()->numerify('########') . '-' . fake()->numberBetween(1, 9),
            'names' => fake()->firstName(),
            'last_name_1' => fake()->lastName(),
        ]);

        return [
            'persona_id' => $persona->id,
            'tipo_usuario_id' => \App\Models\TipoUsuario::query()->value('id'),
            'username' => fake()->unique()->userName(),
            'password' => static::$password ??= Hash::make('password'),
            'settings' => '{}',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }
}
