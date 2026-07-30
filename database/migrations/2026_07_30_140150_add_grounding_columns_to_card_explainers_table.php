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
        Schema::table('card_explainers', function (Blueprint $table) {
            $table->float('grounding_confidence')->nullable();
            $table->text('grounding_flag_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('card_explainers', function (Blueprint $table) {
            $table->dropColumn(['grounding_confidence', 'grounding_flag_reason']);
        });
    }
};
