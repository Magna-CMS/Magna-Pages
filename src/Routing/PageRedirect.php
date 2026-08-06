<?php

declare(strict_types=1);

namespace Magna\Pages\Routing;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $source_path
 * @property string $target_path
 * @property int $status
 * @property string $locale
 * @property bool $automatic
 */
class PageRedirect extends Model
{
    use HasUlids;

    protected $table = 'pages_redirects';

    protected $fillable = [
        'source_path', 'target_path', 'status', 'locale', 'automatic',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'automatic' => 'boolean',
        ];
    }
}
