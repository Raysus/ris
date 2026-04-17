<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supply_pack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_pack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(1);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('supply_pack_items');
    }
};