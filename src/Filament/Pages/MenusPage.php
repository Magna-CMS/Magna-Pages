<?php

declare(strict_types=1);

namespace Magna\Pages\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuItem;
use Magna\Pages\Menus\MenuItemSources;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\Menus\MenuTree;

/**
 * The menu builder: pick a menu, tick items on the left, arrange them on
 * the right, save.
 *
 * Two things shape this screen. The item list is EDITED FLAT with a depth
 * per row (see MenuTree) so that reordering and nesting are the same two
 * gestures at every level, and items are ADDED IN BULK, because a menu is
 * built by choosing eight pages at once rather than by filling in eight
 * forms.
 *
 * Every structural rule lives in MenuTree and every query in
 * MenuItemSources; what is left here is Livewire state and a list of
 * delegations, which is all a page in this codebase is allowed to be.
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

    /** The selected menu's name, editable in place. */
    public string $menuName = '';

    /**
     * The item list as flat rows: {key, label, type, page_id, url, target,
     * plugin_page, settings, depth}.
     *
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    /** Keys of the rows whose detail panel is open. */
    public array $openRows = [];

    /**
     * Which advanced fields the item panels show — WordPress's Screen
     * Options, and for its reason: most menus never need a link relationship
     * or a CSS class, and a form that shows every field to everyone is a
     * form nobody reads.
     *
     * @var array<string, bool>
     */
    public array $screen = [
        'title_attr' => false,
        'css_class' => false,
        'rel' => false,
        'description' => false,
    ];

    /** Which source panel is open, and what has been ticked in it. */
    public string $openPanel = 'pages';

    public string $sourceSearch = '';

    /** @var list<string> */
    public array $checkedPages = [];

    /** @var list<string> */
    public array $checkedPluginPages = [];

    public string $customLinkUrl = 'https://';

    public string $customLinkLabel = '';

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

    // ── Menus ─────────────────────────────────────────────────────────────────

    public function selectMenu(string $menuId): void
    {
        $menu = Menu::query()->find($menuId);
        if ($menu === null) {
            return;
        }

        $this->selectedMenuId = $menu->id;
        $this->menuName = $menu->name;
        $this->rows = MenuTree::flatten($this->treeFor($menu));
        $this->openRows = [];
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

    public function renameMenu(): void
    {
        $menu = $this->selectedMenu();
        $name = trim($this->menuName);
        if ($menu === null || $name === '') {
            return;
        }

        // The HANDLE is what a nav block and a theme layout point at, so it
        // stays put: renaming a menu must not silently empty every header
        // that was rendering it.
        $menu->update(['name' => $name]);

        Notification::make()->title('Menu renamed')->success()->send();
    }

    public function deleteMenu(): void
    {
        $menu = $this->selectedMenu();
        if ($menu === null) {
            return;
        }

        $menu->delete();
        $this->selectedMenuId = null;
        $this->rows = [];
        $this->menuName = '';

        $first = Menu::query()->orderBy('name')->first();
        if ($first !== null) {
            $this->selectMenu($first->id);
        }

        Notification::make()->title('Menu deleted')->success()->send();
    }

    // ── Adding items ──────────────────────────────────────────────────────────

    /** Open a source panel, or close it if it is the open one. */
    public function togglePanel(string $panel): void
    {
        $this->openPanel = $this->openPanel === $panel ? '' : $panel;
        // Each panel searches its own list; carrying the last panel's term
        // over means opening one and finding it empty for no visible reason.
        $this->sourceSearch = '';
    }

    public function addCheckedPages(): void
    {
        $labels = collect($this->pageRows())->keyBy('id');

        foreach ($this->checkedPages as $id) {
            $row = $labels->get($id);
            if ($row === null) {
                continue;
            }

            $this->rows[] = $this->newRow([
                'label' => $row['label'],
                'type' => MenuItem::TYPE_PAGE,
                'page_id' => $id,
            ]);
        }

        $this->checkedPages = [];
    }

    public function addCheckedPluginPages(): void
    {
        $labels = collect($this->pluginPageRows())->keyBy('id');

        foreach ($this->checkedPluginPages as $name) {
            $row = $labels->get($name);
            if ($row === null) {
                continue;
            }

            $this->rows[] = $this->newRow([
                'label' => $row['label'],
                'type' => MenuItem::TYPE_PLUGIN,
                'plugin_page' => $name,
            ]);
        }

        $this->checkedPluginPages = [];
    }

    public function addCustomLink(): void
    {
        $url = trim($this->customLinkUrl);
        $label = trim($this->customLinkLabel);

        if ($url === '' || $url === 'https://' || $label === '') {
            return;
        }

        $this->rows[] = $this->newRow(['label' => $label, 'type' => MenuItem::TYPE_URL, 'url' => $url]);

        $this->customLinkUrl = 'https://';
        $this->customLinkLabel = '';
    }

    // ── Arranging ─────────────────────────────────────────────────────────────

    public function moveUp(int $index): void
    {
        $this->rows = MenuTree::moveUp($this->rows, $index);
    }

    public function moveDown(int $index): void
    {
        $this->rows = MenuTree::moveDown($this->rows, $index);
    }

    public function indent(int $index): void
    {
        $this->rows = MenuTree::indent($this->rows, $index);
    }

    public function outdent(int $index): void
    {
        $this->rows = MenuTree::outdent($this->rows, $index);
    }

    public function moveToTop(int $index): void
    {
        $this->rows = MenuTree::toTop($this->rows, $index);
    }

    /** A completed drag: one row (and its subtree) to a position and depth. */
    public function dropRow(int $from, int $to, int $depth): void
    {
        $this->rows = MenuTree::move($this->rows, $from, $to, $depth);
    }

    public function removeRow(int $index): void
    {
        $this->rows = MenuTree::remove($this->rows, $index);
    }

    public function toggleRow(string $key): void
    {
        $this->openRows = in_array($key, $this->openRows, true)
            ? array_values(array_diff($this->openRows, [$key]))
            : [...$this->openRows, $key];
    }

    public function save(): void
    {
        $menu = $this->selectedMenu();
        if ($menu === null) {
            return;
        }

        app(MenuManager::class)->syncItems($menu, MenuTree::nest($this->forStorage()), auth()->id());

        // Read back, so what is on screen is what was stored rather than
        // what was sent.
        $this->rows = MenuTree::flatten($this->treeFor($menu));

        Notification::make()->title('Menu saved')->success()->send();
    }

    // ── View data ─────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    public function menuOptions(): array
    {
        /** @var array<string, string> */
        return Menu::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return list<array{id: string, label: string, meta: string}> */
    public function pageRows(): array
    {
        return app(MenuItemSources::class)->pages($this->sourceSearch);
    }

    /** @return list<array{id: string, label: string, meta: string}> */
    public function pluginPageRows(): array
    {
        return app(MenuItemSources::class)->pluginPages($this->sourceSearch);
    }

    /** The handle a nav block or theme layout points at. */
    public function selectedHandle(): string
    {
        return $this->selectedMenu()?->handle ?? '';
    }

    /** What a row links to, for the collapsed summary line. */
    public function describeRow(array $row): string
    {
        return match ($row['type'] ?? MenuItem::TYPE_URL) {
            MenuItem::TYPE_PAGE => 'Page',
            MenuItem::TYPE_PLUGIN => 'Plugin page',
            default => 'Custom link',
        };
    }

    /** Whether this row could still be nested one level further in. */
    public function canIndent(int $index): bool
    {
        return MenuTree::indent($this->rows, $index) !== $this->rows;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * The rows as the store wants them: the editor's checkbox becomes the
     * `target` string, and nothing editor-only travels further.
     *
     * @return list<array<string, mixed>>
     */
    private function forStorage(): array
    {
        return array_map(static function (array $row): array {
            $row['target'] = ($row['new_tab'] ?? false) ? '_blank' : null;
            unset($row['new_tab']);

            return $row;
        }, $this->rows);
    }

    private function selectedMenu(): ?Menu
    {
        return $this->selectedMenuId === null
            ? null
            : Menu::query()->find($this->selectedMenuId);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newRow(array $overrides = []): array
    {
        return [
            'key' => (string) Str::ulid(),
            'label' => '',
            'type' => MenuItem::TYPE_URL,
            'page_id' => null,
            'url' => null,
            // A checkbox binds to a boolean; `target` is a string the store
            // and the renderer both understand. Keeping both and mapping at
            // the boundary is what stops the checkbox writing `true` into a
            // column that means "_blank".
            'new_tab' => false,
            'plugin_page' => null,
            'settings' => ['title_attr' => '', 'css_class' => '', 'rel' => '', 'description' => ''],
            'depth' => 0,
            ...$overrides,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function treeFor(Menu $menu): array
    {
        $items = $menu->items()->get()->all();

        $build = function (?string $parentId) use (&$build, $items): array {
            $level = [];
            foreach ($items as $item) {
                if ($item->parent_id !== $parentId) {
                    continue;
                }

                $node = $this->editorItem($item);
                $children = $build($item->id);
                if ($children !== []) {
                    $node['children'] = $children;
                }
                $level[] = $node;
            }

            return $level;
        };

        return $build(null);
    }

    /** @return array<string, mixed> */
    private function editorItem(MenuItem $item): array
    {
        $settings = $item->settings ?? [];

        return [
            'key' => $item->id,
            'label' => $item->label,
            'type' => $item->type,
            'page_id' => $item->page_id,
            'url' => $item->url,
            'new_tab' => $item->target === '_blank',
            'plugin_page' => is_string($settings['frontend_page'] ?? null)
                ? $settings['frontend_page']
                : null,
            'settings' => [
                'title_attr' => is_string($settings['title_attr'] ?? null) ? $settings['title_attr'] : '',
                'css_class' => is_string($settings['css_class'] ?? null) ? $settings['css_class'] : '',
                'rel' => is_string($settings['rel'] ?? null) ? $settings['rel'] : '',
                'description' => is_string($settings['description'] ?? null) ? $settings['description'] : '',
            ],
        ];
    }
}
