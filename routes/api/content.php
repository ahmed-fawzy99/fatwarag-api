<?php

use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\FatwaController;
use App\Http\Controllers\Api\FatwaTranslationController;
use App\Http\Controllers\Api\GlossaryController;
use App\Http\Controllers\Api\HadithController;
use App\Http\Controllers\Api\MuftiController;
use App\Http\Controllers\Api\QuranController;
use App\Http\Controllers\Api\RelatedController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SitemapController;
use App\Http\Controllers\Api\SourceController;
use App\Http\Controllers\Api\TafsirController;
use App\Http\Controllers\Api\TopicController;
use App\Http\Middleware\IdentifyClient;
use Illuminate\Support\Facades\Route;

/*
 * Content API: fatwas, foundations, catalog, translations, reports and contact.
 */
// IdentifyClient: the visitor's IP (forwarded by the BFF) is what translation limits and Turnstile see.
Route::middleware(IdentifyClient::class)->group(function (): void {
    Route::get('fatwas', [FatwaController::class, 'index']);
    Route::get('fatwas/{publicId}', [FatwaController::class, 'show']);
    Route::get('fatwas/{publicId}/translation', [FatwaTranslationController::class, 'show']);
    Route::get('fatwas/{publicId}/related', [RelatedController::class, 'forFatwa']);
    Route::get('related', [RelatedController::class, 'byTopics']);
    Route::get('sitemap/fatwas', [SitemapController::class, 'fatwas']);
    Route::get('sitemap/hadiths', [SitemapController::class, 'hadiths']);
    Route::get('sitemap/shares', [SitemapController::class, 'shares']);

    Route::get('quran/surahs/{number}', [QuranController::class, 'surah']);
    Route::get('quran/tafsir/{surah}/{ayah}', [TafsirController::class, 'show']);
    Route::get('hadiths/{id}', [HadithController::class, 'show']);

    Route::get('sources', [SourceController::class, 'index']);
    Route::get('topics', [TopicController::class, 'index']);
    Route::get('topics/{key}/fatwas', [TopicController::class, 'fatwas']);
    Route::get('muftis/{key}', [MuftiController::class, 'show']);
    Route::get('muftis/{key}/fatwas', [MuftiController::class, 'fatwas']);
    Route::get('glossary', [GlossaryController::class, 'index']);

    Route::post('reports', [ReportController::class, 'store']);
    Route::post('contact', [ContactController::class, 'store']);
});
