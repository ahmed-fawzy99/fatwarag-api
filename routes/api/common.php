<?php

use App\Http\Controllers\Api\CommonQuestionController;
use App\Http\Middleware\IdentifyClient;
use Illuminate\Support\Facades\Route;

/*
 * Common questions (the home page list).
 */
Route::middleware(IdentifyClient::class)->group(function (): void {
    Route::get('common-questions', [CommonQuestionController::class, 'index'])->name('common-questions.index');
});
