<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Illuminate\Contracts\Auth\Authenticatable;
use Magna\Blocks\Contracts\GuardsDocumentEdits;
use Magna\Users\User;

/**
 * The core BlockEditor's view of the builder lock (§E2: two editors, one
 * lock). Thin by design — the LockManager owns every rule; this only
 * translates between the core contract's shape and it.
 */
final class LivewireEditGuard implements GuardsDocumentEdits
{
    public function __construct(private readonly LockManager $locks) {}

    public function acquire(string $entryId, ?Authenticatable $user): array
    {
        if (! $user instanceof User) {
            // System contexts (imports, CLI) contend with nobody.
            return ['mine' => true, 'holderName' => null];
        }

        $lock = $this->locks->acquire($entryId, $user);

        if ($lock->user_id === $user->getKey()) {
            return ['mine' => true, 'holderName' => null];
        }

        $holder = User::query()->find($lock->user_id);

        return ['mine' => false, 'holderName' => $holder?->name ?? 'Another editor'];
    }

    public function holds(string $entryId, ?Authenticatable $user): bool
    {
        return $user instanceof User && $this->locks->holds($entryId, $user);
    }
}
