{{-- Home page category tiles block (5.2). --}}
@include('storefront.partials.category-tiles', ['tiles' => $tiles, 'title' => $title ?? null, 'headingId' => 'home-cat-'.\Illuminate\Support\Str::random(6)])
