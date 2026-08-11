<?php

declare(strict_types=1);

namespace Magna\Pages\Performance;

use Magna\Blocks\PageTree;
use Magna\Content\Entry;
use Magna\Pages\Render\PageRenderer;

/**
 * Performance meter v1 (docs/magna-pages/08-BUILD-PHASES.md item C7): the
 * page rendered through the REAL pipeline and weighed — output size is
 * measured on what ships, never estimated from the document. Advisory
 * like the a11y checker: numbers plus notes, no gate.
 */
class PagePerformanceMeter
{
    /** Over this (gzipped) the page stops being "fast on any connection". */
    private const GZIP_BUDGET_BYTES = 100 * 1024;

    /** More blocks than this usually means the page should be split. */
    private const BLOCK_BUDGET = 80;

    public function __construct(private readonly PageRenderer $renderer) {}

    /**
     * @return array{metrics: array<string, int>, notes: list<array{code: string, message: string}>}
     */
    public function measure(Entry $entry): array
    {
        $html = $this->renderer->render($entry);
        $gzipped = gzencode($html, 6);

        $document = $entry->getAttribute('blocks_data');
        $tree = PageTree::fromArray(is_array($document) ? $document : []);

        $sectionCount = 0;
        $blockCount = 0;
        foreach ($tree->sections as $section) {
            if ($section->isRef()) {
                continue;
            }
            $sectionCount++;
            foreach ($section->columns as $column) {
                $blockCount += count($column->blocks);
            }
        }

        $imageCount = preg_match_all('/<img\b/i', $html);
        $imagesWithoutDimensions = preg_match_all('/<img\b(?![^>]*\bwidth=)[^>]*>/i', $html);

        $inlineStyleBytes = 0;
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $styles) > 0) {
            foreach ($styles[1] as $css) {
                $inlineStyleBytes += strlen($css);
            }
        }

        $metrics = [
            'htmlBytes' => strlen($html),
            'gzippedBytes' => $gzipped === false ? 0 : strlen($gzipped),
            'sectionCount' => $sectionCount,
            'blockCount' => $blockCount,
            'imageCount' => (int) $imageCount,
            'imagesWithoutDimensions' => (int) $imagesWithoutDimensions,
            'inlineStyleBytes' => $inlineStyleBytes,
        ];

        return ['metrics' => $metrics, 'notes' => $this->notes($metrics)];
    }

    /**
     * @param  array<string, int>  $metrics
     * @return list<array{code: string, message: string}>
     */
    private function notes(array $metrics): array
    {
        $notes = [];

        if ($metrics['gzippedBytes'] > self::GZIP_BUDGET_BYTES) {
            $notes[] = [
                'code' => 'page-weight',
                'message' => sprintf(
                    'The page is %dKB gzipped — over the %dKB budget. Long pages load slower everywhere; consider splitting.',
                    intdiv($metrics['gzippedBytes'], 1024),
                    intdiv(self::GZIP_BUDGET_BYTES, 1024),
                ),
            ];
        }

        if ($metrics['imagesWithoutDimensions'] > 0) {
            $notes[] = [
                'code' => 'image-dimensions',
                'message' => sprintf(
                    '%d image%s render without width/height attributes — the browser cannot reserve space, so content jumps while loading (layout shift).',
                    $metrics['imagesWithoutDimensions'],
                    $metrics['imagesWithoutDimensions'] === 1 ? '' : 's',
                ),
            ];
        }

        if ($metrics['blockCount'] > self::BLOCK_BUDGET) {
            $notes[] = [
                'code' => 'block-count',
                'message' => sprintf(
                    '%d blocks on one page (budget %d). Very long pages are slower to render, edit, and read — consider splitting.',
                    $metrics['blockCount'],
                    self::BLOCK_BUDGET,
                ),
            ];
        }

        return $notes;
    }
}
