<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Magna\Pages\Http\Controllers\PageController;

/*
 * The public site mounts as the application FALLBACK route: it only fires
 * when no explicitly registered route (admin panel, API, other plugins)
 * matched. That is the plan's routing precedence — registered routes always
 * beat the Pages catch-all (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §E3) —
 * implemented with Laravel's own lowest-priority mechanism instead of
 * registration-order luck.
 */
Route::fallback(PageController::class)->name('pages.web.show');
