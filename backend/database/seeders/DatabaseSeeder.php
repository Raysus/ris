<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear los Tipos de Laboratorio
        $tipoClinicoId = DB::table('laboratory_types')->insertGetId(['name' => 'Clínico Humano', 'created_at' => now()]);
        $tipoVeterinarioId = DB::table('laboratory_types')->insertGetId(['name' => 'Veterinario', 'created_at' => now()]);
        $tipoDentalId = DB::table('laboratory_types')->insertGetId(['name' => 'Centro Dental', 'created_at' => now()]);

        // 2. Crear la CASA MATRIZ (No tiene parent_id)
        $casaMatrizId = DB::table('laboratories')->insertGetId([
            'laboratory_type_id' => $tipoClinicoId,
            'parent_id' => null,
            'name' => 'Centro de Diagnóstico RIS PRO - Casa Matriz',
            'is_active' => true,
            'created_at' => now(),
        ]);

        // 3. Crear una SUCURSAL (Su parent_id apunta a la Casa Matriz)
        $sucursalSurId = DB::table('laboratories')->insertGetId([
            'laboratory_type_id' => $tipoClinicoId,
            'parent_id' => $casaMatrizId,
            'name' => 'RIS PRO - Sucursal Sur',
            'is_active' => true,
            'created_at' => now(),
        ]);

        // 4. Crear Perfil y Persona (Igual que antes)
        $tipoAdminId = DB::table('tipo_usuarios')->insertGetId(['name' => 'Administrador', 'created_at' => now()]);
        $personaId = DB::table('personas')->insertGetId(['rut' => '11111111-1', 'names' => 'Admin', 'last_name_1' => 'Sistema', 'created_at' => now()]);

        // 5. Crear el Usuario
        $userId = DB::table('users')->insertGetId([
            'persona_id' => $personaId,
            'tipo_usuario_id' => $tipoAdminId,
            'username' => 'admin',
            'password' => Hash::make('Admin123!'),
            'is_active' => true,
            'created_at' => now(),
        ]);

        // 6. Asignar el administrador a la Casa Matriz
        DB::table('laboratory_user')->insert([
            'laboratory_id' => $casaMatrizId,
            'user_id' => $userId,
            'is_primary' => true,
            'created_at' => now(),
        ]);
    }
}