{{-- Block: nav — renders a menu's resolved link tree, to whatever depth
     the menu actually has (partials/nav-items recurses). --}}
@php $items = $block['_resolved']['items'] ?? []; @endphp
<nav class="magna-block magna-block--nav magna-nav">
    @include('magna-pages::partials.nav-items', ['items' => $items, 'depth' => 0])
</nav>
