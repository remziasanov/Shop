@if ($tiles !== [])
    <section aria-labelledby="{{ $headingId ?? 'tiles-heading' }}" class="mb-8">
        <h2 id="{{ $headingId ?? 'tiles-heading' }}" class="sr-only">{{ __('storefront.catalog.categories') }}</h2>
        <ul class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4">
            @foreach ($tiles as $tile)
                <li>
                    <a href="{{ $tile['url'] }}" class="flex h-full items-center gap-3 rounded-lg border border-slate-200 p-3 hover:border-primary hover:shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        <span class="h-14 w-14 flex-none overflow-hidden rounded bg-slate-50">
                            @if ($tile['image'])
                                <img src="{{ $tile['image'] }}" alt="" width="56" height="56" loading="lazy" class="h-full w-full object-cover">
                            @else
                                @include('storefront.partials.image-placeholder', ['url' => null, 'alt' => ''])
                            @endif
                        </span>
                        <span class="min-w-0">
                            <span class="block font-medium leading-snug">{{ $tile['name'] }}</span>
                            <span class="block text-xs text-slate-500">{{ trans_choice('storefront.catalog.products_count', $tile['count']) }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
