<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Common questions: site-authored questions shown on the home page, with pre-generated answers (an owner-approved
     * exception to "questions aren't stored"; visitor questions are still never stored).
     */
    public function up(): void
    {
        Schema::create('common_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('position')->default(0)->index();
            $table->string('question_ar', 1000);
            $table->string('question_en', 1000)->nullable();
            $table->string('normalized_ar', 1000)->index();
            $table->string('normalized_en', 1000)->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('common_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('common_question_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 2);
            $table->json('fatwa_ids');
            $table->json('summary')->nullable();
            $table->string('status', 10);
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['common_question_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('common_answers');
        Schema::dropIfExists('common_questions');
    }
};
