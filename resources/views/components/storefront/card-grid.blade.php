@props(['theme' => null, 'columns' => 4, 'mobileColumns' => 2, 'kind' => 'listing'])
{{-- Grid of product cards (TH-11, TH-13). The classes depend on the card variant (C5 = one column of price-list rows, C6 = no gaps) and on the listing variant (L3 = one column more on a wide screen); with `default` (C1, L1) they are the classes the grids had before themes. kind: listing (catalog, category, search) | home (home product block). --}}
@php
    $card = (string) ($theme->blocks['card'] ?? 'C1');
    $dense = $kind === 'listing' && ($theme->blocks['listing'] ?? 'L1') === 'L3';
    $phone = $mobileColumns === 1 ? 'grid-cols-1 min-[30rem]:grid-cols-2' : 'grid-cols-2';

    // The home grid: a theme may fix the desktop columns (homeGridColumns extra, 3…6), otherwise the setting of the shop.
    $homeColumns = $kind === 'home' ? (\App\Services\Themes\BlockExtras::homeGridColumns($theme) ?? $columns) : $columns;

    if ($card === 'C5') {
        $grid = 'grid-cols-1 gap-0 border-t border-line';
    } else {
        [$md, $rest] = match (true) {
            $kind === 'home' => ['md:grid-cols-3', match ($homeColumns) {
                3 => 'lg:grid-cols-3',
                5 => 'lg:grid-cols-5',
                6 => 'lg:grid-cols-6',
                default => 'lg:grid-cols-4',
            }],
            $dense && $columns === 3 => ['md:grid-cols-3', 'lg:grid-cols-4'],
            $dense => ['md:grid-cols-3', 'lg:grid-cols-4 xl:grid-cols-5'],
            $columns === 3 => ['md:grid-cols-2', 'lg:grid-cols-3'],
            default => ['md:grid-cols-2', 'lg:grid-cols-3 xl:grid-cols-4'],
        };
        // C6: no gaps between cells.
        $grid = $phone.' '.($card === 'C6' ? 'gap-0' : 'gap-3').' '.$md.' '.($card === 'C6' ? '' : 'md:gap-4 ').$rest;
    }
@endphp
<ul {{ $attributes->merge(['class' => 'grid '.$grid]) }} data-card-grid="{{ $card }}">{{ $slot }}</ul>
