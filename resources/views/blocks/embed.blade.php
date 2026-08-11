{{--
    Curated embed: the iframe src was rebuilt by the resolver from a
    provider constant + extracted id — the pasted URL itself never renders.
    No resolution (unknown provider) = no iframe, a gap.
--}}
@php
    $embedUrl = $block['_resolved']['embedUrl'] ?? null;
    $provider = $block['_resolved']['provider'] ?? '';
    $caption = $block['data']['caption'] ?? '';
@endphp

@if(is_string($embedUrl) && $embedUrl !== '')
    <figure class="magna-embed magna-embed--{{ $provider }}">
        <div class="magna-embed__frame" style="position:relative;padding-top:56.25%">
            <iframe
                src="{{ $embedUrl }}"
                style="position:absolute;inset:0;width:100%;height:100%;border:0"
                title="{{ is_string($caption) && $caption !== '' ? $caption : 'Embedded video' }}"
                loading="lazy"
                referrerpolicy="strict-origin-when-cross-origin"
                allow="accelerometer; encrypted-media; picture-in-picture"
                allowfullscreen
            ></iframe>
        </div>
        @if(is_string($caption) && $caption !== '')
            <figcaption class="magna-embed__caption">{{ $caption }}</figcaption>
        @endif
    </figure>
@endif
