<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $entry_id
 * @property string $user_id
 * @property Carbon $acquired_at
 * @property Carbon $heartbeat_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class DocumentLock extends Model
{
    use HasUlids;

    protected $table = 'pages_document_locks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'acquired_at' => 'datetime',
            'heartbeat_at' => 'datetime',
        ];
    }

    /**
     * A lock whose editor has gone quiet is stale — a crashed browser never
     * says goodbye, so silence is the release.
     */
    public function isStale(int $ttlSeconds): bool
    {
        return $this->heartbeat_at->addSeconds($ttlSeconds)->isPast();
    }
}
