<?php

declare(strict_types=1);

namespace Magna\Pages\Render\Conditions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Magna\Blocks\DynamicTags\DynamicTag;
use Magna\Blocks\DynamicTags\DynamicTagRegistry;

/**
 * The document-level cache verdict: walk every node once and fold what it
 * declares into one answer the page cache can act on — may this render be
 * shared at all, and until when is it true. Two things get a veto:
 *
 *  - §C9 display conditions (audience makes a render user-specific, a
 *    schedule bounds its life, an unknown rule type fails closed);
 *  - dynamic-tag bindings (`{"$bind": "tag.<handle>"}`): a tag declaring
 *    CACHE_USER varies per visitor, and a tag we cannot find might — both
 *    make the page uncacheable. CACHE_STATIC / CACHE_PAGE tags are
 *    shared-cache safe by declaration.
 *
 * Visibility is per-node business handled at render; THIS pass exists so
 * the caching decision never depends on which nodes happened to show.
 */
class DocumentConditions
{
    public function __construct(
        private readonly ConditionEvaluator $evaluator,
        private readonly DynamicTagRegistry $tags,
    ) {}

    /**
     * @param  array<mixed, mixed>  $document
     * @return array{cacheable: bool, expiresAt: Carbon|null}
     */
    public function cacheVerdict(array $document, ?Authenticatable $user, Carbon $now): array
    {
        $cacheable = true;
        $expiresAt = null;

        $walk = function (mixed $node) use (&$walk, &$cacheable, &$expiresAt, $user, $now): void {
            if (! is_array($node)) {
                return;
            }

            $settings = $node['settings'] ?? null;
            if (is_array($settings) && isset($settings['conditions'])) {
                $outcome = $this->evaluator->evaluate($settings, $user, $now);
                $cacheable = $cacheable && $outcome->cacheable;
                if ($outcome->expiresAt !== null
                    && ($expiresAt === null || $outcome->expiresAt->lessThan($expiresAt))
                ) {
                    $expiresAt = $outcome->expiresAt;
                }
            }

            $data = $node['data'] ?? null;
            if (is_array($data)) {
                foreach ($data as $value) {
                    if (is_array($value) && isset($value['$bind']) && is_string($value['$bind'])
                        && str_starts_with($value['$bind'], 'tag.')
                        && ! $this->tagBindIsCacheSafe(substr($value['$bind'], 4))
                    ) {
                        $cacheable = false;
                    }
                }
            }

            foreach (['sections', 'columns', 'blocks', 'children'] as $container) {
                if (isset($node[$container]) && is_array($node[$container])) {
                    foreach ($node[$container] as $child) {
                        $walk($child);
                    }
                }
            }
        };

        if (array_is_list($document)) {
            foreach ($document as $section) {
                $walk($section);
            }
        } else {
            $walk($document);
        }

        return ['cacheable' => $cacheable, 'expiresAt' => $expiresAt];
    }

    /**
     * A missing tag fails closed: today it renders an empty string, but the
     * plugin providing it may come back — a cached copy of the gap would
     * then outlive the recovery.
     */
    private function tagBindIsCacheSafe(string $handle): bool
    {
        $tag = $this->tags->get($handle);

        return $tag !== null && $tag->cacheability() !== DynamicTag::CACHE_USER;
    }
}
