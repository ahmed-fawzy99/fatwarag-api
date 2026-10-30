<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which Voyage input type a chunk was embedded as ("document"), so chunks embedded before input types were used
     * are re-embedded in the background.
     */
    public function up(): void
    {
        Schema::table('fatwa_chunks', function (Blueprint $table) {
            $table->string('embedding_input_type', 16)->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('fatwa_chunks', function (Blueprint $table) {
            $table->dropColumn('embedding_input_type');
        });
    }
};
