<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Magna\Pages\Render\StyleDescriptors;

/**
 * The page's own settings — the ground its sections are drawn on
 * (docs/magna-pages/13-BUILDER-CHROME-AND-CANVAS.md §4 F1).
 *
 * Stored beside the document rather than inside it, deliberately. The
 * document is a LIST of sections and the patch path owns it; a page-level
 * key smuggled into that list would be a node that is not a node, and
 * every walker would have to learn to skip it. A separate field is also
 * what makes page settings survive a tree edit cleanly, which is the same
 * conclusion Elementor reached with post meta.
 *
 * The values are the style vocabulary the server already publishes, so
 * this class does not invent a schema: it filters what arrives down to
 * keys the PAGE kind actually declares and lets the descriptor table
 * decide what any of them may say.
 */
final class PageSettings
{
    /**
     * Take a submitted settings object down to what the vocabulary allows.
     *
     * Unknown keys are DROPPED rather than rejected, so a builder one
     * version ahead of its server cannot make a save fail — the server
     * simply stores what it understands, which is the same contract the
     * style descriptor table gives every other node.
     *
     * @param  array<mixed, mixed>  $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $allowed = array_column(StyleDescriptors::forKind(StyleDescriptors::PAGE), 'key');

        $style = $input['style'] ?? null;
        if (! is_array($style)) {
            return [];
        }

        $clean = [];
        foreach ($style as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                continue;
            }

            // Scalars and the per-device sentinel, nothing else. An array
            // that is not a `$responsive` set is not a style value, and
            // storing one would only produce something the emitter drops.
            if (is_string($value) || is_int($value) || is_float($value)) {
                $clean[$key] = $value;

                continue;
            }

            if (is_array($value) && isset($value['$responsive']) && is_array($value['$responsive'])) {
                $devices = [];
                foreach (['base', 'tablet', 'mobile'] as $device) {
                    $at = $value['$responsive'][$device] ?? null;
                    if (is_string($at) || is_int($at) || is_float($at)) {
                        $devices[$device] = $at;
                    }
                }

                if ($devices !== []) {
                    $clean[$key] = ['$responsive' => $devices];
                }
            }
        }

        // An empty style set is stored as nothing at all, so a page that
        // has had its settings cleared renders byte-identically to a page
        // that never had any.
        return $clean === [] ? [] : ['style' => $clean];
    }

    /**
     * The stylesheet for a page's settings, or an empty string.
     *
     * Emitted onto `body` with the document's own stylesheet rather than
     * into the head, so a theme cannot forget to print it — the same
     * reasoning that put the structural utilities there. Per-device values
     * ride the existing responsive emitter, so all breakpoints land in one
     * body and every visitor is served identical bytes.
     *
     * @param  array<mixed, mixed>|null  $settings
     */
    public static function css(?array $settings): string
    {
        $style = $settings['style'] ?? null;
        if (! is_array($style) || $style === []) {
            return '';
        }

        $base = StyleDescriptors::declarations($style, StyleDescriptors::PAGE);
        $css = $base === '' ? '' : 'body{'.$base.'}';

        foreach (['tablet' => '1023.98px', 'mobile' => '767.98px'] as $device => $width) {
            $declarations = StyleDescriptors::declarations($style, StyleDescriptors::PAGE, $device);
            if ($declarations === '') {
                continue;
            }

            $css .= '@media (max-width: '.$width.'){body{'.$declarations.'}}';
        }

        return $css;
    }
}
