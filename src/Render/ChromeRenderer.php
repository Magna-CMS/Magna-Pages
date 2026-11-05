<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

use Magna\Blocks\BlockNode;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\PageTree;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Content\Entry;
use Magna\Pages\Render\Conditions\ConditionEvaluator;
use Magna\Pages\Templates\TemplatePartResolver;
use Magna\Pages\Themes\ThemeViewResolver;

/**
 * A page's published header and footer: the part each slot resolves to, drawn
 * through the section pipeline, and the stylesheet their behaviour needs.
 *
 * Split from PageRenderer, which still decides when chrome is drawn at all and
 * handles chrome being edited in its own slot.
 */
final class ChromeRenderer
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly BlockDataResolver $resolver,
        private readonly ThemeViewResolver $themeViews,
        private readonly TemplatePartResolver $parts,
        private readonly ConditionEvaluator $conditions,
        private readonly BindingResolver $bindings,
    ) {}

    /**
     * A template part rendered through the same section pipeline, or null
     * when the part does not exist or is not published.
     */
    public function render(string $handle, ?Entry $context = null, bool $builderMode = false): ?string
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
    public function css(?Entry $context): string
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
