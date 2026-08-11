<?php

declare(strict_types=1);

namespace Magna\Pages\Consent;

use Magna\Pages\PagesSettings;

/**
 * The consent registry's render half (§F): registered integrations emit
 * before </body>. `necessary` scripts load normally; consent categories
 * render INERT (no src, just data attributes) and a small inline runtime
 * activates them only after the visitor consents — the choice lives in
 * localStorage, so the shared page cache stays shared and no consent
 * state is ever baked into HTML.
 *
 * Only https script URLs pass; anything else is dropped on read, so a
 * settings row cannot smuggle javascript: or protocol-relative sources.
 */
class ConsentScripts
{
    private const CATEGORIES = ['necessary', 'analytics', 'marketing'];

    /** The scripts + banner + runtime, or null when nothing is registered. */
    public function render(): ?string
    {
        $integrations = $this->validIntegrations();
        if ($integrations === []) {
            return null;
        }

        $needsConsent = array_filter(
            $integrations,
            fn (array $i): bool => $i['category'] !== 'necessary',
        );

        return view('magna-pages::partials.consent', [
            'integrations' => $integrations,
            'needsConsent' => $needsConsent !== [],
        ])->render();
    }

    /**
     * @return list<array{handle: string, label: string, category: string, src: string}>
     */
    private function validIntegrations(): array
    {
        $valid = [];
        foreach (PagesSettings::get()->integrations as $integration) {
            if (! is_array($integration)) {
                continue;
            }
            $handle = $integration['handle'] ?? null;
            $src = $integration['src'] ?? null;
            $category = $integration['category'] ?? null;
            if (! is_string($handle) || preg_match('/^[a-z0-9][a-z0-9-]*$/', $handle) !== 1
                || ! is_string($src) || ! str_starts_with($src, 'https://')
                || ! is_string($category) || ! in_array($category, self::CATEGORIES, true)
            ) {
                continue;
            }

            $valid[] = [
                'handle' => $handle,
                'label' => is_string($integration['label'] ?? null) ? $integration['label'] : $handle,
                'category' => $category,
                'src' => $src,
            ];
        }

        return $valid;
    }
}
