<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

use Magna\Blocks\BlockRegistry;
use Magna\Blocks\PageTree;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Content\Entry;

/**
 * Renders a page entry's block document to public HTML.
 *
 * v1 of the render pipeline: parse the stored document through the tolerant
 * PageTree layer, resolve dynamic block data through the shared resolve seam
 * (BlockDataResolver — entries queries, sanitized richtext), and compose the
 * core block views inside the plugin's layout shell. Theme view resolution,
 * template documents, per-page asset aggregation, and the pages_cache
 * subsystem build on top of this class
 * (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §B1/§B3).
 *
 * Unknown block handles render nothing on the public site — a disabled
 * plugin degrades a block, never breaks the page.
 */
final class PageRenderer
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly BlockDataResolver $resolver,
    ) {}

    public function render(Entry $page): string
    {
        $document = $page->getAttribute('blocks_data');
        $tree = PageTree::fromArray(is_array($document) ? $document : []);

        $title = $page->getAttribute('title');

        return view('magna-pages::page', [
            'title' => is_string($title) ? $title : '',
            'tree' => $tree,
            'registry' => $this->registry,
            'resolver' => $this->resolver,
        ])->render();
    }
}
