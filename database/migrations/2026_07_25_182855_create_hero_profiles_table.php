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
        Schema::create('hero_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hero_id')->constrained('heroes');
            $table->string('label');
            $table->text('pattern_summary')->nullable();
            $table->integer('complexity_score')->nullable();
            $table->string('complexity_rating')->nullable();
            $table->json('playstyle_tags')->nullable();
            $table->string('pitch_lean')->nullable();
            $table->text('notes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hero_profiles');
    }
};
