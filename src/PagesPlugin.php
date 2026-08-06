<?php

declare(strict_types=1);

namespace Magna\Pages;

use Illuminate\Support\Facades\Event;
use Magna\Admin\Nav\NavGroup;
use Magna\Admin\Nav\NavItem;
use Magna\Blocks\BlockDefinition;
use Magna\Blocks\Contracts\ProvidesDocumentPreview;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Content\Entry;
use Magna\Content\Events\EntryDeleted;
use Magna\Content\Events\EntryPublished;
use Magna\Content\Events\EntryUnpublished;
use Magna\Content\Events\EntryUpdated;
use Magna\Contracts\RegistersAdminNavigation;
use Magna\Contracts\RegistersBlocks;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Pages\Cache\PurgePageCache;
use Magna\Pages\Filament\Pages\MenusPage;
use Magna\Pages\Filament\Pages\PagesSettingsPage;
use Magna\Pages\Listeners\RecordSlugRenameRedirect;
use Magna\Pages\Menus\MenuOptions;
use Magna\Pages\Menus\NavBlockResolver;
use Magna\Plugins\Plugin;

/**
 * Entry point for the magna/pages plugin — the rendered-frontend layer
 * (docs/magna-pages/ in the core repo is the full plan).
 *
 * Skeleton scope (Phase A item 5):
 *   - the `page` content type (schemas/page.json): title, slug, template,
 *     block document — drafts, revisions, locales inherited from core
 *   - site settings (PagesSettings + admin settings page): home page,
 *     maintenance mode. Host/URL facts stay in core UrlSettings —
 *     deliberately not duplicated here (10-REVIEW-RESOLUTIONS §A8).
 *
 * Routing, rendering, menus, and the builder land in the next Phase A items
 * on top of this skeleton.
 */
class PagesPlugin extends Plugin implements RegistersAdminNavigation, RegistersBlocks, RegistersSettingsPages
{
    public function boot(): void
    {
        $this->loadViewsFrom('resources/views', 'magna-pages');

        // Also expose this plugin's block views on the shared magna::
        // namespace so the standard block-view resolution chain
        // (theme::blocks.X → magna::blocks.X) finds them.
        $this->loadViewsFrom('resources/views', 'magna');

        // Auto-301 on page slug renames. The `updating` model event is the
        // one point where old AND new slug are both visible.
        Entry::updating(function (Entry $entry): void {
            app(RecordSlugRenameRedirect::class)->handle($entry);
        });

        // Dynamic data for the nav block flows through the shared resolve
        // seam, same as the core entries/text resolvers.
        app(BlockDataResolver::class)->register(app(NavBlockResolver::class));

        // Light up the core block editor's live-preview pane (§E1 contract
        // seam — core shows the pane only when this binding exists).
        app()->singleton(ProvidesDocumentPreview::class, ThemedDocumentPreview::class);

        // Page-cache invalidation: content edits purge exactly that page.
        foreach ([
            EntryUpdated::class,
            EntryPublished::class,
            EntryUnpublished::class,
            EntryDeleted::class,
        ] as $event) {
            Event::listen($event, function (object $e): void {
                app(PurgePageCache::class)->handleEntryEvent($e);
            });
        }
    }

    /** @return list<BlockDefinition> */
    public function blocks(): array
    {
        return [
            BlockDefinition::fromArray([
                'handle' => 'nav',
                'label' => 'Navigation',
                'icon' => 'heroicon-o-bars-3',
                'category' => 'site',
                'fields' => [
                    [
                        'handle' => 'menu',
                        'type' => 'select',
                        'label' => 'Menu',
                        'required' => true,
                        'optionsFrom' => MenuOptions::class,
                    ],
                ],
            ]),
        ];
    }

    public function adminNavigation(): NavGroup
    {
        return NavGroup::make('Site', icon: 'heroicon-o-globe-alt')->items([
            NavItem::page('Menus', route: 'filament.admin.pages.pages-menus')
                ->can('pages.settings'),
            NavItem::page('Site settings', route: 'filament.admin.pages.pages-settings')
                ->can('pages.settings'),
        ]);
    }

    /** @return list<class-string> */
    public function settingsPages(): array
    {
        // First entry is what the Installed Plugins screen's "Settings"
        // button links to — keep the settings page first.
        return [PagesSettingsPage::class, MenusPage::class];
    }
}
