<?php

declare(strict_types=1);

namespace Magna\Pages\Routing;

use Magna\Frontend\LocaleUrls;

/**
 * The URL locale-prefix strategy (docs/magna-pages §A2): the fallback
 * locale lives at bare paths, every other AVAILABLE locale under its
 * prefix — /about and /fr/about.
 *
 * Core owns the rule since Magna 1.6.2 so every plugin emitting a public
 * URL prefixes it exactly the way this router strips it; this class stays
 * so code that resolved it keeps working.
 *
 * @deprecated 0.5.0-alpha Use Magna\Frontend\LocaleUrls.
 */
final class LocalePrefix
{
    public function __construct(private readonly LocaleUrls $urls) {}

    /**
     * @return array{locale: string|null, path: string} locale null = the
     *                                                  fallback locale, path already stripped
     */
    public function split(string $path): array
    {
        return $this->urls->split($path);
    }

    /** The locale actually being served (fallback when unprefixed). */
    public function effective(?string $locale): string
    {
        return $this->urls->effective($locale);
    }

    /** The site-relative URL for a path in the given locale. */
    public function urlFor(string $locale, string $path): string
    {
        return $this->urls->urlFor($locale, $path);
    }
}
