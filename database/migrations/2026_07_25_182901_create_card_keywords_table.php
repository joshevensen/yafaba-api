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
        Schema::create('card_keywords', function (Blueprint $table) {
            $table->foreignUuid('card_id')->constrained('cards')->cascadeOnDelete();
            $table->unsignedInteger('keyword_id');
            $table->foreign('keyword_id')->references('id')->on('keywords')->cascadeOnDelete();
            $table->primary(['card_id', 'keyword_id']);
            $table->index('keyword_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_keywords');
    }
};
