<?php

use App\Content\ContentCache;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Lane C (answers): keep meaning-search embeddings in step with imported fatwas.
Schedule::command('fatwas:embed')->everyFifteenMinutes()->withoutOverlapping(120)->runInBackground();

// Foundations (lane B): resolve hadith quotes from the Dorar pages the harvester has saved so far.
Schedule::command('hadiths:import')->everyTenMinutes()->withoutOverlapping()->runInBackground();

// Content (lane D): translate the titles of newly imported fatwas. A small cap, since only a few arrive each hour.
Schedule::command('translations:titles --max-cost=1')->hourly()->withoutOverlapping(120)->runInBackground();

// Content (lane D): topic assignments are changed through pivot rows that fire no model events, so lists are refreshed hourly.
Schedule::call(function (): void {
    ContentCache::forgetDerived();
})->hourly()->name('content-lists-refresh');

// Fatwas (lane A): import what the harvesters have saved so far (incremental: unchanged pages are skipped).
foreach (['fawzan', 'islamqa', 'binbaz', 'islamweb'] as $source) {
    Schedule::command("fatwas:import {$source}")->everyTenMinutes()->withoutOverlapping(60)->runInBackground();
}

// Fatwas (lane A): the 3-day refresh, one source per hour so they don't overlap. It waits while a first harvest still runs.
foreach (['islamqa' => 3, 'binbaz' => 4, 'islamweb' => 5] as $source => $hour) {
    Schedule::command("fatwas:refresh {$source}")->cron("0 {$hour} */3 * *")->withoutOverlapping(720)->runInBackground();
}

// Fatwas (lane A): file new fatwas under topics, and propose topics for any new source category (usually no AI call).
Schedule::command('topics:build --max-cost=0.25')->hourlyAt(40)->withoutOverlapping(60)->runInBackground();

// Operations: hand pending hadith quotes (e.g. ones the Dorar harvester's own scan of the raw stores never saw) to the harvester.
Schedule::command('hadiths:export-pending')->everyThirtyMinutes()->withoutOverlapping(30);

// Common questions: their answers are generated once (`common:generate`, by hand) and kept for good; never on a schedule.
