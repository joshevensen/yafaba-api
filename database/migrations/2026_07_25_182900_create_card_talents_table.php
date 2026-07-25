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
        Schema::create('card_talents', function (Blueprint $table) {
            $table->foreignUuid('card_id')->constrained('cards')->cascadeOnDelete();
            $table->unsignedInteger('talent_id');
            $table->foreign('talent_id')->references('id')->on('talents')->cascadeOnDelete();
            $table->primary(['card_id', 'talent_id']);
            $table->index('talent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_talents');
    }
};
