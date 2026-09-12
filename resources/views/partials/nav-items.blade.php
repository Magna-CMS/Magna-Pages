{{--
    One level of a menu, and — through itself — the levels below it.

    Recursive rather than two hand-written levels: the store has always
    allowed any depth and the builder now lets an editor create it, so a
    view that stopped at two would silently drop the third.

    Receives: $items, $depth.
--}}
<ul class="magna-nav__list magna-nav__list--depth-{{ $depth }}">
    @foreach($items as $item)
        <li class="magna-nav__item{{ $depth > 0 ? ' magna-nav__item--child' : '' }}{{ !empty($item['css_class']) ? ' '.e($item['css_class']) : '' }}">
            <a href="{{ $item['url'] }}"
               @if(!empty($item['title_attr'])) title="{{ $item['title_attr'] }}" @endif
               @if(!empty($item['rel'])) rel="{{ $item['rel'] }}" @endif
               @if(!empty($item['target'])) target="{{ $item['target'] }}" rel="noopener noreferrer" @endif
            >{{ $item['label'] }}</a>

            @if(!empty($item['description']))
                <span class="magna-nav__description">{{ $item['description'] }}</span>
            @endif

            @if(!empty($item['children']))
                @include('magna-pages::partials.nav-items', [
                    'items' => $item['children'],
                    'depth' => $depth + 1,
                ])
            @endif
        </li>
    @endforeach
</ul>
