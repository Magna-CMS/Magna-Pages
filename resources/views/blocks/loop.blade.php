{{--
    The Loop block: items from a plugin data source, rendered under the
    conventional keys (title/url/description/date/image). Everything is
    escaped — sources return presentation STRINGS, and even a source that
    misbehaves cannot inject markup through this view.

    $block['_resolved']['pagination'] is a Magna\Frontend\Pagination when
    the block pages through its source, null otherwise; core's
    magna::partials.pagination renders nothing for null or a single page.
--}}
@php
    $items = $block['_resolved']['items'] ?? [];
    $pagination = $block['_resolved']['pagination'] ?? null;
    $heading = $block['data']['heading'] ?? '';
@endphp

<div class="magna-loop">
    @if(is_string($heading) && $heading !== '')
        <h2 class="magna-loop__heading">{{ $heading }}</h2>
    @endif

    @if($items === [])
        {{-- An empty source renders nothing extra: a gap, not a broken box. --}}
    @else
        <ul class="magna-loop__items">
            @foreach($items as $item)
                <li class="magna-loop__item">
                    @if(!empty($item['image']))
                        <img class="magna-loop__image"
                             src="{{ \Magna\Blocks\Support\SafeUrl::sanitize($item['image']) }}"
                             alt="" loading="lazy">
                    @endif

                    <div class="magna-loop__body">
                        @if(!empty($item['url']))
                            <a class="magna-loop__title" href="{{ \Magna\Blocks\Support\SafeUrl::sanitize($item['url']) }}">
                                {{ $item['title'] ?? 'Untitled' }}
                            </a>
                        @else
                            <span class="magna-loop__title">{{ $item['title'] ?? 'Untitled' }}</span>
                        @endif

                        @if(!empty($item['description']))
                            <p class="magna-loop__description">{{ $item['description'] }}</p>
                        @endif

                        @if(!empty($item['date']))
                            <time class="magna-loop__date">{{ $item['date'] }}</time>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        @include('magna::partials.pagination', ['pagination' => $pagination])
    @endif
</div>
