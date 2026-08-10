<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $entry_id
 * @property string $requested_by
 * @property string|null $note
 * @property string $status
 * @property string|null $resolved_by
 * @property string|null $resolution_note
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PublishRequest extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RETURNED = 'returned';

    protected $table = 'pages_publish_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
