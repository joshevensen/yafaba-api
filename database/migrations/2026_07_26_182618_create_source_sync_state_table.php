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
        Schema::create('source_sync_state', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_key')->unique();
            $table->string('fingerprint')->nullable();
            $table->string('fingerprint_type')->nullable();
            $table->string('pulled_fingerprint')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('last_changed_at')->nullable();
            $table->timestampTz('last_pulled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->index('last_changed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_sync_state');
    }
};
