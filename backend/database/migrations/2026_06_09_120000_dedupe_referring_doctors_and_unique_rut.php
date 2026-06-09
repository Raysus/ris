<?php

use App\Services\CatalogDedupeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('referring_doctors')) {
            return;
        }

        app(CatalogDedupeService::class)->dedupeReferringDoctors(false);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS referring_doctors_rut_normalized_unique
            ON referring_doctors (
                UPPER(REPLACE(REPLACE(rut, '.', ''), ' ', ''))
            )
            WHERE rut IS NOT NULL AND rut <> ''
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS referring_doctors_rut_normalized_unique');
    }
};
