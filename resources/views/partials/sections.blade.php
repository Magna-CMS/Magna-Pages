{{--
    Shared section/column/block loop — included by the built-in shell AND by
    theme layouts (a theme layout composes this inside its own header/footer
    markup). Receives: $tree, $registry, $resolver, $blockViewFor.

    In builder mode (inherited from the parent view when the canvas renders)
    every node carries data-magna-node markers so a click in the iframe maps
    back to a document node. The markup is otherwise identical — the canvas
    renders through this same loop precisely so it cannot drift from the
    published page.
--}}
@php
    $inBuilder = ($builderMode ?? false) === true;
    $mark = fn (string $html, string $id, string $kind): string => $inBuilder
        ? app(\Magna\Pages\Render\BuilderMarkup::class)->mark($html, $id, $kind)
        : $html;

    // Per-device visibility (settings.visibility, written by both editors).
    // A device key that is absent means visible — only an explicit false
    // hides, so documents from before this feature render unchanged.
    $visibilityClasses = function (array $settings): string {
        $visibility = $settings['visibility'] ?? [];
        if (! is_array($visibility)) {
            return '';
        }
        $classes = '';
        foreach (['desktop', 'tablet', 'mobile'] as $device) {
            if (($visibility[$device] ?? true) === false) {
                $classes .= ' magna-hide-'.$device;
            }
        }

        return $classes;
    };
@endphp

{{--
    Structural utilities the document itself relies on (device visibility).
    Owned by this partial rather than by themes so a theme cannot forget
    them; @once because the partial runs again for header/footer parts.
    Breakpoints match the editor's three devices: mobile <768, tablet
    768-1023, desktop >=1024.
--}}
@once
    <style>
        @media (min-width: 1024px) { .magna-hide-desktop { display: none !important; } }
        @media (min-width: 768px) and (max-width: 1023.98px) { .magna-hide-tablet { display: none !important; } }
        @media (max-width: 767.98px) { .magna-hide-mobile { display: none !important; } }
        /* Motion presets: CSS-only entry animations (settings.motion).
           Reduced-motion preference wins unconditionally. */
        @keyframes magna-motion-fade { from { opacity: 0; } to { opacity: 1; } }
        @keyframes magna-motion-rise { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: none; } }
        .magna-motion--fade { animation: magna-motion-fade 0.7s ease-out both; }
        .magna-motion--rise { animation: magna-motion-rise 0.7s ease-out both; }
        @media (prefers-reduced-motion: reduce) {
            .magna-motion--fade, .magna-motion--rise { animation: none !important; }
        }
    </style>
@endonce
@php
    // Per-device style overrides, collected while the sections render and
    // emitted once at the end. Nothing accumulates unless a node actually
    // declares one, so a document that does not use them produces exactly
    // the markup it produced before they existed.
    $responsiveCss = '';

    // Display conditions: absent closure (older include sites) = show all.
    $passes = $conditionsPass ?? fn (array $settings): bool => true;
    // Bindings: absent closure = literals pass through untouched.
    $bound = $resolveBindings ?? fn ($block) => $block;
@endphp
@foreach($tree->sections as $section)
    @continue($section->isRef()) {{-- refs are spliced before parsing; a stray one renders nothing --}}
    @continue(! $passes($section->settings))

    @php
        $overrides = $section->tokenOverrides();
        $styleAttr = '';
        if ($overrides !== []) {
            $styleAttr = implode(';', array_map(
                fn (string $k, string $v): string => '--'.$k.':'.$v,
                array_keys($overrides),
                array_values($overrides),
            ));
        }
        $sectionStyle = $section->settings['style'] ?? null;

        // The ROW its columns lay out in is a different element from the
        // section, and the section's style attribute cannot reach it — so
        // row layout rides the stylesheet, base values included.
        $rowStyle = $section->settings['row'] ?? null;
        $rowCss = \Magna\Pages\Render\ResponsiveStyles::rulesFor(
            $section->id,
            $rowStyle,
            \Magna\Pages\Render\StyleDescriptors::ROW,
            withBase: true,
            within: '.magna-columns',
        );

        $sectionCss = \Magna\Pages\Render\ResponsiveStyles::rulesFor($section->id, $sectionStyle, \Magna\Pages\Render\StyleDescriptors::SECTION);
        $responsiveCss .= $sectionCss.$rowCss;

        // One class serves both: the section needs it if either it or its
        // row has anything in the stylesheet.
        $responsiveClass = ($sectionCss === '' && $rowCss === '')
            ? ''
            : ' '.\Magna\Pages\Render\ResponsiveStyles::nodeClass($section->id);

        $anchor = $section->settings['anchor'] ?? '';
        $cssClass = $section->settings['cssClass'] ?? '';
        $motion = $section->settings['motion'] ?? '';
        $motionClass = in_array($motion, ['fade', 'rise'], true) ? ' magna-motion--'.$motion : '';

        // Style controls (settings.style): an allowlisted vocabulary, so a
        // key the renderer does not know emits nothing rather than becoming
        // a way to write arbitrary CSS. Ahead of custom CSS in the
        // attribute, so a hand-written declaration still wins.
        $styleControls = \Magna\Pages\Render\StyleDescriptors::declarations(
            $section->settings['style'] ?? null,
            \Magna\Pages\Render\StyleDescriptors::SECTION,
        );
        if ($styleControls !== '') {
            $styleAttr = $styleAttr === '' ? $styleControls : $styleAttr.';'.$styleControls;
        }

        // Per-node custom CSS: validated declarations join the style
        // attribute — the attribute IS the sandbox (no selectors possible).
        $customCss = \Magna\Pages\Render\CustomCss::sanitize($section->settings['customCss'] ?? null);
        if ($customCss !== '') {
            $styleAttr = $styleAttr === '' ? $customCss : $styleAttr.';'.$customCss;
        }

        // A/B: sections sharing an experiment id are variants of it. Both
        // ship in the HTML — assignment is client-side and sticky, which
        // is what keeps the page in the SHARED cache. The builder shows
        // every variant; only the public site hides all but one.
        $experiment = $section->settings['experiment'] ?? null;
        $variant = $section->settings['variant'] ?? null;
        $isVariant = ! $inBuilder
            && is_string($experiment) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $experiment) === 1
            && is_string($variant) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $variant) === 1;
    @endphp

    @if($isVariant)
        @once
            <style>[data-magna-variant]{display:none}[data-magna-variant].magna-variant--on{display:block}</style>
            <script>
                (function () {
                    var seen = {};
                    document.addEventListener('DOMContentLoaded', function () {
                        var nodes = document.querySelectorAll('[data-magna-variant]');
                        var groups = {};
                        nodes.forEach(function (node) {
                            var name = node.getAttribute('data-magna-experiment');
                            (groups[name] = groups[name] || []).push(node);
                        });

                        function track(experiment, variant, event) {
                            try {
                                fetch('/pages-experiments/track', {
                                    method: 'POST', keepalive: true,
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ experiment: experiment, variant: variant, event: event }),
                                });
                            } catch (e) {}
                        }

                        Object.keys(groups).forEach(function (experiment) {
                            var members = groups[experiment];
                            var key = 'magna-ab:' + experiment;
                            var chosen = null;
                            try { chosen = window.localStorage.getItem(key); } catch (e) {}

                            var names = members.map(function (n) { return n.getAttribute('data-magna-variant'); });
                            if (names.indexOf(chosen) === -1) {
                                // Sticky assignment: a visitor keeps their
                                // variant, so the comparison stays honest.
                                chosen = names[Math.floor(Math.random() * names.length)];
                                try { window.localStorage.setItem(key, chosen); } catch (e) {}
                            }

                            members.forEach(function (node) {
                                var name = node.getAttribute('data-magna-variant');
                                if (name !== chosen) { node.remove(); return; }
                                node.classList.add('magna-variant--on');
                                if (!seen[experiment]) {
                                    seen[experiment] = true;
                                    track(experiment, name, 'exposure');
                                }
                                node.querySelectorAll('[data-magna-goal]').forEach(function (goal) {
                                    goal.addEventListener('click', function () {
                                        track(experiment, name, 'conversion');
                                    }, { once: true });
                                });
                            });
                        });
                    });
                })();
            </script>
        @endonce
    @endif
    <section
        class="magna-section{{ is_string($cssClass) && $cssClass !== '' ? ' '.e($cssClass) : '' }}{{ $visibilityClasses($section->settings) }}{{ $motionClass }}{{ $responsiveClass }}"
        @if(is_string($anchor) && $anchor !== '') id="{{ $anchor }}" @endif
        @if($styleAttr !== '') style="{{ $styleAttr }}" @endif
        @if($inBuilder) data-magna-node="{{ $section->id }}" data-magna-kind="section" @endif
        @if($isVariant) data-magna-experiment="{{ $experiment }}" data-magna-variant="{{ $variant }}" @endif
    >
        <div class="magna-section__inner">
            <div class="magna-columns">
                @foreach($section->columns as $column)
                    @php
                        // The span is the column's job and is never editable
                        // as a style; anything the editor set joins after it.
                        $columnStyle = 'flex: '.$column->span.' '.$column->span.' 0%';
                        $columnStyleSet = $column->settings['style'] ?? null;
                        $columnControls = \Magna\Pages\Render\StyleDescriptors::declarations(
                            $columnStyleSet,
                            \Magna\Pages\Render\StyleDescriptors::COLUMN,
                        );
                        if ($columnControls !== '') {
                            $columnStyle .= ';'.$columnControls;
                        }

                        $columnResponsive = '';
                        if (\Magna\Pages\Render\ResponsiveStyles::isResponsive($columnStyleSet)) {
                            $columnResponsive = ' '.\Magna\Pages\Render\ResponsiveStyles::nodeClass($column->id);
                            $responsiveCss .= \Magna\Pages\Render\ResponsiveStyles::rulesFor(
                                $column->id,
                                $columnStyleSet,
                                \Magna\Pages\Render\StyleDescriptors::COLUMN,
                            );
                        }
                    @endphp
                    <div
                        class="magna-column{{ $columnResponsive }}"
                        style="{{ $columnStyle }}"
                        @if($inBuilder) data-magna-node="{{ $column->id }}" data-magna-kind="column" @endif
                    >
                        @foreach($column->blocks as $block)
                            @continue(! $passes($block->settings))
                            @php
                                $blockView = $blockViewFor($block->block);

                                // A block renders its own markup, so its
                                // styles reach it through a class merged
                                // into that markup and a rule in the page
                                // stylesheet — never a second class or
                                // style attribute, which the parser would
                                // resolve in our favour and against the
                                // block's own.
                                $blockStyle = $block->settings['style'] ?? null;
                                $blockRules = \Magna\Pages\Render\ResponsiveStyles::rulesFor(
                                    $block->id,
                                    $blockStyle,
                                    \Magna\Pages\Render\StyleDescriptors::BLOCK,
                                    withBase: true,
                                );
                                $responsiveCss .= $blockRules;
                            @endphp
                            @if($blockView !== null)
                                {!! $mark(\Magna\Pages\Render\BlockStyleMarkup::withClass(view($blockView, [
                                    'block' => $resolver->viewPayload($bound($block)),
                                    'definition' => $registry->get($block->block),
                                    // Page-level context any block may use
                                    // (the locale switcher reads it).
                                    'localeAlternates' => $localeAlternates ?? [],
                                ])->render(), $blockRules === '' ? '' : \Magna\Pages\Render\ResponsiveStyles::nodeClass($block->id)), $block->id, 'block') !!}
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endforeach

{{--
    Per-device overrides for the nodes on this page, as one stylesheet.

    The renderer cannot choose a breakpoint — one cached body is served to
    every visitor — so it emits all of them and lets the browser decide.
    Every visitor gets identical bytes, which is what the shared cache
    requires. A page with no per-device values emits nothing at all.
--}}
@if($responsiveCss !== '')
    <style>{!! $responsiveCss !!}</style>
@endif
