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
        Schema::create('cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->index();
            $table->unsignedInteger('card_type_id');
            $table->foreign('card_type_id')->references('id')->on('card_types');
            $table->integer('pitch_value')->nullable();
            $table->text('cost')->nullable();
            $table->integer('power')->nullable();
            $table->integer('defense')->nullable();
            $table->text('functional_text')->nullable();
            $table->foreignUuid('hero_profile_id')->nullable()->constrained('hero_profiles');
            $table->string('age')->nullable();
            $table->decimal('hero_profile_match_confidence', 5, 4)->nullable();
            $table->string('hero_profile_grouping_status')->nullable();
            $table->string('source_hash')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
