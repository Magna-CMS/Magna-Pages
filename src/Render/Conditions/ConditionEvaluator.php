<?php

declare(strict_types=1);

namespace Magna\Pages\Render\Conditions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Magna\Blocks\Conditions\DisplayConditionRegistry;

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
    /*
     * Anything beyond the two built-ins is a plugin's, through
     * RegistersDisplayConditions. Injected rather than resolved inline so
     * this stays testable with an empty registry — which is also what an
     * install with no condition-providing plugin actually has.
     */
    public function __construct(private readonly DisplayConditionRegistry $conditions) {}

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
                // Anything else may be a plugin's, and is still unknown if no
                // plugin claims it — see fromRegistry().
                default => $this->fromRegistry($condition, $user, $now),
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
     * A condition type a plugin registered, or nothing we can evaluate.
     *
     * The two built-ins above are matched first and deliberately cannot be
     * replaced: `auth` and `schedule` are what the page cache's own reasoning
     * is built on, and a plugin redefining either could quietly make every
     * cached page wrong.
     *
     * A resolver that throws is treated exactly as an unknown type. A plugin
     * failing mid-render must not be a way to make gated content appear, and
     * the page it is on cannot be cached either — we have no idea what the
     * answer should have been.
     *
     * @param  array<mixed, mixed>  $condition
     */
    private function fromRegistry(array $condition, ?Authenticatable $user, Carbon $now): ConditionOutcome
    {
        /** @var string $type */
        $type = $condition['type'];

        $handler = $this->conditions->get($type);

        if ($handler === null) {
            return ConditionOutcome::hiddenUncacheable();
        }

        try {
            $verdict = $handler->evaluate($condition, $user, $now);
        } catch (\Throwable $failure) {
            report($failure);

            return ConditionOutcome::hiddenUncacheable();
        }

        return new ConditionOutcome($verdict->visible, $verdict->cacheable, $verdict->expiresAt);
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
