<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Magna\Pages\Http\Controllers\MenusApiController;

// Mounted at api/v1/pages by the plugin route registrar.
// Menus are public nav data — the same tree the rendered site shows every
// visitor — so headless frontends read them without a delivery token.
Route::get('/menus/{handle}', MenusApiController::class)
    ->where('handle', '[a-z0-9_-]+')
    ->name('pages.api.menus.show');
