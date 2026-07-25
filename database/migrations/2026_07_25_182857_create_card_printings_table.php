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
        Schema::create('card_printings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('card_id')->index()->constrained('cards');
            $table->string('set_code')->index();
            $table->string('rarity')->nullable();
            $table->string('finish')->nullable();
            $table->string('art_variant')->nullable();
            $table->text('image_url')->nullable();
            $table->string('cardvault_print_id')->nullable();
            $table->decimal('price_cache', 10, 2)->nullable();
            $table->timestampTz('price_updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_printings');
    }
};
