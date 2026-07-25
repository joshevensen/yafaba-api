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
        Schema::create('errata_bulletins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bulletin_number');
            $table->text('url')->nullable();
            $table->date('published_date')->nullable();
            $table->text('content')->nullable();
            $table->json('affected_card_ids')->nullable();
            $table->timestampTz('cached_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('errata_bulletins');
    }
};
