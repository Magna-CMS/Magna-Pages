<?php

declare(strict_types=1);

namespace Magna\Pages;

use Magna\Blocks\Contracts\ProvidesDocumentPreview;

/**
 * Pages' implementation of core's preview binding: the editor's live
 * preview posts to the themed preview endpoint.
 */
final class ThemedDocumentPreview implements ProvidesDocumentPreview
{
    public function previewUrl(): string
    {
        // url() rather than route(): plugin routes register at runtime on
        // enable, and Laravel's route-name lookup table refreshes lazily —
        // the path is the stable identity here.
        return url('/pages-preview');
    }
}
