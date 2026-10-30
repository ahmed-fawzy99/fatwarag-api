<?php

use App\Http\Controllers\Api\ShareController;
use App\Http\Middleware\IdentifyClient;
use Illuminate\Support\Facades\Route;

/*
 * Shared answers: saved only on an explicit, consented share.
 */
Route::middleware(IdentifyClient::class)->group(function (): void {
    Route::post('shares', [ShareController::class, 'store'])->name('shares.store');
    Route::get('shares/{id}', [ShareController::class, 'show'])->name('shares.show');
});
