{{-- Block: nav — renders a menu's resolved link tree (one nesting level deep
     in markup; deeper levels render flat until the mega-menu milestone). --}}
@php $items = $block['_resolved']['items'] ?? []; @endphp
<nav class="magna-block magna-block--nav magna-nav">
    <ul class="magna-nav__list">
        @foreach($items as $item)
            <li class="magna-nav__item">
                <a href="{{ $item['url'] }}"
                   @if(!empty($item['target'])) target="{{ $item['target'] }}" rel="noopener noreferrer" @endif
                >{{ $item['label'] }}</a>
                @if(!empty($item['children']))
                    <ul class="magna-nav__sublist">
                        @foreach($item['children'] as $child)
                            <li class="magna-nav__item magna-nav__item--child">
                                <a href="{{ $child['url'] }}"
                                   @if(!empty($child['target'])) target="{{ $child['target'] }}" rel="noopener noreferrer" @endif
                                >{{ $child['label'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
