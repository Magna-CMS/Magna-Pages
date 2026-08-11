<?php

declare(strict_types=1);

namespace Magna\Pages\Comments;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $page_id
 * @property string|null $node_id
 * @property string|null $author_id
 * @property string $body
 * @property Carbon|null $resolved_at
 */
class PageComment extends Model
{
    use HasUlids;

    protected $table = 'pages_comments';

    protected $fillable = ['page_id', 'node_id', 'author_id', 'body', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
