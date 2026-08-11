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

            // No braces, angle brackets, @rules, comments, escapes, or
            // control characters — none of them belong in a declaration
            // value, all of them are how attacks start.
            if ($value === '' || preg_match('/[{}<>@\\\\\'"]|\/\*|[\x00-\x1f]/', $value) === 1) {
                continue;
            }

            // Functions refused except var(--token). Checked by erasing
            // allowed var() calls first: any parenthesis left is a refusal.
            $withoutVars = preg_replace('/var\(--[a-z0-9-]+\)/i', '', $value);
            if ($withoutVars === null || str_contains($withoutVars, '(') || str_contains($withoutVars, ')')) {
                continue;
            }

            $safe[] = $property.':'.$value;
        }

        return implode(';', $safe);
    }
}
