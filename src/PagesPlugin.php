<?php

declare(strict_types=1);

namespace Magna\Pages;

use Magna\Admin\Nav\NavGroup;
use Magna\Admin\Nav\NavItem;
use Magna\Contracts\RegistersAdminNavigation;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Pages\Filament\Pages\PagesSettingsPage;
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
class PagesPlugin extends Plugin implements RegistersAdminNavigation, RegistersSettingsPages
{
    public function boot(): void
    {
        $this->loadViewsFrom('resources/views', 'magna-pages');
    }

    public function adminNavigation(): NavGroup
    {
        return NavGroup::make('Site', icon: 'heroicon-o-globe-alt')->items([
            NavItem::page('Site settings', route: 'filament.admin.pages.pages-settings')
                ->can('pages.settings'),
        ]);
    }

    /** @return list<class-string> */
    public function settingsPages(): array
    {
        return [PagesSettingsPage::class];
    }
}
