<?php

use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SummaryController;
use App\Http\Controllers\Api\SummaryStreamController;
use App\Http\Middleware\IdentifyClient;
use Illuminate\Support\Facades\Route;

/*
 * Ask: search and the summary.
 */
Route::middleware(IdentifyClient::class)->group(function (): void {
    Route::post('search', SearchController::class)->name('search');
    Route::get('answers/{answerId}/summary', SummaryController::class)->name('answers.summary');
    Route::get('answers/{answerId}/summary/stream', SummaryStreamController::class)->name('answers.summary.stream');
});
