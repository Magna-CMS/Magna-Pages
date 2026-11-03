<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

/**
 * What a head provider contributes to a rendered page's `<head>`: ready-to-print
 * HTML, and which of the layout's own tags it makes redundant.
 *
 * The second half is what keeps a page from carrying two titles. A theme layout
 * prints a title, a description, hreflang links and an icon of its own; when a
 * provider (Magna SEO, typically) prints the same thing, the layout skips its
 * version by checking `$headProvides['title']` and friends. A layout that has
 * never heard of the flags keeps printing everything — and also never prints
 * `$headHtml`, so it cannot duplicate anything either.
 */
final readonly class DocumentHeadFragment
{
    /** The keys a provider may claim. Anything else is ignored. */
    public const KEYS = ['title', 'description', 'alternates', 'icon', 'canonical'];

    /** @var array<string, bool> */
    public array $provides;

    /**
     * @param  string  $html  Trusted, already-escaped markup for `<head>`.
     * @param  array<string, bool>  $provides  key => true for each tag the layout should not print itself
     */
    public function __construct(
        public string $html = '',
        array $provides = [],
    ) {
        $clean = [];
        foreach (self::KEYS as $key) {
            $clean[$key] = ($provides[$key] ?? false) === true;
        }

        $this->provides = $clean;
    }

    public static function empty(): self
    {
        return new self;
    }

    /**
     * Two fragments as one: markup in order, a tag provided when either provides it.
     */
    public function merge(self $other): self
    {
        $provides = [];
        foreach (self::KEYS as $key) {
            $provides[$key] = $this->provides[$key] || $other->provides[$key];
        }

        $html = $this->html === '' ? $other->html : ($other->html === '' ? $this->html : $this->html."\n".$other->html);

        return new self($html, $provides);
    }
}
