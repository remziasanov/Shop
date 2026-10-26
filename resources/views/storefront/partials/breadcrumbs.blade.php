{{-- SF-06. $breadcrumbs: list of ['name', 'url|null']; microdata BreadcrumbList (JSON-LD comes in stage 5). --}}
<nav aria-label="{{ __('storefront.breadcrumbs.label') }}" class="mb-4 text-sm text-slate-500">
    <ol class="flex flex-wrap items-center gap-x-2 gap-y-1" itemscope itemtype="https://schema.org/BreadcrumbList">
        @foreach ($breadcrumbs as $crumb)
            <li class="flex items-center gap-2" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                @if ($crumb['url'])
                    <a href="{{ $crumb['url'] }}" class="rounded hover:text-primary hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" itemprop="item"><span itemprop="name">{{ $crumb['name'] }}</span></a>
                    <span aria-hidden="true">/</span>
                @else
                    <span itemprop="name" class="text-slate-800" aria-current="page">{{ $crumb['name'] }}</span>
                @endif
                <meta itemprop="position" content="{{ $loop->iteration }}">
            </li>
        @endforeach
    </ol>
</nav>
