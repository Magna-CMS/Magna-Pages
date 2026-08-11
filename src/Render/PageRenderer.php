<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

use Magna\Blocks\BlockNode;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\PageTree;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Content\Entry;
use Magna\Frontend\FrontendPage;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\Render\Conditions\ConditionEvaluator;
use Magna\Pages\Templates\TemplatePartResolver;
use Magna\Pages\Themes\ThemeTokens;
use Magna\Pages\Themes\ThemeViewResolver;
use Magna\Settings\GeneralSettings;

/**
 * Renders a page entry's block document to public HTML.
 *
 * v1 of the render pipeline: parse the stored document through the tolerant
 * PageTree layer, resolve dynamic block data through the shared resolve seam
 * (BlockDataResolver — entries queries, sanitized richtext), and compose the
 * core block views inside the plugin's layout shell. Theme view resolution,
 * template documents, per-page asset aggregation, and the pages_cache
 * subsystem build on top of this class
 * (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §B1/§B3).
 *
 * Unknown block handles render nothing on the public site — a disabled
 * plugin degrades a block, never breaks the page.
 */
final class PageRenderer
{
    /**
     * The menu handle theme layouts render in their header until template
     * parts land — a site convention, resolved HERE so theme views stay
     * logic-free (views receive data; they never query).
     */
    private const HEADER_MENU_HANDLE = 'primary';

    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly BlockDataResolver $resolver,
        private readonly ThemeViewResolver $themeViews,
        private readonly ThemeTokens $tokens,
        private readonly MenuManager $menus,
        private readonly TemplatePartResolver $parts,
        private readonly ConditionEvaluator $conditions,
        private readonly BindingResolver $bindings,
    ) {}

    public function render(Entry $page, bool $builderMode = false, bool $withParts = true): string
    {
        $document = $page->getAttribute('blocks_data');
        $title = $page->getAttribute('title');

        return $this->renderDocument(
            is_array($document) ? $document : [],
            is_string($title) ? $title : '',
            $builderMode,
            $withParts,
            $page,
        );
    }

    /**
     * A plugin frontend page (ProvidesFrontendPages): its view rendered
     * into the layout's main slot, with the same shell — header/footer
     * parts, theme tokens, header menu — every real page gets.
     */
    public function renderFrontendPage(FrontendPage $page): string
    {
        $mode = $page->mode === FrontendPage::MODE_APP ? 'app' : 'content';
        $mainHtml = '<div class="magna-frontend-page magna-frontend-page--'.$mode.'">'
            .view($page->view)->render()
            .'</div>';

        return $this->renderDocument([], $page->title, mainHtml: $mainHtml);
    }

    /**
     * Render a raw block document through the full themed pipeline — used
     * for stored pages AND for unsaved editor state (live preview), so the
     * preview is byte-identical to what publishing would produce.
     *
     * When $mainHtml is given it replaces the section tree in the layout's
     * main slot (plugin frontend pages) — the shell still renders.
     *
     * @param  array<mixed, mixed>  $document
     */
    public function renderDocument(array $document, string $title, bool $builderMode = false, bool $withParts = true, ?Entry $context = null, ?string $mainHtml = null): string
    {
        // Ref sections splice their template part's sections in place
        // before parsing — parts compose pages, never the reverse.
        $tree = PageTree::fromArray($this->parts->expandRefs($document));
        $siteName = GeneralSettings::get()->site_name;

        return view($this->themeViews->layoutView(), [
            'title' => $title,
            // Pre-rendered main-slot HTML (plugin frontend pages). A theme
            // layout must print it instead of the section tree when set.
            'mainHtml' => $mainHtml,
            'siteName' => is_string($siteName) && $siteName !== '' ? $siteName : 'Magna',
            'headerMenu' => $this->menus->resolve(self::HEADER_MENU_HANDLE),
            'tree' => $tree,
            'registry' => $this->registry,
            'resolver' => $this->resolver,
            'blockViewFor' => fn (string $handle): ?string => $this->themeViews->blockView($handle),
            'tokensCss' => $this->tokens->rootCss(),
            // Site-designed header/footer parts (slugs "header"/"footer")
            // replace a theme layout's built-in chrome when published.
            // withParts false = a template document editing itself bare;
            // injecting the published header while EDITING the header would
            // show two of it, one stale.
            'headerPartHtml' => $withParts ? $this->renderPart('header', $context) : null,
            'footerPartHtml' => $withParts ? $this->renderPart('footer', $context) : null,
            // Inherited by the sections partial through @include, so a theme
            // layout needs no builder awareness of its own.
            'builderMode' => $builderMode,
            // Display conditions, evaluated per node at render. The BUILDER
            // shows everything — you cannot edit what you cannot see — and
            // the canvas marks conditioned nodes instead (the partial adds
            // a data attribute the overlay can badge).
            'conditionsPass' => $builderMode
                ? fn (array $settings): bool => true
                : fn (array $settings): bool => $this->conditions
                    ->evaluate($settings, auth()->user(), now())->visible,
            // Bindings resolve against the PAGE being rendered — a part's
            // {"$bind": "entry.title"} means the page it appears on. The
            // stored document keeps its bindings; only the view payload
            // carries the values.
            'resolveBindings' => fn (BlockNode $block): BlockNode => $block->withData(
                $this->bindings->resolve($block->data, $context),
            ),
        ])->render();
    }

    /**
     * A template part rendered through the same section pipeline, or null
     * when the part does not exist or is not published.
     */
    private function renderPart(string $handle, ?Entry $context = null): ?string
    {
        $tree = $this->parts->partTree($handle);
        if ($tree === null || $tree->sections === []) {
            return null;
        }

        return view('magna-pages::partials.sections', [
            'tree' => $tree,
            'registry' => $this->registry,
            'resolver' => $this->resolver,
            'blockViewFor' => fn (string $handle): ?string => $this->themeViews->blockView($handle),
            // Parts obey conditions on the public site like any section.
            'conditionsPass' => fn (array $settings): bool => $this->conditions
                ->evaluate($settings, auth()->user(), now())->visible,
            // A part's bindings mean the page it appears on.
            'resolveBindings' => fn (BlockNode $block): BlockNode => $block->withData(
                $this->bindings->resolve($block->data, $context),
            ),
        ])->render();
    }
}
