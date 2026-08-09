<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

/**
 * Tags rendered nodes so the builder canvas can map a click back to a
 * document node (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §B5).
 *
 * Markers are ATTRIBUTES on the element a block already renders — never a
 * new wrapper element. A wrapper (even `display: contents`) changes the DOM
 * shape, which breaks `>` selectors and makes the canvas subtly different
 * from the published page. Since the whole point of rendering the canvas
 * with the production renderer is that what you see is what ships, adding
 * structure only in builder mode would reintroduce exactly the drift this
 * architecture exists to prevent.
 *
 * Only when a block renders no element at all (bare text) does a wrapper
 * appear, because there is then nothing to attach to.
 */
final class BuilderMarkup
{
    /**
     * Attach node markers to the outermost element of a rendered fragment.
     */
    public function mark(string $html, string $nodeId, string $kind): string
    {
        $attributes = sprintf(
            ' data-magna-node="%s" data-magna-kind="%s"',
            e($nodeId),
            e($kind),
        );

        $offset = $this->firstElementOffset($html);

        if ($offset === null) {
            // Nothing to hang an attribute on. A wrapper is the lesser evil
            // here: a block with no element of its own has no layout of its
            // own to disturb.
            return $html === ''
                ? $html
                : '<span'.$attributes.' style="display:contents">'.$html.'</span>';
        }

        // Insert immediately after the tag name, before any existing
        // attributes, so a self-closing or attribute-carrying tag both work.
        return substr($html, 0, $offset).$attributes.substr($html, $offset);
    }

    /**
     * Byte offset just past the tag NAME of the first real element, or null
     * when the fragment contains none. Comments and doctypes are skipped —
     * a Blade view that opens with a comment is normal.
     */
    private function firstElementOffset(string $html): ?int
    {
        $position = 0;
        $length = strlen($html);

        while ($position < $length) {
            $open = strpos($html, '<', $position);
            if ($open === false) {
                return null;
            }

            if (substr($html, $open, 4) === '<!--') {
                $close = strpos($html, '-->', $open);
                if ($close === false) {
                    return null;
                }
                $position = $close + 3;

                continue;
            }

            if (substr($html, $open, 2) === '<!' || substr($html, $open, 2) === '</') {
                $close = strpos($html, '>', $open);
                if ($close === false) {
                    return null;
                }
                $position = $close + 1;

                continue;
            }

            if (preg_match('/\G<([a-zA-Z][a-zA-Z0-9-]*)/', $html, $matches, 0, $open) === 1) {
                return $open + 1 + strlen($matches[1]);
            }

            $position = $open + 1;
        }

        return null;
    }
}
