<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('tipo_usuarios')->where('name', 'contador')->exists();
        if ($exists) {
            return;
        }

        DB::table('tipo_usuarios')->insert([
            'id' => (string) Str::uuid(),
            'name' => 'contador',
            'description' => 'Contador(a)',
            'permissions' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('tipo_usuarios')->where('name', 'contador')->delete();
    }
};
