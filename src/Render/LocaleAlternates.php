<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Frontend\LocaleUrls;

/**
 * A page's published translations as locale => site-relative URL — what the
 * locale-switcher block and the layout's hreflang links render. Empty for
 * anything that is not a translated entry.
 */
final class LocaleAlternates
{
    public function __construct(private readonly LocaleUrls $urls) {}

    /**
     * @return array<string, string> locale => site-relative URL
     */
    public function forEntry(?Entry $context): array
    {
        $group = $context?->translation_group;
        $handle = $context?->getHandle();
        if (! is_string($group) || $group === '' || ! is_string($handle)) {
            return [];
        }

        $fallback = $this->urls->fallback();

        $alternates = [];
        foreach (Entry::type($handle)
            ->where('translation_group', $group)
            ->where('status', EntryStatus::Published->value)
            ->orderBy('locale')
            ->get() as $sibling) {
            $locale = $sibling->getAttribute('locale');
            // A row with no locale recorded IS the fallback locale
            // (EntryManager stores '' when a create names none).
            $locale = is_string($locale) && $locale !== '' ? $locale : $fallback;
            $path = $sibling->getAttribute('path') ?? $sibling->getAttribute('slug');
            if (is_string($path) && $path !== '') {
                $alternates[$locale] = $this->urls->urlFor($locale, $path);
            }
        }

        return $alternates;
    }
}
