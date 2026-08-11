<?php

declare(strict_types=1);

namespace Magna\Pages;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Magna\Admin\Nav\NavGroup;
use Magna\Admin\Nav\NavItem;
use Magna\Blocks\BlockDefinition;
use Magna\Blocks\Contracts\GuardsDocumentEdits;
use Magna\Blocks\Contracts\ProvidesDocumentPreview;
use Magna\Blocks\DataSources\DataSource;
use Magna\Blocks\DynamicTags\DynamicTag;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Content\Entry;
use Magna\Content\Events\EntryDeleted;
use Magna\Content\Events\EntryPublished;
use Magna\Content\Events\EntryUnpublished;
use Magna\Content\Events\EntryUpdated;
use Magna\Contracts\RegistersAdminNavigation;
use Magna\Contracts\RegistersBlocks;
use Magna\Contracts\RegistersCommands;
use Magna\Contracts\RegistersDataSources;
use Magna\Contracts\RegistersDynamicTags;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Pages\Blocks\CurrentYearTag;
use Magna\Pages\Blocks\DataSourceOptions;
use Magna\Pages\Blocks\EmbedBlockResolver;
use Magna\Pages\Blocks\LatestPagesSource;
use Magna\Pages\Blocks\LoopBlockResolver;
use Magna\Pages\Builder\LivewireEditGuard;
use Magna\Pages\Cache\PurgePageCache;
use Magna\Pages\Console\E2eUserCommand;
use Magna\Pages\Console\InstallDemoCommand;
use Magna\Pages\Console\PruneCacheCommand;
use Magna\Pages\Console\SiteDiffCommand;
use Magna\Pages\Console\SiteExportCommand;
use Magna\Pages\Console\SiteSyncCommand;
use Magna\Pages\Filament\Pages\MenusPage;
use Magna\Pages\Filament\Pages\PagesIndexPage;
use Magna\Pages\Filament\Pages\PagesSettingsPage;
use Magna\Pages\Filament\Pages\ReviewQueuePage;
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
class PagesPlugin extends Plugin implements RegistersAdminNavigation, RegistersBlocks, RegistersCommands, RegistersDataSources, RegistersDynamicTags, RegistersSettingsPages
{
    /** @return list<DataSource> */
    public function dataSources(): array
    {
        // The plugin registers its own built-in source through the same
        // contract third-party plugins use — one wiring path, exercised
        // on every boot.
        return [app(LatestPagesSource::class)];
    }

    /** @return list<DynamicTag> */
    public function dynamicTags(): array
    {
        // Same one-wiring-path rule as dataSources().
        return [new CurrentYearTag];
    }

    /** @return list<class-string> */
    public function commands(): array
    {
        return [
            InstallDemoCommand::class,
            PruneCacheCommand::class,
            E2eUserCommand::class,
            SiteExportCommand::class,
            SiteDiffCommand::class,
            SiteSyncCommand::class,
        ];
    }

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

        // The Loop block: plugin data sources onto pages.
        app(BlockDataResolver::class)->register(app(LoopBlockResolver::class));

        // The curated embed block: allowlisted providers only.
        app(BlockDataResolver::class)->register(app(EmbedBlockResolver::class));

        // Light up the core block editor's live-preview pane (§E1 contract
        // seam — core shows the pane only when this binding exists).
        app()->singleton(ProvidesDocumentPreview::class, ThemedDocumentPreview::class);

        // The structured editor honors the builder's document lock (§E2) —
        // two editors, one lock, neither can overwrite the other unseen.
        app()->singleton(
            GuardsDocumentEdits::class,
            LivewireEditGuard::class,
        );

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

        // Expired pages_cache rows are dead weight after their TTL — sweep
        // hourly. Same deferred-Schedule pattern as core's prune commands
        // (a plugin is not a ServiceProvider, so the callAfterResolving
        // helper is spelled out here): no-op unless the scheduler runs.
        $wire = function (Schedule $schedule): void {
            $schedule->command('magna:pages:cache-prune')->hourly();
        };
        if (app()->resolved(Schedule::class)) {
            $wire(app(Schedule::class));
        } else {
            app()->afterResolving(Schedule::class, $wire);
        }
    }

    /** @return list<BlockDefinition> */
    public function blocks(): array
    {
        return [
            BlockDefinition::fromArray([
                'handle' => 'loop',
                'label' => 'Loop',
                'icon' => 'heroicon-o-arrow-path-rounded-square',
                'category' => 'dynamic',
                'fields' => [
                    ['handle' => 'heading', 'type' => 'text', 'label' => 'Heading', 'required' => false],
                    [
                        'handle' => 'source',
                        'type' => 'select',
                        'label' => 'Data source',
                        'required' => true,
                        'optionsFrom' => DataSourceOptions::class,
                    ],
                    ['handle' => 'limit', 'type' => 'number', 'label' => 'Items', 'required' => false, 'default' => 6],
                ],
            ]),
            BlockDefinition::fromArray([
                'handle' => 'embed',
                'label' => 'Embed',
                'icon' => 'heroicon-o-play-circle',
                'category' => 'media',
                'fields' => [
                    ['handle' => 'url', 'type' => 'link', 'label' => 'Video URL', 'required' => true],
                    ['handle' => 'caption', 'type' => 'text', 'label' => 'Caption', 'required' => false],
                ],
            ]),
            BlockDefinition::fromArray([
                'handle' => 'locale-switcher',
                'label' => 'Language switcher',
                'icon' => 'heroicon-o-language',
                'category' => 'site',
                'fields' => [],
            ]),
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
            NavItem::page('Pages', route: 'filament.admin.pages.pages-index')
                ->can('pages.content'),
            NavItem::page('Review queue', route: 'filament.admin.pages.pages-review-queue')
                ->can('pages.publish'),
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
        return [PagesSettingsPage::class, MenusPage::class, PagesIndexPage::class, ReviewQueuePage::class];
    }
}
