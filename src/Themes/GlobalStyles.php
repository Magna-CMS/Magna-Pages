<?php

declare(strict_types=1);

namespace Magna\Pages\Themes;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $theme
 * @property array<string, string> $tokens
 * @property string|null $updated_by
 */
final class GlobalStyles extends Model
{
    use HasUlids;

    protected $table = 'pages_global_styles';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tokens' => 'array'];
    }
}
