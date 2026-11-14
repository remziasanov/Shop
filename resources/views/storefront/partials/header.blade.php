{{-- TH-10: the header variant H1…H4 comes from the theme (blocks.header); each variant is partials/header/h1…h4.
     The burger menu, MB-10 contacts and the install button are shared (partials/header/mobile). --}}
@php
    $headerVariant = \App\Services\Themes\BlockExtras::header($theme ?? null);
    $headerRule = \App\Services\Themes\BlockExtras::headerRule($theme ?? null);
@endphp
<header class="border-b border-line bg-surface" @if ($headerRule) style="border-bottom:{{ $headerRule }}" @endif data-header="{{ $headerVariant }}"
        x-data="{ menu: false, search: {{ request()->routeIs('search') ? 'true' : 'false' }} }" @keydown.escape.window="menu = false">
    @include('storefront.partials.header.'.strtolower($headerVariant))
    @include('storefront.partials.header.mobile')
</header>
