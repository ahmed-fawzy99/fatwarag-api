<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fatwas, their translations, categories/topics, cross-references and search chunks.
     *
     * Text is stored as rich paragraphs (JSON arrays of segments, see web/src/types/content.ts) so quotes keep their links.
     * `search_text` is the cleaned copy (no harakat, unified letters) used by keyword search.
     */
    public function up(): void
    {
        Schema::create('fatwas', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique(); // "<sourceKey>-<sourceFatwaId>"
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('source_fatwa_id');
            $table->text('url');
            $table->foreignId('mufti_id')->nullable()->constrained()->nullOnDelete();
            $table->text('title');
            $table->jsonb('question');
            $table->jsonb('answer');
            $table->jsonb('source_summary')->nullable();
            $table->text('origin')->nullable();
            $table->unsignedSmallInteger('hijri_year')->nullable();
            $table->unsignedTinyInteger('hijri_month')->nullable();
            $table->unsignedTinyInteger('hijri_day')->nullable();
            $table->date('date_gregorian')->nullable();
            $table->boolean('is_cross_reference')->default(false); // collected only because another fatwa points to it
            $table->text('search_text');
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'source_fatwa_id']);
        });

        DB::statement("ALTER TABLE fatwas ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple', search_text)) STORED");
        DB::statement('CREATE INDEX fatwas_search_vector_index ON fatwas USING gin (search_vector)');

        Schema::create('fatwa_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fatwa_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 8);
            $table->string('kind'); // source (the site's own translation) | machine
            $table->text('title');
            $table->jsonb('question')->nullable(); // null until the body is translated (machine titles come first)
            $table->jsonb('answer')->nullable();
            $table->jsonb('source_summary')->nullable();
            $table->text('url')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();

            $table->unique(['fatwa_id', 'locale']);
        });

        Schema::create('fatwa_source_category', function (Blueprint $table) {
            $table->foreignId('fatwa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_category_id')->constrained()->cascadeOnDelete();
            $table->primary(['fatwa_id', 'source_category_id']);
        });

        Schema::create('fatwa_topic', function (Blueprint $table) {
            $table->foreignId('fatwa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->primary(['fatwa_id', 'topic_id']);
            $table->index('topic_id');
        });

        Schema::create('fatwa_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fatwa_id')->constrained()->cascadeOnDelete();
            $table->string('target_source_fatwa_id');
            $table->foreignId('target_fatwa_id')->nullable()->constrained('fatwas')->nullOnDelete();
            $table->text('anchor_text')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('target_fatwa_id');
            $table->unique(['fatwa_id', 'target_source_fatwa_id']);
        });

        Schema::create('fatwa_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fatwa_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('content');
            $table->timestamps();

            $table->unique(['fatwa_id', 'position']);
        });

        // voyage-4-large, 1024 dimensions, stored as half precision to halve index memory.
        DB::statement('ALTER TABLE fatwa_chunks ADD COLUMN embedding halfvec(1024)');
        DB::statement('CREATE INDEX fatwa_chunks_embedding_index ON fatwa_chunks USING hnsw (embedding halfvec_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fatwa_chunks');
        Schema::dropIfExists('fatwa_links');
        Schema::dropIfExists('fatwa_topic');
        Schema::dropIfExists('fatwa_source_category');
        Schema::dropIfExists('fatwa_translations');
        Schema::dropIfExists('fatwas');
    }
};
