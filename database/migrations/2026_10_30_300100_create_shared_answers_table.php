<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared answers: saved only when a visitor explicitly shares an answer (an owner-approved exception to "questions
     * aren't stored"). Kept until the owner deletes one in the admin. `ip_hash` is a keyed hash, for rate limiting only.
     */
    public function up(): void
    {
        Schema::create('shared_answers', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 32)->unique();
            $table->text('question');
            $table->text('follow_up')->nullable();
            $table->string('locale', 2);
            $table->json('fatwa_ids');
            $table->json('summary')->nullable();
            $table->string('ip_hash', 64)->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_answers');
    }
};
