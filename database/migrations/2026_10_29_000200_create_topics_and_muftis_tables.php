<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shared topic tree, each source's own category tree mapped onto it, and mufti cards.
     */
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('topics')->nullOnDelete();
            $table->string('key')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('source_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('source_categories')->nullOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->text('path')->nullable();
            $table->unsignedInteger('fatwa_count')->nullable(); // as reported by the source; drives islamweb's quota
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mapping_status')->default('unmapped'); // unmapped | proposed | approved
            $table->timestamps();

            $table->unique(['source_id', 'external_id']);
        });

        Schema::create('muftis', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('kind'); // person | institution
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('bio_ar')->nullable();
            $table->text('bio_en')->nullable();
            $table->text('source_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muftis');
        Schema::dropIfExists('source_categories');
        Schema::dropIfExists('topics');
    }
};
