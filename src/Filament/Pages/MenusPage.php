<?php

declare(strict_types=1);

namespace Magna\Pages\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;
use Magna\Frontend\FrontendPageRegistry;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuItem;
use Magna\Pages\Menus\MenuManager;

/**
 * Menu builder: create menus, edit their item tree (two levels — matching
 * what the nav block renders), reorder with up/down controls, save through
 * MenuManager so every change lands a history snapshot.
 *
 * State lives on the page (Filament pages are Livewire components) in the
 * same editable-array style as the core BlockEditor; drag-and-drop ordering
 * is a later enhancement on the same state shape.
 */
class MenusPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bars-3';

    protected static string|\UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Menus';

    protected static ?string $title = 'Menus';

    protected static ?string $slug = 'pages-menus';

    protected string $view = 'magna-pages::filament.menus';

    public ?string $selectedMenuId = null;

    public string $newMenuName = '';

    /**
     * Editable tree of the selected menu. Item shape:
     * {label, type, page_id, url, target, children: [same, no deeper]}
     *
     * @var list<array<string, mixed>>
     */
    public array $items = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('pages.settings') ?? false;
    }

    public function mount(): void
    {
        $first = Menu::query()->orderBy('name')->first();
        if ($first !== null) {
            $this->selectMenu($first->id);
        }
    }

    // ── Menu selection & creation ─────────────────────────────────────────────

    public function selectMenu(string $menuId): void
    {
        $menu = Menu::query()->find($menuId);
        if ($menu === null) {
            return;
        }

        $this->selectedMenuId = $menu->id;
        $this->items = $this->treeFor($menu);
    }

    public function createMenu(): void
    {
        $name = trim($this->newMenuName);
        if ($name === '') {
            return;
        }

        $handle = Str::slug($name, '_');
        if ($handle === '' || Menu::query()->where('handle', $handle)->exists()) {
            Notification::make()->title('A menu with that name already exists.')->danger()->send();

            return;
        }

        $menu = app(MenuManager::class)->create($handle, $name);
        $this->newMenuName = '';
        $this->selectMenu($menu->id);
    }

    // ── Tree editing (two levels) ─────────────────────────────────────────────

    public function addItem(?int $parentIndex = null): void
    {
        $item = ['label' => '', 'type' => 'url', 'page_id' => null, 'url' => '', 'target' => null, 'plugin_page' => null, 'children' => []];

        if ($parentIndex === null) {
            $this->items[] = $item;

            return;
        }

        if (isset($this->items[$parentIndex])) {
            unset($item['children']); // one nesting level only
            $this->items[$parentIndex]['children'][] = $item;
        }
    }

    public function removeItem(int $index, ?int $childIndex = null): void
    {
        if ($childIndex === null) {
            array_splice($this->items, $index, 1);

            return;
        }

        if (isset($this->items[$index]['children'][$childIndex])) {
            array_splice($this->items[$index]['children'], $childIndex, 1);
        }
    }

    public function moveItem(int $index, int $direction, ?int $childIndex = null): void
    {
        if ($childIndex === null) {
            $this->swap($this->items, $index, $index + $direction);

            return;
        }

        if (isset($this->items[$index]['children'])) {
            $this->swap($this->items[$index]['children'], $childIndex, $childIndex + $direction);
        }
    }

    public function save(): void
    {
        if ($this->selectedMenuId === null) {
            return;
        }

        $menu = Menu::query()->find($this->selectedMenuId);
        if ($menu === null) {
            return;
        }

        app(MenuManager::class)->syncItems($menu, $this->items, auth()->id());

        Notification::make()->title('Menu saved')->success()->send();
    }

    // ── View data ─────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    public function menuOptions(): array
    {
        /** @var array<string, string> */
        return Menu::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    public function pageOptions(): array
    {
        if (! app(SchemaRegistry::class)->has('page')) {
            return [];
        }

        $options = [];
        foreach (Entry::type('page')
            ->where('status', EntryStatus::Published->value)
            ->orderBy('title')
            ->limit(200)
            ->get() as $entry) {
            $id = $entry->getKey();
            $title = $entry->getAttribute('title');
            if (is_string($id) && is_string($title)) {
                $options[$id] = $title;
            }
        }

        return $options;
    }

    /**
     * Plugin frontend pages offered in the picker (menu-visible only).
     *
     * @return array<string, string>
     */
    public function pluginPageOptions(): array
    {
        $options = [];
        foreach (app(FrontendPageRegistry::class)->all() as $name => $page) {
            if ($page->menuVisible) {
                $options[$name] = $page->title;
            }
        }
        ksort($options);

        return $options;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function treeFor(Menu $menu): array
    {
        $items = $menu->items()->get();

        $tree = [];
        foreach ($items as $item) {
            if ($item->parent_id !== null) {
                continue;
            }

            $children = [];
            foreach ($items as $child) {
                if ($child->parent_id === $item->id) {
                    $children[] = $this->editorItem($child);
                }
            }

            $node = $this->editorItem($item);
            $node['children'] = $children;
            $tree[] = $node;
        }

        return $tree;
    }

    /** @return array<string, mixed> */
    private function editorItem(MenuItem $item): array
    {
        return [
            'label' => $item->label,
            'type' => $item->type,
            'page_id' => $item->page_id,
            'url' => $item->url,
            'target' => $item->target,
            'plugin_page' => is_string($item->settings['frontend_page'] ?? null)
                ? $item->settings['frontend_page']
                : null,
        ];
    }

    /**
     * @param  array<int, mixed>  $list
     */
    private function swap(array &$list, int $a, int $b): void
    {
        if ($a < 0 || $b < 0 || ! isset($list[$a]) || ! isset($list[$b])) {
            return;
        }

        [$list[$a], $list[$b]] = [$list[$b], $list[$a]];
        $list = array_values($list);
    }
}
