{{--
    Built-in single view for a mounted collection entry: title plus the
    entry's PUBLIC fields only (§C4 — the responder already filtered).
    A site-designed {type}-single template document replaces this whole
    view when published.
--}}
<article class="magna-collection magna-collection--single">
    <h1 class="magna-collection__heading">{{ $title }}</h1>

    @if(!empty($item['date']))
        <time class="magna-collection__date">{{ $item['date'] }}</time>
    @endif

    @foreach($item['fields'] as $handle => $value)
        <div class="magna-collection__field magna-collection__field--{{ $handle }}">
            {{ $value }}
        </div>
    @endforeach
</article>
