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
        Schema::create('meta_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hero_id')->constrained('cards');
            $table->string('format');
            $table->string('tier')->nullable();
            $table->decimal('win_rate', 5, 4)->nullable();
            $table->integer('sample_size')->nullable();
            $table->string('source');
            $table->timestampTz('fetched_at');
            $table->index(['hero_id', 'format']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meta_snapshots');
    }
};
