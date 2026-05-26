<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->string('rut_hash', 64)->nullable()->after('rut');
            $table->string('email_hash', 64)->nullable()->after('email');
        });

        DB::table('personas')->orderBy('created_at')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                $updates = [];

                if (!empty($row->rut) && !$this->looksEncrypted($row->rut)) {
                    $normalized = $this->normalizeRut($row->rut);
                    $updates['rut'] = Crypt::encryptString($normalized);
                    $updates['rut_hash'] = hash('sha256', $normalized);
                } elseif (!empty($row->rut)) {
                    $updates['rut_hash'] = hash('sha256', $this->normalizeRut(
                        Crypt::decryptString($row->rut)
                    ));
                }

                if (!empty($row->email) && !$this->looksEncrypted($row->email)) {
                    $updates['email'] = Crypt::encryptString($row->email);
                    $updates['email_hash'] = hash('sha256', strtolower(trim($row->email)));
                } elseif (!empty($row->email)) {
                    $updates['email_hash'] = hash('sha256', strtolower(trim(
                        Crypt::decryptString($row->email)
                    )));
                }

                if (!empty($row->phone) && !$this->looksEncrypted($row->phone)) {
                    $updates['phone'] = Crypt::encryptString($row->phone);
                }

                if ($updates !== []) {
                    DB::table('personas')->where('id', $row->id)->update($updates);
                }
            }
        });

        Schema::table('personas', function (Blueprint $table) {
            $table->dropIndex(['rut']);
            $table->unique('rut_hash');
            $table->index('email_hash');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropUnique(['rut_hash']);
            $table->dropIndex(['email_hash']);
            $table->dropColumn(['rut_hash', 'email_hash']);
            $table->index('rut');
        });
    }

    private function normalizeRut(string $rut): string
    {
        return strtoupper(str_replace(['.', ' '], '', $rut));
    }

    private function looksEncrypted(string $value): bool
    {
        return str_starts_with($value, 'eyJ');
    }
};
