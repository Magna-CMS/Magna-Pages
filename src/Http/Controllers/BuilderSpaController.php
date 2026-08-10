<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Magna\Pages\Builder\FindsDocuments;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Serves the built builder SPA.
 *
 * The bundle is committed under public/builder, so a Magna install runs the
 * builder without Node — the same arrangement the other plugin SPAs use.
 *
 * The shell is rendered per page rather than served as a static file: the
 * page id and the session's CSRF token are stamped into it, so the app knows
 * what it is editing and can write without a bootstrap request just to learn
 * its own identity.
 */
final class BuilderSpaController
{
    use FindsDocuments;

    private const ASSET_TYPES = [
        'js' => 'text/javascript',
        'css' => 'text/css',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'json' => 'application/json',
    ];

    public function index(string $id): Response
    {
        Gate::authorize('pages.content');

        $entry = $this->findDocument($id);

        $index = $this->publicPath('index.html');

        abort_unless(
            is_file($index),
            HttpResponse::HTTP_NOT_FOUND,
            'The Magna Pages builder has not been built. Run npm run build in resources/app.',
        );

        $html = (string) file_get_contents($index);

        $html = str_replace(
            '<div id="magna-builder" data-page="">',
            '<meta name="csrf-token" content="'.e(csrf_token()).'">'
                .'<div id="magna-builder" data-page="'.e((string) $entry->getKey()).'">',
            $html,
        );

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            // The shell names hashed assets and carries a CSRF token — it must
            // never be cached, by the browser or anything between.
            ->header('Cache-Control', 'no-store, must-revalidate');
    }

    public function asset(string $path): BinaryFileResponse
    {
        Gate::authorize('pages.content');

        $real = realpath($this->publicPath($path));
        $root = realpath($this->publicPath(''));

        // realpath resolves any ".." before this comparison, so nothing
        // outside the bundle directory can be reached.
        abort_if(
            $real === false || $root === false || ! str_starts_with($real, $root),
            HttpResponse::HTTP_NOT_FOUND,
        );

        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));

        abort_unless(array_key_exists($extension, self::ASSET_TYPES), HttpResponse::HTTP_NOT_FOUND);

        return response()->file($real, [
            'Content-Type' => self::ASSET_TYPES[$extension],
            'Cache-Control' => str_starts_with($path, 'assets/')
                ? 'public, max-age=31536000, immutable'
                : 'no-cache',
        ]);
    }

    private function publicPath(string $path): string
    {
        return rtrim(dirname(__DIR__, 3).'/public/builder/'.$path, '/');
    }
}
