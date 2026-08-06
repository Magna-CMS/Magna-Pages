<?php

declare(strict_types=1);

namespace Magna\Pages\Themes;

use Illuminate\Support\Facades\View;
use Magna\Themes\ThemeManager;

/**
 * Registers the active theme's views under the `theme::` namespace and
 * resolves block views through the fallback chain
 * (docs/magna-pages/04-THEMES-V2.md §5 — addon layer arrives later):
 *
 *   theme::blocks.{handle} → magna::blocks.{handle} → null
 *
 * A theme never breaks a page: a block it does not style falls back to the
 * core default view, and a missing view renders nothing on the public site.
 */
class ThemeViewResolver
{
    private bool $registered = false;

    public function __construct(private readonly ThemeManager $themes) {}

    /**
     * Register the active theme's views directory once per request.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

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
     * The view name to render for a block handle, or null when neither the
     * theme nor core ships one.
     */
    public function blockView(string $handle): ?string
    {
        $this->register();

        foreach (['theme::blocks.'.$handle, 'magna::blocks.'.$handle] as $view) {
            if (View::exists($view)) {
                return $view;
            }
        }

        return null;
    }

    /**
     * The layout shell view: the theme's system layout when it ships one,
     * the plugin's built-in shell otherwise.
     */
    public function layoutView(): string
    {
        $this->register();

        return View::exists('theme::system.layout')
            ? 'theme::system.layout'
            : 'magna-pages::page';
    }
}
