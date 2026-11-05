<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

use Illuminate\Support\Facades\Route;
use Magna\Blocks\BlockNode;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\PageTree;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Content\Entry;
use Magna\Frontend\FrontendPage;
use Magna\Frontend\FrontendPageResult;
use Magna\Pages\Builder\PageSettings;
use Magna\Pages\Consent\ConsentScripts;
use Magna\Pages\Experiments\ExperimentTracker;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\PagesSettings;
use Magna\Pages\Render\Conditions\ConditionEvaluator;
use Magna\Pages\Routing\PageUrls;
use Magna\Pages\Templates\PopupTargeting;
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

    /** Whether a route other than the page fallback answers GET / (memoised). */
    private ?bool $rootClaimed = null;

    /** @var array<string, string|null> settings page id => its translation_group (memoised) */
    private array $settingsPageGroups = [];

    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly BlockDataResolver $resolver,
        private readonly ThemeViewResolver $themeViews,
        private readonly ThemeTokens $tokens,
        private readonly MenuManager $menus,
        private readonly TemplatePartResolver $parts,
        private readonly ConditionEvaluator $conditions,
        private readonly BindingResolver $bindings,
        private readonly LocaleAlternates $localeAlternates,
        private readonly PageUrls $pageUrls,
        private readonly ConsentScripts $consent,
        private readonly ExperimentTracker $experiments,
        private readonly DocumentHead $head,
        private readonly ChromeRenderer $chrome,
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
     * parts, theme tokens, header menu — every real page gets. A data
     * provider's result feeds the view and overrides title + description.
     */
    public function renderFrontendPage(FrontendPage $page, ?FrontendPageResult $result = null): string
    {
        $mode = $page->mode === FrontendPage::MODE_APP ? 'app' : 'content';
        $mainHtml = '<div class="magna-frontend-page magna-frontend-page--'.$mode.'">'
            .view($page->view, $result?->data ?? [])->render()
            .'</div>';

        return $this->renderDocument(
            [],
            $result?->title ?? $page->title,
            mainHtml: $mainHtml,
            metaDescription: $result?->metaDescription,
            localeAlternates: $result?->localeAlternates,
        );
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
     * @param  array<string, string>|null  $localeAlternates  locale => URL; null = the context entry's translations
     */
    public function renderDocument(array $document, string $title, bool $builderMode = false, bool $withParts = true, ?Entry $context = null, ?string $mainHtml = null, ?string $chromeSlot = null, ?string $metaDescription = null, ?array $localeAlternates = null): string
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

        $seoTitle = $this->entryString($context, 'meta_title');
        $metaDescription = $context === null ? trim($metaDescription ?? '') : $this->entryString($context, 'meta_description');
        $localeAlternates ??= $this->localeAlternates->forEntry($context);

        /*
         * What other plugins print in <head> (Magna SEO's meta, canonical,
         * share cards and structured data). Only for a document a visitor is
         * actually reading: never the builder canvas, never a header or
         * footer being edited in its slot, never a template part rendered
         * bare — each would advertise the wrong page to whoever reads it.
         */
        $head = ! $builderMode && $chromeSlot === null && $withParts && ! $this->head->isEmpty()
            ? $this->head->render(new DocumentHeadContext(
                kind: $context?->getHandle() === 'page' ? DocumentHeadContext::KIND_PAGE : DocumentHeadContext::KIND_FRONTEND,
                entry: $context,
                title: $title,
                seoTitle: $seoTitle,
                metaDescription: $metaDescription,
                localeAlternates: $localeAlternates,
                isHome: $this->isSettingsPage($context, PagesSettings::get()->home_page_id),
                isNotFound: $this->isSettingsPage($context, PagesSettings::get()->not_found_page_id),
                tree: $tree,
                requestUrl: request()->url(),
            ))
            : DocumentHeadFragment::empty();

        return view($this->themeViews->layoutView(), [
            'title' => $title,
            /*
             * What search engines and share cards read.
             *
             * A page's own title is written for the person already on the
             * site — "Home", "Pricing" — and a theme appends the site name
             * to it. A search result is read by someone who has never been
             * here, so a page may carry its own full title and a sentence
             * describing itself, and when it does that title stands alone:
             * the site name is already part of what the author wrote.
             *
             * Both are empty for anything that is not a page (a template
             * part, a plugin frontend page — whose provider may supply the
             * description), and a layout that has never heard of them keeps
             * working — it prints $title as before.
             */
            'seoTitle' => $seoTitle,
            'metaDescription' => $metaDescription,
            /*
             * The head contract (DocumentHead). `headHtml` is trusted,
             * escaped markup a layout prints right after its viewport tag;
             * `headProvides` names the tags it already carries (title,
             * description, alternates, icon, canonical) so the layout skips
             * its own. Both are empty when no provider contributes, so a
             * layout that ignores them renders exactly as before.
             */
            'headHtml' => $head->html,
            'headProvides' => $head->provides,
            // Pre-rendered main-slot HTML (plugin frontend pages). A theme
            // layout must print it instead of the section tree when set.
            'mainHtml' => $mainHtml,
            'siteName' => is_string($siteName) && $siteName !== '' ? $siteName : 'Magna',
            'headerMenu' => $this->menus->resolve(self::HEADER_MENU_HANDLE),
            // Any menu by handle, for chrome needing more than one navigation:
            // headerMenu above resolves one hardcoded handle, which was every
            // menu a layout could reach. Empty list for an unknown handle, so a
            // layout asking for a menu the site has not made degrades to no
            // links. Both pinned by PagesLayoutMenusTest in core; the theme
            // guide's layout-payload table explains when to reach for it.
            'menu' => fn (string $handle): array => $this->menus->resolve($handle),
            // Where a theme's wordmark should point. NOT always "/": the
            // page router only sees a path nothing else has claimed, and on
            // an install whose admin panel is mounted at the root the home
            // page lives at its own address instead. Resolved here for the
            // same reason the header menu is — views receive data.
            'homeUrl' => $this->homeUrl(),
            // Any published page's URL, by slug — how a layout links its
            // own CTA ("download", "pricing") without hardcoding a path
            // that breaks on rename, nesting or a locale prefix. Null for
            // a draft or unknown slug: the layout decides how a missing
            // target degrades. Deriving from $homeUrl is the trap this
            // closes — the home page's own address is not the site root.
            'pageUrl' => fn (string $slug): ?string => $this->pageUrls->forSlug($slug),
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
                : ($withParts ? $this->chrome->render('header', $context, $builderMode) : null),
            'footerPartHtml' => $chromeSlot === 'footer'
                ? $chromeHtml
                : ($withParts ? $this->chrome->render('footer', $context, $builderMode) : null),
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
            'pageCss' => ($withParts ? $this->chrome->css($context) : '')
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
            // what the locale-switcher block and hreflang links render. A
            // plugin frontend page's provider supplies its own.
            'localeAlternates' => $localeAlternates,
        ])->render();
    }

    /**
     * Whether the page being rendered is the one a site setting points at (the
     * home page, the not-found page) — or a translation of it, which is how
     * that page is served in another locale.
     */
    private function isSettingsPage(?Entry $context, ?string $id): bool
    {
        if ($context === null || $context->getHandle() !== 'page' || $id === null || $id === '') {
            return false;
        }

        if ($context->getKey() === $id) {
            return true;
        }

        $group = $context->translation_group;
        if (! is_string($group) || $group === '') {
            return false;
        }

        // One lookup per setting per request, however many documents render.
        if (! array_key_exists($id, $this->settingsPageGroups)) {
            $value = Entry::type('page')->whereKey($id)->value('translation_group');
            $this->settingsPageGroups[$id] = is_string($value) ? $value : null;
        }

        return $this->settingsPageGroups[$id] === $group;
    }

    /**
     * One trimmed string field off the page being rendered, or ''.
     */
    private function entryString(?Entry $context, string $field): string
    {
        $value = $context?->getAttribute($field);

        return is_string($value) ? trim($value) : '';
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

        if (! is_string($id) || $id === '') {
            return '/';
        }

        return $this->pageUrls->forIds([$id])[$id] ?? '/';
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
}
