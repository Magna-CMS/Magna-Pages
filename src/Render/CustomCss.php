<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

/**
 * Per-node custom CSS (docs/magna-pages/08-BUILD-PHASES.md item C9): a
 * DECLARATION LIST only — `margin-top: 2rem; letter-spacing: 0.1em` — that
 * renders into the node's style attribute. The emission target is the
 * sandbox: a style attribute cannot carry selectors, @rules, or nesting,
 * so an editor styles their own node and nothing else.
 *
 * Validation happens at RENDER (the document is editor input, never an
 * authorization) with the same posture as SafeUrl: parse what we
 * understand, drop everything else. Function calls are refused wholesale —
 * url() is an exfiltration channel and the rest aren't worth
 * distinguishing — with var(--token) as the one allowed exception, since
 * design tokens are the system's own currency.
 */
final class CustomCss
{
    private const MAX_LENGTH = 2000;

    /**
     * The safe subset of the given declaration list, as
     * `prop:value;prop:value` (empty string when nothing survives).
     */
    public static function sanitize(mixed $declarations): string
    {
        if (! is_string($declarations) || $declarations === '' || strlen($declarations) > self::MAX_LENGTH) {
            return '';
        }

        $safe = [];
        foreach (explode(';', $declarations) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if (preg_match('/^-?[a-z][a-z-]*$/', $property) !== 1) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            /*
             * url() is an exfiltration channel, so it is allowed in exactly
             * one shape and no other: the WHOLE value, one function, a
             * double-quoted relative path or http(s) URL carrying nothing
             * that could close the quote, the function, or the declaration.
             *
             * Anchored rather than erased on purpose. An erasing pass would
             * also accept `url("/a.png"), url("/b.png")` — valid CSS, but
             * not something the style descriptors ever build, and the value
             * of a narrow exception is that it stays narrow.
             *
             * This is not a widening of what an author may express: an
             * image block already puts any remote URL on a page, and only
             * the design tier can set a page background at all.
             */
            if (preg_match('/^url\("(?:\/(?!\/)|https?:\/\/)[^"()<>;{}\s\\\\\']*"\)$/i', $value) === 1) {
                $safe[] = $property.':'.$value;

                continue;
            }

            // Every other function is refused except var(--token), which is
            // the system's own currency. Erased first so that any
            // parenthesis left over is a refusal.
            $withoutFunctions = preg_replace('/var\(--[a-z0-9-]+\)/i', '', $value);
            if ($withoutFunctions === null) {
                continue;
            }

            // No braces, angle brackets, @rules, comments, escapes, quotes,
            // or control characters — none of them belong in a declaration
            // value, all of them are how attacks start.
            if (preg_match('/[{}<>@\\\\\'"]|\/\*|[\x00-\x1f]/', $withoutFunctions) === 1) {
                continue;
            }

            // Any parenthesis left after the two allowed functions were
            // erased is a function this does not permit.
            if (str_contains($withoutFunctions, '(') || str_contains($withoutFunctions, ')')) {
                continue;
            }

            $safe[] = $property.':'.$value;
        }

        return implode(';', $safe);
    }
}
