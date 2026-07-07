<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->decimal('fonasa_price', 10, 2)->nullable()->after('price');
        });

        DB::table('exams')->whereNull('fonasa_price')->update([
            'fonasa_price' => DB::raw('price'),
        ]);
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('fonasa_price');
        });
    }
};
