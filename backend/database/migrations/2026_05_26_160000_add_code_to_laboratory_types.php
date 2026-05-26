<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_types', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->after('name');
        });

        $map = [
            'Clínico Humano' => 'clinical',
            'Veterinario' => 'veterinary',
            'Centro Dental' => 'dental',
        ];

        foreach ($map as $name => $code) {
            DB::table('laboratory_types')->where('name', $name)->update(['code' => $code]);
        }

        DB::table('laboratory_types')->whereNull('code')->update(['code' => 'clinical']);
    }

    public function down(): void
    {
        Schema::table('laboratory_types', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
