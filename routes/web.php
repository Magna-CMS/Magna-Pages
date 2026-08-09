<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Magna\Pages\Http\Controllers\BuilderApiController;
use Magna\Pages\Http\Controllers\BuilderCanvasController;
use Magna\Pages\Http\Controllers\BuilderSpaController;
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

/*
 * Builder management API. On the web group on purpose: the builder is an
 * admin surface sharing the panel session and its CSRF protection, rather
 * than a second bearer credential with its own revocation story
 * (docs/magna-pages/01-ARCHITECTURE.md §4). Every endpoint gates on a
 * permission of its own; the session only establishes WHO is asking.
 */
Route::middleware('auth')->prefix('pages-builder')->group(function (): void {
    // Literal segments first: they must win over the {id} routes below,
    // which would otherwise swallow "registry", "app" and the rest.
    Route::get('/registry', [BuilderApiController::class, 'registry'])->name('pages.builder.registry');
    Route::get('/bridge.js', [BuilderCanvasController::class, 'bridge'])->name('pages.builder.bridge');
    Route::get('/app/{path}', [BuilderSpaController::class, 'asset'])
        ->where('path', '.*')
        ->name('pages.builder.asset');
    Route::get('/edit/{id}', [BuilderSpaController::class, 'index'])->name('pages.builder.edit');
    Route::get('/{id}', [BuilderApiController::class, 'bootstrap'])->name('pages.builder.bootstrap');
    Route::patch('/{id}', [BuilderApiController::class, 'patch'])->name('pages.builder.patch');
    Route::get('/{id}/canvas', [BuilderCanvasController::class, 'canvas'])->name('pages.builder.canvas');
    Route::post('/{id}/fragment', [BuilderCanvasController::class, 'fragment'])->name('pages.builder.fragment');
    Route::post('/{id}/heartbeat', [BuilderApiController::class, 'heartbeat'])->name('pages.builder.heartbeat');
    Route::post('/{id}/take-over', [BuilderApiController::class, 'takeOver'])->name('pages.builder.take-over');
    Route::post('/{id}/release', [BuilderApiController::class, 'release'])->name('pages.builder.release');
    Route::post('/{id}/publish', [BuilderApiController::class, 'publish'])->name('pages.builder.publish');
});

Route::fallback(PageController::class)->name('pages.web.show');
