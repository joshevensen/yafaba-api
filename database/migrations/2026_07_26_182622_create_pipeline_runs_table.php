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
        Schema::create('pipeline_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('phase');
            $table->string('command');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('status');
            $table->string('triggered_by');
            $table->json('counts')->nullable();
            $table->boolean('is_running')->default(false);
            $table->text('error')->nullable();
            $table->index(['phase', 'status', 'finished_at']);
            $table->index(['phase', 'is_running']);
            $table->index(['command', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pipeline_runs');
    }
};
