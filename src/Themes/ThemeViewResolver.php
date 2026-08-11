<?php

declare(strict_types=1);

namespace Magna\Pages\Themes;

use Illuminate\Support\Facades\View;
use Magna\Blocks\BlockRegistry;
use Magna\Themes\ThemeManager;
use Magna\Themes\ThemeManifest;

/**
 * Registers the active theme's and active addons' views and resolves block
 * views through the fallback chain (docs/magna-pages/04-THEMES-V2.md §5):
 *
 *   addon → theme::blocks.{handle} → magna::blocks.{handle} → null
 *
 * (`magna::` covers both the plugin's default view and the core default —
 * plugin block views mount on the shared namespace behind core's.)
 *
 * §C8 constraint, enforced HERE at resolution time: an addon's view is
 * consulted only for blocks whose sourcePlugin appears in its pairsWith.
 * Core blocks (sourcePlugin null) can never be overridden by an addon —
 * an `extends: "*"` addon replacing core markup site-wide is exactly the
 * attack the review flagged. Addon precedence comes ordered from
 * ThemeManager::activeAddons() (specific extends, then priority, then name).
 *
 * A theme or addon never breaks a page: anything unstyled falls through
 * the chain, and a missing view renders nothing on the public site.
 */
class ThemeViewResolver
{
    private bool $registered = false;

    /** @var list<array{namespace: string, manifest: ThemeManifest}> */
    private array $addonNamespaces = [];

    public function __construct(
        private readonly ThemeManager $themes,
        private readonly BlockRegistry $blocks,
    ) {}

    /**
     * Register the active theme's and addons' view directories once per request.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        foreach ($this->themes->activeAddons() as $i => $addon) {
            $viewsPath = $this->themes->pathFor($addon->name).'/views';
            if (is_dir($viewsPath)) {
                $namespace = 'theme-addon-'.$i;
                View::addNamespace($namespace, $viewsPath);
                $this->addonNamespaces[] = ['namespace' => $namespace, 'manifest' => $addon];
            }
        }

        $active = $this->themes->active();
        if ($active === null) {
            return;
        }

        $viewsPath = $this->themes->pathFor($active->name).'/views';
        if (is_dir($viewsPath)) {
            View::addNamespace('theme', $viewsPath);
        }
    }

    /**
     * The view name to render for a block handle, or null when nothing in
     * the chain ships one.
     */
    public function blockView(string $handle): ?string
    {
        $this->register();

        $sourcePlugin = $this->blocks->get($handle)?->sourcePlugin;

        if ($sourcePlugin !== null) {
            foreach ($this->addonNamespaces as $addon) {
                if (! in_array($sourcePlugin, $addon['manifest']->pairsWith, true)) {
                    continue;
                }
                $view = $addon['namespace'].'::blocks.'.$handle;
                if (View::exists($view)) {
                    return $view;
                }
            }
        }

        foreach (['theme::blocks.'.$handle, 'magna::blocks.'.$handle] as $view) {
            if (View::exists($view)) {
                return $view;
            }
        }

        return null;
    }

    /**
     * The layout shell view: the theme's system layout when it ships one,
     * the plugin's built-in shell otherwise. Addons never provide layouts —
     * they extend a theme, they do not replace its shell.
     */
    public function layoutView(): string
    {
        $this->register();

        return View::exists('theme::system.layout')
            ? 'theme::system.layout'
            : 'magna-pages::page';
    }
}
