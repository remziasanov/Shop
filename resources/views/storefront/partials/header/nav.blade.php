{{-- SF-01: categories menu, desktop dropdown (CSS hover / focus-within, works without JS). $class: classes of the <nav>;
     $listClass: classes of the <ul>. --}}
<nav class="{{ $class }}" aria-label="{{ __('storefront.header.categories') }}" data-header-nav>
    <ul class="{{ $listClass }}">
        @foreach ($menuCategories as $item)
            <li class="group relative">
                <a href="{{ $item['url'] }}" class="block rounded-btn px-3 py-2 hover:text-primary active:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary motion-reduce:transition-none">{{ $item['name'] }}</a>
                @if ($item['children'] !== [])
                    <ul class="absolute left-0 top-full z-40 hidden min-w-56 rounded-btn border border-line bg-surface py-1 shadow-lg group-focus-within:block group-hover:block">
                        @foreach ($item['children'] as $child)
                            <li><a href="{{ $child['url'] }}" class="block px-4 py-2 hover:bg-surface-2 hover:text-primary focus-visible:bg-surface-2">{{ $child['name'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
