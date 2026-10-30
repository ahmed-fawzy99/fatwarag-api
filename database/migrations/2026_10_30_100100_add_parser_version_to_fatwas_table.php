<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which version of its source's parser produced a fatwa, so a parser change re-imports pages that are otherwise unchanged.
     */
    public function up(): void
    {
        Schema::table('fatwas', function (Blueprint $table) {
            $table->unsignedSmallInteger('parser_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fatwas', function (Blueprint $table) {
            $table->dropColumn('parser_version');
        });
    }
};
