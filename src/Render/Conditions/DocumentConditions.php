<?php

declare(strict_types=1);

namespace Magna\Pages\Render\Conditions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

/**
 * The document-level cache verdict: walk every node's conditions once and
 * fold their §C9 declarations into one answer the page cache can act on —
 * may this render be shared at all, and until when is it true.
 *
 * Visibility is per-node business handled at render; THIS pass exists so
 * the caching decision never depends on which nodes happened to show.
 */
class DocumentConditions
{
    public function __construct(private readonly ConditionEvaluator $evaluator) {}

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
}
