<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Valores cifrados (Laravel Crypt) superan varchar(255) en rut/email/teléfono.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE personas ALTER COLUMN rut TYPE text');
        DB::statement('ALTER TABLE personas ALTER COLUMN email TYPE text');
        DB::statement('ALTER TABLE personas ALTER COLUMN phone TYPE text');

        DB::statement('ALTER TABLE users ALTER COLUMN pacs_ae TYPE text');
        DB::statement('ALTER TABLE users ALTER COLUMN dragon_profile TYPE text');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE personas ALTER COLUMN rut TYPE character varying(255)');
        DB::statement('ALTER TABLE personas ALTER COLUMN email TYPE character varying(255)');
        DB::statement('ALTER TABLE personas ALTER COLUMN phone TYPE character varying(255)');

        DB::statement('ALTER TABLE users ALTER COLUMN pacs_ae TYPE character varying(255)');
        DB::statement('ALTER TABLE users ALTER COLUMN dragon_profile TYPE character varying(255)');
    }
};
