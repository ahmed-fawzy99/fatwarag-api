<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sources, their collector state, and the resumable task queue every collector works through.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('kind'); // fatwa | foundation
            $table->unsignedTinyInteger('tier')->default(1); // internal only, never exposed
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('base_url');
            $table->jsonb('settings')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('collector_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('idle'); // idle | running | stopped | done
            $table->text('stop_reason')->nullable();
            $table->jsonb('cursor')->nullable();
            $table->unsignedInteger('items_collected')->default(0);
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('collection_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // e.g. fatwa, fatwa_en, listing, hadith_lookup, sharh
            $table->string('external_id');
            $table->text('url')->nullable();
            $table->jsonb('payload')->nullable();
            $table->integer('priority')->default(0);
            $table->string('status')->default('pending'); // pending | running | done | failed | skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'kind', 'external_id']);
            $table->index(['source_id', 'status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_tasks');
        Schema::dropIfExists('collector_states');
        Schema::dropIfExists('sources');
    }
};
