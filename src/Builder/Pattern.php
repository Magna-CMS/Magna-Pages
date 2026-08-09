<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $kind
 * @property array<mixed, mixed> $document
 * @property string|null $created_by
 */
final class Pattern extends Model
{
    use HasUlids;

    public const KIND_SECTION = 'section';

    public const KIND_BLOCK = 'block';

    protected $table = 'pages_patterns';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['document' => 'array'];
    }
}
