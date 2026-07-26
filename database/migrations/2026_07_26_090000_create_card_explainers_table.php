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
        Schema::create('card_explainers', function (Blueprint $table) {
            $table->foreignUuid('card_id')->primary()->constrained('cards')->cascadeOnDelete();
            $table->text('explainer_text')->nullable();
            $table->json('cited_rules')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('validated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_explainers');
    }
};
