<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Magna\Pages\Http\Controllers\PageController;
use Magna\Pages\Http\Controllers\PagePreviewController;

/*
 * The public site mounts as the application FALLBACK route: it only fires
 * when no explicitly registered route (admin panel, API, other plugins)
 * matched. That is the plan's routing precedence — registered routes always
 * beat the Pages catch-all (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §E3) —
 * implemented with Laravel's own lowest-priority mechanism instead of
 * registration-order luck.
 */
// Themed live preview for the block editor (auth + blocks.preview gate in
// the controller; session/CSRF via the web group).
Route::post('/pages-preview', PagePreviewController::class)
    ->middleware('auth')
    ->name('pages.web.preview');

Route::fallback(PageController::class)->name('pages.web.show');
