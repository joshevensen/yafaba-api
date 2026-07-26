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
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('kb_documents', function (Blueprint $table) use ($driver) {
            $table->uuid('id')->primary();
            $table->string('source_type')->index();
            $table->text('source_ref')->nullable();
            $table->text('content')->nullable();
            if ($driver !== 'pgsql') {
                $table->text('embedding')->nullable();
            }
            $table->string('embedding_model')->nullable();
            $table->string('trust_status')->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->date('effective_date')->nullable();
            $table->timestampTz('created_at')->nullable();
        });

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE kb_documents ADD COLUMN embedding vector(1024)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kb_documents');
    }
};
