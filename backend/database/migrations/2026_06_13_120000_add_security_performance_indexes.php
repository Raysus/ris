<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->index('accession_number');
            $table->index('study_instance_uid');
        });

        if (Schema::hasTable('hl7_messages')) {
            Schema::table('hl7_messages', function (Blueprint $table) {
                $table->index('message_control_id');
            });
        }

        if (Schema::hasTable('laboratory_user')) {
            Schema::table('laboratory_user', function (Blueprint $table) {
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['accession_number']);
            $table->dropIndex(['study_instance_uid']);
        });

        if (Schema::hasTable('hl7_messages')) {
            Schema::table('hl7_messages', function (Blueprint $table) {
                $table->dropIndex(['message_control_id']);
            });
        }

        if (Schema::hasTable('laboratory_user')) {
            Schema::table('laboratory_user', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
            });
        }
    }
};
