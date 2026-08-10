<?php

declare(strict_types=1);

namespace Magna\Pages\Library;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Magna\Marketplace\Marketplace;

/**
 * The hardened egress client for the cloud library (§C10): the ONLY code in
 * this plugin that talks to the hub, so every rule about that conversation
 * lives in one place.
 *
 * Rules: the baked-in hub origin only (same stance as licensing — an
 * operator who could repoint it could feed the builder arbitrary
 * documents); JSON only; short timeout; no redirects followed; browse
 * responses cached briefly so an open Add panel does not hammer the hub;
 * a hub outage degrades to an empty library, never to a broken builder.
 */
class LibraryClient
{
    private const TIMEOUT_SECONDS = 6;

    private const BROWSE_CACHE_SECONDS = 300;

    /**
     * @return list<array<string, mixed>>
     */
    public function assets(string $kind = '', string $search = '', string $sort = ''): array
    {
        // Searches bypass the cache (too many key shapes to be worth it);
        // plain browses share one entry per kind+sort.
        $fetch = fn (): array => $this->get('/library', array_filter([
            'kind' => $kind,
            'q' => $search,
            'sort' => $sort,
        ], fn (string $value): bool => $value !== ''))['assets'] ?? [];

        if ($search !== '') {
            return $this->listOf($fetch());
        }

        /** @var list<array<string, mixed>> */
        return Cache::remember(
            'magna.pages.library.'.$kind.'.'.$sort,
            self::BROWSE_CACHE_SECONDS,
            fn (): array => $this->listOf($fetch()),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collections(): array
    {
        /** @var list<array<string, mixed>> */
        return Cache::remember(
            'magna.pages.library.collections',
            self::BROWSE_CACHE_SECONDS,
            fn (): array => $this->listOf($this->get('/library/collections')['collections'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function collection(string $slug): ?array
    {
        $payload = $this->get('/library/collections/'.rawurlencode($slug));

        return $payload === [] ? null : $payload;
    }

    /**
     * The full asset, document included. Never cached — fetching IS the
     * download count, and an instance is about to be freshened anyway.
     *
     * @return array<string, mixed>|null
     */
    public function asset(string $slug): ?array
    {
        $payload = $this->get('/library/'.rawurlencode($slug));

        return isset($payload['document']) && is_array($payload['document']) ? $payload : null;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<mixed, mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->acceptJson()
                ->get(Marketplace::API_BASE.$path, $query);

            if (! $response->ok()) {
                return [];
            }

            $json = $response->json();

            return is_array($json) ? $json : [];
        } catch (\Throwable) {
            // Hub unreachable: the library browses empty, the builder works.
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
