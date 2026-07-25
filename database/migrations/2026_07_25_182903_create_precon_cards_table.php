<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('precon_cards', function (Blueprint $table) {
            $table->foreignUuid('precon_id')->constrained('precons')->cascadeOnDelete();
            $table->foreignUuid('card_id')->constrained('cards')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->primary(['precon_id', 'card_id']);
            $table->index('card_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('precon_cards');
    }
};
