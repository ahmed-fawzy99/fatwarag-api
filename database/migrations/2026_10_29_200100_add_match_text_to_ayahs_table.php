<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A spelling-neutral copy of each ayah for matching fatwa quotes: Uthmani and standard spellings differ mostly in
     * alef and hamza («أَيۡمَٰنِكُمۡ» / «أيمانكم», «ٱلۡقُرۡءَانُ» / «القرآن»), so both sides drop them. Filled by QuranQuoteMatcher.
     */
    public function up(): void
    {
        Schema::table('ayahs', function (Blueprint $table) {
            $table->text('match_text')->nullable();
        });

        DB::statement('CREATE INDEX ayahs_match_text_trgm_index ON ayahs USING gin (match_text gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ayahs_match_text_trgm_index');

        Schema::table('ayahs', function (Blueprint $table) {
            $table->dropColumn('match_text');
        });
    }
};
