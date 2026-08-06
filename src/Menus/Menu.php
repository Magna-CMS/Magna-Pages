<?php

declare(strict_types=1);

namespace Magna\Pages\Menus;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $handle
 * @property string $name
 */
class Menu extends Model
{
    use HasUlids;

    protected $table = 'pages_menus';

    protected $fillable = ['handle', 'name'];

    /** @return HasMany<MenuItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'menu_id')
            ->orderBy('position');
    }
}
