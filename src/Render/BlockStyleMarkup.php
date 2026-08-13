<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

/**
 * Puts a styling class on a block's OWN outermost element.
 *
 * A block renders its own markup, so styling it needs a hook in that
 * markup — and the only safe hook is its class attribute, MERGED rather
 * than added. Injecting a second `class` (the way BuilderMarkup can
 * safely add `data-` attributes, which never collide) would silently win
 * over the block's own: the HTML parser keeps the first occurrence and
 * discards the rest, so a block that styles itself would lose its
 * styling the moment an editor set a padding on it.
 *
 * Nothing here runs unless a block actually has styles. A block with none
 * renders exactly the markup its view produced.
 */
final class BlockStyleMarkup
{
    /**
     * The same fragment with `$class` merged into its first element.
     *
     * A fragment with no element at all is returned untouched — there is
     * nothing to hang a class on, and wrapping it would change the DOM
     * shape the canvas promises to keep identical to production.
     */
    public static function withClass(string $html, string $class): string
    {
        if ($html === '' || $class === '') {
            return $html;
        }

        $open = self::firstTagRange($html);
        if ($open === null) {
            return $html;
        }

        [$start, $end] = $open;
        $tag = substr($html, $start, $end - $start);

        // An existing class attribute is extended in place. The pattern is
        // anchored on an attribute BOUNDARY (whitespace before, `=` after)
        // so `data-foo="class=x"` is not mistaken for one.
        $merged = preg_replace(
            '/(\sclass\s*=\s*)(["\'])(.*?)\2/is',
            '$1$2$3 '.$class.'$2',
            $tag,
            1,
            $count,
        );

        if ($merged !== null && $count === 1) {
            return substr($html, 0, $start).$merged.substr($html, $end);
        }

        // No class attribute: add one immediately after the tag name, which
        // is valid for a self-closing tag and one carrying attributes alike.
        $nameEnd = self::tagNameEnd($html, $start);

        return $nameEnd === null
            ? $html
            : substr($html, 0, $nameEnd).' class="'.$class.'"'.substr($html, $nameEnd);
    }

    /**
     * Byte range of the first real element's opening tag, or null when the
     * fragment contains none. Comments and doctypes are skipped — a view
     * that opens with a comment is normal.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function firstTagRange(string $html): ?array
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

            if (preg_match('/\G<[a-zA-Z][a-zA-Z0-9-]*/', $html, $matches, 0, $open) === 1) {
                $close = strpos($html, '>', $open);

                return $close === false ? null : [$open, $close + 1];
            }

            $position = $open + 1;
        }

        return null;
    }

    /** Byte offset just past the tag NAME of the element starting at $open. */
    private static function tagNameEnd(string $html, int $open): ?int
    {
        return preg_match('/\G<([a-zA-Z][a-zA-Z0-9-]*)/', $html, $matches, 0, $open) === 1
            ? $open + 1 + strlen($matches[1])
            : null;
    }
}
