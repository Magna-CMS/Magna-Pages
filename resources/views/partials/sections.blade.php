{{--
    Shared section/column/block loop — included by the built-in shell AND by
    theme layouts (a theme layout composes this inside its own header/footer
    markup). Receives: $tree, $registry, $resolver, $blockViewFor.
--}}
@foreach($tree->sections as $section)
    @continue($section->isRef()) {{-- template parts resolve in a later milestone --}}

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
    >
        <div class="magna-section__inner">
            <div class="magna-columns">
                @foreach($section->columns as $column)
                    <div class="magna-column" style="flex: {{ $column->span }} {{ $column->span }} 0%">
                        @foreach($column->blocks as $block)
                            @php $blockView = $blockViewFor($block->block); @endphp
                            @if($blockView !== null)
                                @include($blockView, [
                                    'block' => $resolver->viewPayload($block),
                                    'definition' => $registry->get($block->block),
                                ])
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endforeach
