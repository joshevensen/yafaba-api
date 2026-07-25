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
        Schema::create('precons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('set_code')->nullable();
            $table->string('type')->nullable();
            $table->foreignUuid('hero_card_id')->nullable()->index()->constrained('cards');
            $table->date('release_date')->nullable();
            $table->text('notes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('precons');
    }
};
