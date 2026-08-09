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
@endphp
@foreach($tree->sections as $section)
    @continue($section->isRef()) {{-- refs are spliced before parsing; a stray one renders nothing --}}

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
    @endphp
    <section
        class="magna-section{{ is_string($cssClass) && $cssClass !== '' ? ' '.e($cssClass) : '' }}"
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
                            @php $blockView = $blockViewFor($block->block); @endphp
                            @if($blockView !== null)
                                {!! $mark(view($blockView, [
                                    'block' => $resolver->viewPayload($block),
                                    'definition' => $registry->get($block->block),
                                ])->render(), $block->id, 'block') !!}
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endforeach
