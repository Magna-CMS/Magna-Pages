<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    @if($tokensCss !== '')
        {{-- Active theme's design tokens as CSS custom properties (values
             pass the same injection filter as section tokenOverrides). --}}
        <style>{!! $tokensCss !!}</style>
    @endif
    {{-- Built-in fallback shell: semantic structure only. A theme replaces
         this whole file via views/system/layout.blade.php. --}}
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
    @include('magna-pages::partials.sections')
</main>

</body>
</html>
