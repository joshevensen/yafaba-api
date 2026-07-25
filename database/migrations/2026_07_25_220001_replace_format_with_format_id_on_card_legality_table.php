<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('card_legality', function (Blueprint $table) {
            $table->unsignedInteger('format_id')->nullable()->after('card_id');
        });

        foreach (DB::table('formats')->pluck('id', 'abbreviation') as $abbreviation => $formatId) {
            DB::table('card_legality')->where('format', $abbreviation)->update(['format_id' => $formatId]);
        }

        Schema::table('card_legality', function (Blueprint $table) {
            $table->dropUnique(['card_id', 'format']);
            $table->dropColumn('format');
            $table->unsignedInteger('format_id')->nullable(false)->change();
            $table->foreign('format_id')->references('id')->on('formats');
            $table->unique(['card_id', 'format_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('card_legality', function (Blueprint $table) {
            $table->dropUnique(['card_id', 'format_id']);
            $table->dropForeign(['format_id']);
            $table->string('format')->nullable()->after('card_id');
        });

        foreach (DB::table('formats')->pluck('abbreviation', 'id') as $formatId => $abbreviation) {
            DB::table('card_legality')->where('format_id', $formatId)->update(['format' => $abbreviation]);
        }

        Schema::table('card_legality', function (Blueprint $table) {
            $table->string('format')->nullable(false)->change();
            $table->dropColumn('format_id');
            $table->unique(['card_id', 'format']);
        });
    }
};
