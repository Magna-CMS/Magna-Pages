{{--
    Locale switcher: the current page's PUBLISHED translations as links —
    computed renderer-side from the entry's translation_group, passed in as
    $localeAlternates (locale => URL). One translation (or none) renders
    nothing: a switcher with one option is noise.
--}}
@php
    $alternates = $localeAlternates ?? [];
    $current = app()->getLocale();
@endphp

@if(count($alternates) > 1)
    <nav class="magna-locale-switcher" aria-label="Language">
        <ul class="magna-locale-switcher__items">
            @foreach($alternates as $locale => $url)
                <li class="magna-locale-switcher__item">
                    @if($locale === $current)
                        <span class="magna-locale-switcher__current" aria-current="true">{{ strtoupper($locale) }}</span>
                    @else
                        <a href="{{ $url }}" lang="{{ $locale }}" hreflang="{{ $locale }}">{{ strtoupper($locale) }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>
@endif
