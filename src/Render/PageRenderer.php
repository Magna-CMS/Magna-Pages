<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

use Illuminate\Support\Facades\Route;
use Magna\Blocks\BlockNode;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\PageTree;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;
use Magna\Frontend\FrontendPage;
use Magna\Pages\Builder\PageSettings;
use Magna\Pages\Consent\ConsentScripts;
use Magna\Pages\Experiments\ExperimentTracker;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\PagesSettings;
use Magna\Pages\Render\Conditions\ConditionEvaluator;
use Magna\Pages\Routing\LocalePrefix;
use Magna\Pages\Templates\PopupTargeting;
use Magna\Pages\Templates\TemplatePartResolver;
use Magna\Pages\Themes\ThemeTokens;
use Magna\Pages\Themes\ThemeViewResolver;
use Magna\Settings\GeneralSettings;
use Magna\Settings\LocalizationSettings;

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

    /** Whether a route other than the page fallback answers GET / (memoised). */
    private ?bool $rootClaimed = null;

    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly BlockDataResolver $resolver,
        private readonly ThemeViewResolver $themeViews,
        private readonly ThemeTokens $tokens,
        private readonly MenuManager $menus,
        private readonly TemplatePartResolver $parts,
        private readonly ConditionEvaluator $conditions,
        private readonly BindingResolver $bindings,
        private readonly LocalePrefix $localePrefix,
        private readonly ConsentScripts $consent,
        private readonly ExperimentTracker $experiments,
        private readonly SchemaRegistry $schemaRegistry,
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
     * A header or footer being EDITED, drawn in the slot it occupies.
     *
     * Editing chrome used to render it bare, with `withParts: false`, on
     * the reasoning that injecting the published header while editing the
     * header would show two of it. True, but the cure was worse: with no
     * part html the theme falls back to drawing its OWN header, and the
     * part's sections land in the main slot — so the editor saw two
     * headers anyway, neither of them the one they were editing, and
     * nothing they typed appeared in a header at all.
     *
     * Drawn in its slot instead. A non-empty slot suppresses the theme's
     * fallback, the editor sees the header AS a header, and what they type
     * changes the thing they are looking at.
     */
    public function renderChrome(Entry $part, string $role, bool $builderMode = false): string
    {
        $document = $part->getAttribute('blocks_data');
        $title = $part->getAttribute('title');

        return $this->renderDocument(
            is_array($document) ? $document : [],
            is_string($title) ? $title : '',
            $builderMode,
            withParts: true,
            context: $part,
            chromeSlot: $role,
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
    public function renderDocument(array $document, string $title, bool $builderMode = false, bool $withParts = true, ?Entry $context = null, ?string $mainHtml = null, ?string $chromeSlot = null): string
    {
        /*
         * The document IS this slot's chrome: render it there rather than
         * as page content, and leave the main slot to say so. Its nodes
         * carry no document marker, because it is the document this session
         * opened — the marker means "somewhere else".
         */
        $chromeHtml = null;
        if ($chromeSlot !== null) {
            $chromeHtml = view('magna-pages::partials.sections', [
                'tree' => PageTree::fromArray($document),
                'registry' => $this->registry,
                'resolver' => $this->resolver,
                'blockViewFor' => fn (string $handle): ?string => $this->themeViews->blockView($handle),
                'builderMode' => $builderMode,
                'conditionsPass' => fn (array $settings): bool => true,
                'resolveBindings' => fn (BlockNode $block): BlockNode => $block,
            ])->render();

            $mainHtml = $builderMode
                ? '<div class="magna-chrome-editing" style="padding:3rem 1rem;text-align:center;opacity:0.55">'
                    .'Page content appears here.</div>'
                : '';

            // The sections are in the slot now; printing them again as page
            // content is the second header this used to show.
            $document = [];
        }

        // Ref sections splice their template part's sections in place
        // before parsing — parts compose pages, never the reverse.
        $tree = PageTree::fromArray($this->parts->expandRefs($document));
        $siteName = GeneralSettings::get()->site_name;

        // A/B: register every variant this document declares, so an
        // experiment appears in the results screen the moment it goes
        // live. Idempotent, and skipped in the builder.
        if (! $builderMode) {
            $variants = [];
            foreach ($tree->sections as $section) {
                $experiment = $section->settings['experiment'] ?? null;
                $variant = $section->settings['variant'] ?? null;
                if (is_string($experiment) && is_string($variant) && $experiment !== '' && $variant !== '') {
                    $variants[$experiment][] = $variant;
                }
            }
            if ($variants !== []) {
                $this->experiments->ensure($variants);
            }
        }

        return view($this->themeViews->layoutView(), [
            'title' => $title,
            // Pre-rendered main-slot HTML (plugin frontend pages). A theme
            // layout must print it instead of the section tree when set.
            'mainHtml' => $mainHtml,
            'siteName' => is_string($siteName) && $siteName !== '' ? $siteName : 'Magna',
            'headerMenu' => $this->menus->resolve(self::HEADER_MENU_HANDLE),
            // Where a theme's wordmark should point. NOT always "/": the
            // page router only sees a path nothing else has claimed, and on
            // an install whose admin panel is mounted at the root the home
            // page lives at its own address instead. Resolved here for the
            // same reason the header menu is — views receive data.
            'homeUrl' => $this->homeUrl(),
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
            'headerPartHtml' => $chromeSlot === 'header'
                ? $chromeHtml
                : ($withParts ? $this->renderPart('header', $context, $builderMode) : null),
            'footerPartHtml' => $chromeSlot === 'footer'
                ? $chromeHtml
                : ($withParts ? $this->renderPart('footer', $context, $builderMode) : null),
            // Published popup documents as dismissible overlays, plus the
            // consent registry's scripts + banner — everything the layout
            // prints before </body>. Never in the builder canvas and never
            // when editing a popup document itself (withParts false).
            'popupsHtml' => $withParts && ! $builderMode
                ? ($this->renderPopups($context) ?? '').($this->consent->render() ?? '') ?: null
                : null,
            // Inherited by the sections partial through @include, so a theme
            // layout needs no builder awareness of its own.
            'builderMode' => $builderMode,
            /*
             * Display conditions, evaluated per node at render.
             *
             * The BUILDER shows everything — you cannot edit what you cannot
             * see, and a section restricted to signed-in visitors would
             * otherwise vanish from the canvas along with any way to lift the
             * restriction.
             *
             * Where a conditioned node is called out is the NAVIGATOR, which
             * badges any node carrying `settings.conditions`. The canvas does
             * not mark them: it renders the published markup exactly, and an
             * attribute only the builder emits is a difference between what
             * the canvas shows and what ships. If a canvas badge is wanted it
             * belongs in the overlay, drawn from the document the builder
             * already holds, rather than from markup smuggled through the
             * render.
             */
            /*
             * The page's own settings, as a stylesheet on `body`.
             *
             * Passed to the sections partial rather than to the layout: a
             * theme replaces the whole layout file, so a page background
             * printed there would silently stop working on any theme that
             * had not heard of it. The partial is included by every layout
             * and already owns the utilities a theme must not forget.
             */
            'pageCss' => ($withParts ? $this->chromeCss($context) : '')
                .($context === null
                    ? ''
                    : PageSettings::css(
                        is_array($pageSettings = $context->getAttribute('page_settings')) ? $pageSettings : null,
                    )),
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
            // The current page's published translations, locale => URL —
            // what the locale-switcher block renders. Empty off-page.
            'localeAlternates' => $this->localeAlternates($context),
        ])->render();
    }

    /**
     * @return array<string, string> locale => site-relative URL
     */
    private function localeAlternates(?Entry $context): array
    {
        $group = $context?->translation_group;
        $handle = $context?->getHandle();
        if (! is_string($group) || $group === '' || ! is_string($handle)) {
            return [];
        }

        $fallback = LocalizationSettings::get()->fallback_locale;

        $alternates = [];
        foreach (Entry::type($handle)
            ->where('translation_group', $group)
            ->where('status', EntryStatus::Published->value)
            ->orderBy('locale')
            ->get() as $sibling) {
            $locale = $sibling->getAttribute('locale');
            // A row with no locale recorded IS the fallback locale
            // (EntryManager stores '' when a create names none).
            $locale = is_string($locale) && $locale !== '' ? $locale : $fallback;
            $path = $sibling->getAttribute('path') ?? $sibling->getAttribute('slug');
            if (is_string($path) && $path !== '') {
                $alternates[$locale] = $this->localePrefix->urlFor($locale, $path);
            }
        }

        return $alternates;
    }

    /**
     * Every published popup document as a dismissible overlay, or null when
     * none renders. Each popup's own §C9 conditions decide visibility (a
     * fully hidden popup emits nothing); a dismissed popup stays dismissed
     * per browser via localStorage. Self-contained: style + script ship
     * inline with the first popup.
     */
    private function renderPopups(?Entry $context = null): ?string
    {
        $overlays = '';
        $path = request()->path();

        foreach ($this->parts->popups() as $popup) {
            // Path targeting is a server decision: the path is already in
            // the cache key, so a page-limited popup costs nothing.
            if (! PopupTargeting::matchesPath($popup['document'], $path)) {
                continue;
            }

            $tree = PageTree::fromArray($popup['document']);

            // Every root section conditioned away for this visitor = no
            // popup at all, not an empty white box. Checked BEFORE the
            // partial renders: its @once utility style would otherwise
            // make the output non-empty even with zero sections shown.
            $anyVisible = false;
            foreach ($tree->sections as $section) {
                if (! $section->isRef()
                    && $this->conditions->evaluate($section->settings, auth()->user(), now())->visible
                ) {
                    $anyVisible = true;
                    break;
                }
            }
            if (! $anyVisible) {
                continue;
            }

            $sections = view('magna-pages::partials.sections', [
                'tree' => $tree,
                'registry' => $this->registry,
                'resolver' => $this->resolver,
                'blockViewFor' => fn (string $handle): ?string => $this->themeViews->blockView($handle),
                'conditionsPass' => fn (array $settings): bool => $this->conditions
                    ->evaluate($settings, auth()->user(), now())->visible,
                'resolveBindings' => fn (BlockNode $block): BlockNode => $block->withData(
                    $this->bindings->resolve($block->data, $context),
                ),
            ])->render();

            $overlays .= view('magna-pages::partials.popup', [
                'slug' => $popup['slug'],
                'title' => $popup['title'],
                'sectionsHtml' => $sections,
                ...PopupTargeting::behaviour($popup['document']),
            ])->render();
        }

        return $overlays === '' ? null : $overlays;
    }

    /**
     * The site's front door, as a theme should link to it.
     *
     * "/" whenever the page router can answer it, which is the common case
     * and the one PageRouteResolver already implements. When something else
     * owns the root — a Filament panel mounted there, most obviously — that
     * request never reaches the page router at all, and a wordmark linking
     * to "/" walks the visitor out of the site. So the home page's OWN
     * address is used instead, built the way menus build a page link.
     */
    private function homeUrl(): string
    {
        if (! $this->rootIsClaimed()) {
            return '/';
        }

        $id = PagesSettings::get()->home_page_id;

        if (! is_string($id) || $id === '' || ! $this->schemaRegistry->has('page')) {
            return '/';
        }

        $home = Entry::type('page')
            ->where('id', $id)
            ->where('status', EntryStatus::Published->value)
            ->first();

        if ($home === null) {
            return '/';
        }

        $path = $home->getAttribute('path');
        $slug = $home->getAttribute('slug');
        $segment = is_string($path) && $path !== '' ? $path : (is_string($slug) ? $slug : '');

        return $segment === '' ? '/' : '/'.$segment;
    }

    /**
     * Whether a route other than the page fallback answers GET /.
     *
     * Asked of the router rather than of any one package: a panel, a
     * marketing controller or another plugin could all be the thing sitting
     * on the root, and the answer a theme needs is the same in every case.
     * The page fallback's own URI is a placeholder, never a literal "/".
     */
    private function rootIsClaimed(): bool
    {
        // Memoised: the route table cannot change mid-request, and this
        // walks all of it.
        if ($this->rootClaimed !== null) {
            return $this->rootClaimed;
        }

        $claimed = false;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === '/' && in_array('GET', $route->methods(), true)) {
                $claimed = true;
                break;
            }
        }

        return $this->rootClaimed = $claimed;
    }

    /**
     * A template part rendered through the same section pipeline, or null
     * when the part does not exist or is not published.
     */
    private function renderPart(string $handle, ?Entry $context = null, bool $builderMode = false): ?string
    {
        /*
         * Which header or footer this page gets: the page's own choice,
         * then the site default, then a part whose slug is literally
         * "header"/"footer". The last rung is what keeps every site that
         * predates this feature rendering exactly as it does today.
         */
        $entry = $this->parts->chromeEntry($handle, $context);
        if ($entry === null) {
            return null;
        }

        $document = $entry->getAttribute('blocks_data');
        $tree = PageTree::fromArray(is_array($document) ? $document : []);
        if ($tree->sections === []) {
            return null;
        }

        $behaviour = $this->parts->chromeBehaviour($entry);

        $html = view('magna-pages::partials.sections', [
            'tree' => $tree,
            'registry' => $this->registry,
            'resolver' => $this->resolver,
            'blockViewFor' => fn (string $handle): ?string => $this->themeViews->blockView($handle),
            // In the builder a part's nodes are marked like any other, plus
            // the entry they belong to — a header is a different document
            // from the page it sits on, and an edit must reach the right one.
            'builderMode' => $builderMode,
            'partDocumentId' => $builderMode ? (string) $entry->getKey() : null,
            // Parts obey conditions on the public site like any section.
            'conditionsPass' => fn (array $settings): bool => $this->conditions
                ->evaluate($settings, auth()->user(), now())->visible,
            // A part's bindings mean the page it appears on.
            'resolveBindings' => fn (BlockNode $block): BlockNode => $block->withData(
                $this->bindings->resolve($block->data, $context),
            ),
        ])->render();

        /*
         * Chrome is wrapped so its behaviour has something to attach to.
         *
         * Sticky is the awkward one: a theme puts our HTML inside its own
         * <header>, and an element can only stick within its parent's box —
         * so sticking the wrapper alone would do nothing, because the
         * wrapper is exactly as tall as its parent. The rule therefore
         * names the wrapper AND whatever element holds it, through :has().
         * :where() keeps the specificity at zero so a theme that wants to
         * disagree still can.
         */
        $classes = 'magna-chrome magna-chrome--'.$handle;
        if ($behaviour['sticky']) {
            $classes .= ' magna-chrome--sticky';
        }
        if ($behaviour['stickyMobile']) {
            $classes .= ' magna-chrome--sticky-mobile';
        }

        /*
         * In the builder the wrapper also says WHICH chrome it is and
         * which document holds it, so a click on the header can offer to
         * edit the header. Two attributes on a wrapper we already emit —
         * nothing of it reaches a published page.
         */
        $marker = '';
        if ($builderMode) {
            $marker = ' data-magna-chrome="'.$handle.'"'
                .' data-magna-chrome-id="'.e((string) $entry->getKey()).'"'
                .' data-magna-chrome-title="'.e((string) ($entry->getAttribute('title') ?? $handle)).'"';
        }

        return '<div class="'.$classes.'"'.$marker.'>'.$html.'</div>';
    }

    /**
     * The stylesheet chrome behaviour needs, or an empty string.
     *
     * Emitted once per page beside the document's own styles, so a theme
     * cannot forget it and a page with ordinary chrome pays nothing.
     */
    private function chromeCss(?Entry $context): string
    {
        $sticky = false;
        $stickyMobile = false;
        foreach (['header', 'footer'] as $role) {
            $behaviour = $this->parts->chromeBehaviour($this->parts->chromeEntry($role, $context));
            $sticky = $sticky || $behaviour['sticky'];
            $stickyMobile = $stickyMobile || $behaviour['stickyMobile'];
        }

        if (! $sticky && ! $stickyMobile) {
            return '';
        }

        /*
         * The rule names the wrapper AND whatever holds it: a theme puts
         * our chrome inside its own <header>, and an element only sticks
         * within its parent's box — so sticking the wrapper alone would
         * emit correct CSS that does nothing.
         *
         * Desktop and mobile are separate rules rather than one, because
         * they are separate answers: a header that follows you down a wide
         * page can eat a third of a phone screen.
         */
        $stick = '{position:sticky;top:0;z-index:50}';
        $unstick = '{position:static}';

        $css = '';
        if ($sticky) {
            $css .= '@media (min-width: 768px){.magna-chrome--sticky,'
                .':where(header,footer,div,section):has(>.magna-chrome--sticky)'.$stick.'}';
        }
        if ($stickyMobile) {
            $css .= '@media (max-width: 767.98px){.magna-chrome--sticky-mobile,'
                .':where(header,footer,div,section):has(>.magna-chrome--sticky-mobile)'.$stick.'}';
        } else {
            // Said explicitly: a header sticky on desktop must let go on a
            // phone, and inheriting the desktop rule is how it would not.
            $css .= '@media (max-width: 767.98px){.magna-chrome--sticky,'
                .':where(header,footer,div,section):has(>.magna-chrome--sticky)'.$unstick.'}';
        }

        return $css;
    }
}
