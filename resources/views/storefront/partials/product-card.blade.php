{{-- SF-15, TH-11. Product card dispatcher: the variant (C1…C6) comes from the active theme ($theme->blocks['card']); every variant shows the same data and actions. $eager: first cards of a listing are above the fold and load eagerly (NF-03). $view: App\Services\Catalog\ProductView. Optional $number: position in the listing (C6 numbering). --}}
@php
    $cardVariant = strtolower((string) ($theme->blocks['card'] ?? 'C1'));
    $cardVariant = in_array($cardVariant, ['c1', 'c2', 'c3', 'c4', 'c5', 'c6'], true) ? $cardVariant : 'c1';
    // Position of the card in its grid (read before any inner loop replaces $loop): the hook for tile colors and patterns (`data-tile-index`).
    $tileIndex = isset($loop) ? (int) $loop->index : 0;
    // MR-03: a reservation-mode card has its reserve button already; a cart-and-reservation card keeps the quick link to the one-product form.
    $cardActions = array_values(array_filter($view->actions, fn (array $action): bool => ! (($action['single'] ?? false) && $view->mode === \App\Enums\SalesMode::Reservation)));
@endphp
@include('storefront.partials.cards.'.$cardVariant)
