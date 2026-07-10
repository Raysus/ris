<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('tipo_usuarios')->where('name', 'tens')->exists();
        if ($exists) {
            return;
        }

        DB::table('tipo_usuarios')->insert([
            'id' => (string) Str::uuid(),
            'name' => 'tens',
            'description' => 'TENS',
            'permissions' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('tipo_usuarios')->where('name', 'tens')->delete();
    }
};
