<?php

declare(strict_types=1);

namespace Magna\Pages\Render\Conditions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Display conditions (Phase C item 2): per-node show/hide rules stored in
 * `settings.conditions` as a list of `{type, ...}` objects, ALL of which
 * must pass for the node to render.
 *
 * The §C9 contract is the architecture here: every condition type declares
 * its CACHE behavior alongside its truth, because a shared page cache that
 * ignores conditions serves the wrong variant to somebody, silently.
 *
 *   - `auth` is cache-safe by construction: the shared cache only ever
 *     stores the GUEST render (per-user requests bypass it), so the cached
 *     copy is exactly the guest variant.
 *   - `schedule` is cacheable UNTIL its next boundary: the evaluation
 *     reports when its answer next changes, and the page cache expires
 *     then rather than serving yesterday's banner past its `until`.
 *   - an UNKNOWN type fails closed twice over: the node hides (content
 *     gated by a rule this install cannot evaluate must not leak), and the
 *     page becomes uncacheable (a rule we cannot evaluate is a rule whose
 *     cache behavior we cannot promise).
 */
class ConditionEvaluator
{
    /**
     * @param  array<mixed, mixed>  $settings  A node's settings array
     */
    public function evaluate(array $settings, ?Authenticatable $user, Carbon $now): ConditionOutcome
    {
        $conditions = $settings['conditions'] ?? null;
        if (! is_array($conditions) || $conditions === []) {
            return ConditionOutcome::visible();
        }

        $visible = true;
        $cacheable = true;
        $expiresAt = null;

        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! is_string($condition['type'] ?? null)) {
                return ConditionOutcome::hiddenUncacheable();
            }

            $result = match ($condition['type']) {
                'auth' => $this->auth($condition, $user),
                'schedule' => $this->schedule($condition, $now),
                default => ConditionOutcome::hiddenUncacheable(),
            };

            $visible = $visible && $result->visible;
            $cacheable = $cacheable && $result->cacheable;
            if ($result->expiresAt !== null && ($expiresAt === null || $result->expiresAt->lessThan($expiresAt))) {
                $expiresAt = $result->expiresAt;
            }
        }

        return new ConditionOutcome($visible, $cacheable, $expiresAt);
    }

    /**
     * @param  array<mixed, mixed>  $condition
     */
    private function auth(array $condition, ?Authenticatable $user): ConditionOutcome
    {
        $show = $condition['show'] ?? null;

        $visible = match ($show) {
            'guests' => $user === null,
            'authenticated' => $user !== null,
            default => false, // a malformed rule hides, never leaks
        };

        // Cache-safe: the shared cache stores only the guest render.
        return new ConditionOutcome($visible, cacheable: true, expiresAt: null);
    }

    /**
     * @param  array<mixed, mixed>  $condition
     */
    private function schedule(array $condition, Carbon $now): ConditionOutcome
    {
        $from = $this->parse($condition['from'] ?? null);
        $until = $this->parse($condition['until'] ?? null);

        $visible = ($from === null || $now->greaterThanOrEqualTo($from))
            && ($until === null || $now->lessThan($until));

        // The answer next changes at the nearest FUTURE boundary — that is
        // exactly how long a cached copy of this answer stays true.
        $boundaries = array_filter(
            [$from, $until],
            fn (?Carbon $boundary): bool => $boundary !== null && $boundary->greaterThan($now),
        );
        $next = $boundaries === [] ? null : min($boundaries);

        return new ConditionOutcome($visible, cacheable: true, expiresAt: $next);
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
