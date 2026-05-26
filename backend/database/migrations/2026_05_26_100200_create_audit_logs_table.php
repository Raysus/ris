<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 120);
            $table->string('entity_type', 120)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->jsonb('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
