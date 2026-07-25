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
        Schema::create('rules_text_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestampTz('fetched_at');
            $table->text('full_text');
            $table->text('diff_from_previous')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rules_text_versions');
    }
};
