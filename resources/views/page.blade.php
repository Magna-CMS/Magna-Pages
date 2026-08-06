<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    {{--
        Layout shell v1: semantic structure only. Theme tokens, compiled
        per-page CSS, SEO/meta slots, and header/footer template parts land
        with the themes-v2 loader (docs/magna-pages/04-THEMES-V2.md §3).
    --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; line-height: 1.6; color: #111; }
        img { max-width: 100%; height: auto; }
        .magna-section__inner { margin-inline: auto; padding: 2rem 1rem; }
        .magna-columns { display: flex; flex-wrap: wrap; gap: 1.5rem; }
        .magna-column { min-width: 0; }
        @media (max-width: 640px) { .magna-columns { flex-direction: column; } .magna-column { flex: 1 1 100% !important; } }
    </style>
</head>
<body>

<main>
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
                                @if($registry->has($block->block))
                                    @include('magna::blocks.' . $block->block, [
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
</main>

</body>
</html>
