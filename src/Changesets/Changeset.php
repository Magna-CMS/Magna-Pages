<?php

declare(strict_types=1);

namespace Magna\Pages\Changesets;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $status
 * @property string|null $author_id
 * @property Carbon|null $published_at
 */
class Changeset extends Model
{
    use HasUlids;

    public const STATUS_OPEN = 'open';

    public const STATUS_PUBLISHED = 'published';

    protected $table = 'pages_changesets';

    protected $fillable = ['name', 'status', 'author_id', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
