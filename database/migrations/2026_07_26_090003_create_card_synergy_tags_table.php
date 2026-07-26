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
        Schema::create('card_synergy_tags', function (Blueprint $table) {
            $table->foreignUuid('card_id')->constrained('cards')->cascadeOnDelete();
            $table->foreignUuid('synergy_tag_id')->constrained('synergy_tags')->cascadeOnDelete();
            $table->string('status')->default('draft')->index();
            $table->primary(['card_id', 'synergy_tag_id']);
            $table->index('synergy_tag_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_synergy_tags');
    }
};
