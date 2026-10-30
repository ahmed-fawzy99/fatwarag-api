<?php

use Illuminate\Support\Facades\Route;

/*
 * Version 1 of the public API (consumed by the Next.js BFF and, later, Flutter). Each area keeps its own route file.
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    foreach (glob(__DIR__.'/api/*.php') as $file) {
        require $file;
    }
});
