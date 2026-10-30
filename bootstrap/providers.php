<?php

use App\Providers\AppServiceProvider;
use App\Providers\ContentCacheServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ContentCacheServiceProvider::class,
];
