{{--
    Built-in archive view for a mounted collection: semantic list of
    public items. Everything escaped; items were already reduced to public
    fields by the responder.
--}}
<div class="magna-collection magna-collection--archive">
    <h1 class="magna-collection__heading">{{ $typeLabel }}</h1>

    @if($items === [])
        <p class="magna-collection__empty">Nothing published here yet.</p>
    @else
        <ul class="magna-collection__items">
            @foreach($items as $item)
                <li class="magna-collection__item">
                    <a class="magna-collection__title" href="{{ $item['url'] }}">{{ $item['title'] }}</a>
                    @if(!empty($item['date']))
                        <time class="magna-collection__date">{{ $item['date'] }}</time>
                    @endif
                </li>
            @endforeach
        </ul>

        @include('magna::partials.pagination', ['pagination' => $pagination])
    @endif
</div>
