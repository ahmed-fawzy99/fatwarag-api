<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foundations: the Quran with its translation and tafsirs, hadiths with every grading, and the grading glossary.
     */
    public function up(): void
    {
        Schema::create('surahs', function (Blueprint $table) {
            $table->unsignedSmallInteger('number')->primary();
            $table->string('name_ar');
            $table->string('name_en');
            $table->unsignedSmallInteger('ayah_count');
        });

        Schema::create('ayahs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('surah_number');
            $table->unsignedSmallInteger('number');
            $table->text('text');
            $table->text('search_text');
            $table->text('translation_en')->nullable();
            $table->jsonb('footnotes_en')->nullable();

            $table->foreign('surah_number')->references('number')->on('surahs')->cascadeOnDelete();
            $table->unique(['surah_number', 'number']);
        });

        DB::statement('CREATE INDEX ayahs_search_text_trgm_index ON ayahs USING gin (search_text gin_trgm_ops)');

        Schema::create('tafsirs', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // muyassar | ibnKathir | saadi
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('source_url')->nullable();
            $table->boolean('is_default')->default(false);
        });

        Schema::create('tafsir_passages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tafsir_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('surah_number');
            $table->unsignedSmallInteger('ayah_from');
            $table->unsignedSmallInteger('ayah_to');
            $table->text('text');

            $table->index(['tafsir_id', 'surah_number', 'ayah_from', 'ayah_to']);
        });

        Schema::create('hadith_explanations', function (Blueprint $table) {
            $table->id();
            $table->string('dorar_sharh_id')->unique();
            $table->text('text');
            $table->text('source_url');
            $table->timestamps();
        });

        Schema::create('hadiths', function (Blueprint $table) {
            $table->id();
            $table->text('text');
            $table->text('search_text');
            $table->text('narrator')->nullable();
            $table->text('translation_en')->nullable(); // machine translation, made once
            $table->foreignId('hadith_explanation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('lookup_url')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE INDEX hadiths_search_text_trgm_index ON hadiths USING gin (search_text gin_trgm_ops)');

        Schema::create('hadith_gradings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hadith_id')->constrained()->cascadeOnDelete();
            $table->string('dorar_hash')->nullable();
            $table->text('text')->nullable(); // this narration's wording
            $table->text('narrator')->nullable();
            $table->string('scholar');
            $table->string('scholar_en')->nullable();
            $table->text('book')->nullable();
            $table->string('reference')->nullable(); // page or number
            $table->text('phrase'); // the grading as written, e.g. «حسن صحيح»
            $table->string('level'); // sahih | hasan | daif | very_daif | mawdu | other
            $table->text('takhrij')->nullable();
            $table->string('tab')->nullable(); // general | specialist
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['hadith_id', 'dorar_hash']);
        });

        // Every distinct hadith quote found in a fatwa, keyed by its cleaned text. Fatwa segments point here, so a lookup
        // that completes later links every fatwa quoting it without rewriting their text.
        Schema::create('hadith_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('quote_key', 64)->unique(); // sha256 of the cleaned quote
            $table->text('quote');
            $table->foreignId('hadith_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending'); // pending | found | not_found
            $table->unsignedInteger('fatwa_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'fatwa_count']);
        });

        Schema::create('glossary_terms', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label_ar');
            $table->string('label_en');
            $table->string('level');
            $table->jsonb('entry_ar')->nullable(); // {meaning: GlossaryText, acting: GlossaryText|null}
            $table->jsonb('entry_en')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glossary_terms');
        Schema::dropIfExists('hadith_quotes');
        Schema::dropIfExists('hadith_gradings');
        Schema::dropIfExists('hadiths');
        Schema::dropIfExists('hadith_explanations');
        Schema::dropIfExists('tafsir_passages');
        Schema::dropIfExists('tafsirs');
        Schema::dropIfExists('ayahs');
        Schema::dropIfExists('surahs');
    }
};
