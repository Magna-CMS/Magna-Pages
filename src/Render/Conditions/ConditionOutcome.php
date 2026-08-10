<?php

declare(strict_types=1);

namespace Magna\Pages\Render\Conditions;

use Illuminate\Support\Carbon;

/**
 * What a condition evaluation decided: whether the node shows, whether the
 * page may still be cached, and when the answer next changes.
 */
final class ConditionOutcome
{
    public function __construct(
        public readonly bool $visible,
        public readonly bool $cacheable,
        public readonly ?Carbon $expiresAt,
    ) {}

    public static function visible(): self
    {
        return new self(true, true, null);
    }

    public static function hiddenUncacheable(): self
    {
        return new self(false, false, null);
    }
}
