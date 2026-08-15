{{--
    ONE block, and — through itself — its children.

    Extracted from the column loop so a container renders nested blocks
    through the SAME pipeline: same view lookup, same binding resolution,
    same style class, same builder markers, same display conditions. A
    container that rendered its own children would be a second block
    renderer, and the day the two disagreed the canvas would stop matching
    the published page for nested content only — the hardest kind of drift
    to notice.

    Children are rendered FIRST and handed to the block's view as
    `$childrenHtml`, so a container view is only a wrapper. Depth is not
    checked here: PageTreeValidator caps nesting at save (MAX_BLOCK_DEPTH),
    which is the authoritative guard — a render-time cap would silently
    hide content that was allowed to be stored.

    Receives: $block (BlockNode), $blockViewFor, $registry, $resolver,
    $mark, $passes, $bound, and $collectCss (a callback the caller uses to
    gather the page's per-node stylesheet).
--}}
@php
    $blockView = $blockViewFor($block->block);
@endphp
@if($blockView !== null)
    @php
        // A container styles the same way any block does, plus the flex
        // controls for arranging what it holds — so it reads a wider
        // vocabulary from the same `settings.style`. The DEFINITION says
        // which, never the handle: a plugin's own layout block lays its
        // children out on exactly the same terms core's does.
        $styleKind = $registry->get($block->block)?->container === true
            ? \Magna\Pages\Render\StyleDescriptors::CONTAINER
            : \Magna\Pages\Render\StyleDescriptors::BLOCK;

        // A block renders its own markup, so its styles reach it through a
        // class merged into that markup and a rule in the page stylesheet —
        // never a second class or style attribute, which the parser would
        // resolve in our favour and against the block's own.
        $blockRules = \Magna\Pages\Render\ResponsiveStyles::rulesFor(
            $block->id,
            $block->settings['style'] ?? null,
            $styleKind,
            withBase: true,
        );
        $collectCss($blockRules);

        // Children first: a container's view receives them already
        // rendered, which is what keeps this the only place blocks are
        // turned into HTML. A block that holds none pays for one empty
        // string, so nothing about an ordinary document changes.
        $childrenHtml = '';
        foreach ($block->children as $child) {
            if (! $passes($child->settings)) {
                continue;
            }

            $childrenHtml .= view('magna-pages::partials.block', [
                'block' => $child,
                'blockViewFor' => $blockViewFor,
                'registry' => $registry,
                'resolver' => $resolver,
                'mark' => $mark,
                'passes' => $passes,
                'bound' => $bound,
                'collectCss' => $collectCss,
                'localeAlternates' => $localeAlternates ?? [],
            ])->render();
        }
    @endphp
    {!! $mark(
        \Magna\Pages\Render\BlockStyleMarkup::withClass(
            view($blockView, [
                'block' => $resolver->viewPayload($bound($block)),
                'definition' => $registry->get($block->block),
                // Already-rendered children, for a block that holds any.
                'childrenHtml' => $childrenHtml,
                // Page-level context any block may use (the locale
                // switcher reads it).
                'localeAlternates' => $localeAlternates ?? [],
            ])->render(),
            $blockRules === '' ? '' : \Magna\Pages\Render\ResponsiveStyles::nodeClass($block->id),
        ),
        $block->id,
        'block',
    ) !!}
@endif
