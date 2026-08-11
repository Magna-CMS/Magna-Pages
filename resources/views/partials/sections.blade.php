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
        $anchor = $section->settings['anchor'] ?? '';
        $cssClass = $section->settings['cssClass'] ?? '';
        $motion = $section->settings['motion'] ?? '';
        $motionClass = in_array($motion, ['fade', 'rise'], true) ? ' magna-motion--'.$motion : '';

        // Per-node custom CSS: validated declarations join the style
        // attribute — the attribute IS the sandbox (no selectors possible).
        $customCss = \Magna\Pages\Render\CustomCss::sanitize($section->settings['customCss'] ?? null);
        if ($customCss !== '') {
            $styleAttr = $styleAttr === '' ? $customCss : $styleAttr.';'.$customCss;
        }
    @endphp
    <section
        class="magna-section{{ is_string($cssClass) && $cssClass !== '' ? ' '.e($cssClass) : '' }}{{ $visibilityClasses($section->settings) }}{{ $motionClass }}"
        @if(is_string($anchor) && $anchor !== '') id="{{ $anchor }}" @endif
        @if($styleAttr !== '') style="{{ $styleAttr }}" @endif
        @if($inBuilder) data-magna-node="{{ $section->id }}" data-magna-kind="section" @endif
    >
        <div class="magna-section__inner">
            <div class="magna-columns">
                @foreach($section->columns as $column)
                    <div
                        class="magna-column"
                        style="flex: {{ $column->span }} {{ $column->span }} 0%"
                        @if($inBuilder) data-magna-node="{{ $column->id }}" data-magna-kind="column" @endif
                    >
                        @foreach($column->blocks as $block)
                            @continue(! $passes($block->settings))
                            @php $blockView = $blockViewFor($block->block); @endphp
                            @if($blockView !== null)
                                {!! $mark(view($blockView, [
                                    'block' => $resolver->viewPayload($bound($block)),
                                    'definition' => $registry->get($block->block),
                                    // Page-level context any block may use
                                    // (the locale switcher reads it).
                                    'localeAlternates' => $localeAlternates ?? [],
                                ])->render(), $block->id, 'block') !!}
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endforeach
