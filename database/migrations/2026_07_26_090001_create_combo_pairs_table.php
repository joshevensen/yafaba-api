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
        Schema::create('combo_pairs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('card_id_a')->constrained('cards')->cascadeOnDelete();
            $table->foreignUuid('card_id_b')->constrained('cards')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->decimal('self_play_win_rate_delta', 8, 4)->nullable();
            $table->unique(['card_id_a', 'card_id_b']);
            $table->index('card_id_b');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combo_pairs');
    }
};
