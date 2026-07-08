<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->boolean('receipt_printed')->default(false)->after('images_received_at');
            $table->timestamp('receipt_printed_at')->nullable()->after('receipt_printed');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['receipt_printed', 'receipt_printed_at']);
        });
    }
};
