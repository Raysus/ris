<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hl7_messages', function (Blueprint $table) {
            $table->string('direction')->default('inbound')->after('message_type');
            $table->foreignUuid('appointment_id')->nullable()->after('laboratory_id')
                ->constrained('appointments')->nullOnDelete();
            $table->string('placer_order_id')->nullable()->after('message_control_id');
        });
    }

    public function down(): void
    {
        Schema::table('hl7_messages', function (Blueprint $table) {
            $table->dropForeign(['appointment_id']);
            $table->dropColumn(['direction', 'appointment_id', 'placer_order_id']);
        });
    }
};
