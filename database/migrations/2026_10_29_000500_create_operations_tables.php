<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reports, contact messages, AI spend, settings, system alerts and translation jobs.
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('reason');
            $table->text('comment')->nullable();
            $table->string('locale', 8);
            $table->jsonb('context'); // where it was reported, what was reported, the question, the summary shown, device
            $table->string('status')->default('new'); // new | reviewed | resolved
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email');
            $table->text('message');
            $table->string('locale', 8);
            $table->string('status')->default('new'); // new | answered
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->string('purpose'); // summary | merge | translation | embedding | rerank
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->boolean('counts_toward_budget')->default(true); // one-off indexing is tracked but not budgeted
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at', 'counts_toward_budget']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->jsonb('value');
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint')->unique(); // groups repeats of the same failure
            $table->string('kind');
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('translation_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 8);
            $table->string('scope'); // fatwa_titles | topics | muftis | foundations
            $table->string('status')->default('pending'); // pending | running | done | stopped
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('stop_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_jobs');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('ai_usages');
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('reports');
    }
};
