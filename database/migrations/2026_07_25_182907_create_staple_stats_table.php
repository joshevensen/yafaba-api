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
        Schema::create('staple_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hero_id')->constrained('cards');
            $table->foreignUuid('card_id')->constrained('cards');
            $table->decimal('inclusion_rate', 5, 4)->nullable();
            $table->string('source');
            $table->timestampTz('fetched_at');
            $table->index(['hero_id', 'card_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staple_stats');
    }
};
