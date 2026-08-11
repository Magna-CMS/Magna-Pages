<?php

declare(strict_types=1);

namespace Magna\Pages\Blocks;

use Magna\Blocks\Resolution\ResolvesBlockData;

/**
 * The curated embed block (docs/magna-pages/07-EXTENSIBILITY.md §5 rules):
 * a small allowlist of providers, never "iframe any URL" — an arbitrary
 * embed is an arbitrary third-party document in the page.
 *
 * The pasted URL is PARSED, never emitted: the provider and video id are
 * extracted against strict patterns and the embed src is rebuilt from
 * constants + id, so nothing an editor pastes reaches the iframe as-is.
 * YouTube embeds go through the nocookie host. An unrecognised URL
 * resolves to nothing — a gap, not a hole.
 */
final class EmbedBlockResolver implements ResolvesBlockData
{
    /** provider => [url pattern (id in group 1), embed src prefix] */
    private const PROVIDERS = [
        'youtube' => [
            '~^https://(?:www\.)?(?:youtube\.com/watch\?(?:[^\s#]*&)?v=|youtu\.be/)([A-Za-z0-9_-]{5,20})~',
            'https://www.youtube-nocookie.com/embed/',
        ],
        'vimeo' => [
            '~^https://(?:www\.)?vimeo\.com/(\d{5,15})(?:$|[?/#])~',
            'https://player.vimeo.com/video/',
        ],
    ];

    public function handle(): string
    {
        return 'embed';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data): array
    {
        $url = $data['url'] ?? null;
        if (! is_string($url)) {
            return [];
        }

        foreach (self::PROVIDERS as $provider => [$pattern, $embedPrefix]) {
            if (preg_match($pattern, trim($url), $m) === 1) {
                return [
                    'provider' => $provider,
                    'embedUrl' => $embedPrefix.$m[1],
                ];
            }
        }

        return [];
    }
}
