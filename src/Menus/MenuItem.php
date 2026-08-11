<?php

declare(strict_types=1);

namespace Magna\Pages\Menus;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $menu_id
 * @property string|null $parent_id
 * @property int $position
 * @property string $label
 * @property string $type
 * @property string|null $page_id
 * @property string|null $url
 * @property string|null $target
 * @property array<string, mixed>|null $settings
 */
class MenuItem extends Model
{
    use HasUlids;

    public const TYPE_URL = 'url';

    public const TYPE_PAGE = 'page';

    /** A plugin frontend page (ProvidesFrontendPages), referenced by name in settings.frontend_page. */
    public const TYPE_PLUGIN = 'plugin';

    protected $table = 'pages_menu_items';

    protected $fillable = [
        'menu_id', 'parent_id', 'position', 'label', 'type', 'page_id', 'url', 'target', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'settings' => 'array',
        ];
    }
}
