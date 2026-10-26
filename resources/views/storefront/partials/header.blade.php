<header class="border-b border-slate-200 bg-white" x-data="{ menu: false }" @keydown.escape.window="menu = false">
    <div class="mx-auto flex w-full max-w-7xl flex-wrap items-center gap-3 px-4 py-3">
        <button type="button" class="rounded p-2 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary md:hidden"
                aria-label="{{ __('storefront.header.menu') }}" aria-controls="mobile-menu" :aria-expanded="menu.toString()" aria-expanded="false" @click="menu = ! menu">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>

        <a href="{{ route('home') }}" class="flex items-center gap-2 rounded text-lg font-semibold text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" aria-label="{{ __('storefront.header.home') }}">
            @if ($shopLogoUrl)
                <img src="{{ $shopLogoUrl }}" alt="" class="h-8 w-auto">
            @endif
            <span>{{ $shopName }}</span>
        </a>

        {{-- SF-20, SF-21: search form with live suggestions (Alpine component "searchSuggest" in resources/js/app.js). --}}
        <form role="search" action="{{ route('search') }}" method="get"
              class="relative order-last w-full md:order-none md:ml-4 md:w-auto md:max-w-md md:flex-1"
              x-data="searchSuggest(@js(route('search.suggest')), @js($searchQuery))"
              @click.outside="close()" @keydown.escape="close()">
            <label for="header-search" class="sr-only">{{ __('storefront.header.search_label') }}</label>
            <div class="flex">
                <input id="header-search" name="q" type="search" autocomplete="off" maxlength="100"
                       placeholder="{{ __('storefront.header.search_placeholder') }}"
                       class="w-full rounded-l border border-slate-300 px-3 py-2 text-sm focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                       role="combobox" aria-autocomplete="list" aria-controls="search-suggestions" aria-haspopup="listbox"
                       :aria-expanded="open.toString()" aria-expanded="false"
                       :aria-activedescendant="active >= 0 ? 'suggest-' + active : null"
                       x-model="query" @input="onInput()" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                       @keydown.enter="choose($event)" @focus="items.length && (open = true)">
                <button type="submit" class="rounded-r bg-primary px-3 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" aria-label="{{ __('storefront.header.search_submit') }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z"/></svg>
                </button>
            </div>
            <ul id="search-suggestions" role="listbox" aria-label="{{ __('storefront.header.search_suggestions') }}" x-cloak x-show="open && items.length"
                class="absolute inset-x-0 top-full z-40 mt-1 max-h-96 overflow-y-auto rounded border border-slate-200 bg-white shadow-lg">
                <template x-for="(item, index) in items" :key="item.url">
                    <li role="option" :id="'suggest-' + index" :aria-selected="(index === active).toString()">
                        <a :href="item.url" class="flex items-center gap-3 px-3 py-2 text-sm hover:bg-slate-50" :class="index === active ? 'bg-slate-100' : ''" @mouseenter="active = index">
                            <img x-show="item.image" :src="item.image" alt="" width="40" height="40" loading="lazy" class="h-10 w-10 flex-none rounded object-cover">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate" x-text="item.name"></span>
                                <span class="block truncate text-xs text-slate-500" x-text="item.sku"></span>
                            </span>
                            <span class="flex-none whitespace-nowrap font-medium" x-text="item.price"></span>
                        </a>
                    </li>
                </template>
                <li class="border-t border-slate-100">
                    <button type="submit" class="block w-full px-3 py-2 text-left text-sm text-primary hover:bg-slate-50">{{ __('storefront.header.search_all_results') }}</button>
                </li>
            </ul>
        </form>

        <div class="ml-auto flex items-center gap-3">
            @if ($shopPhones !== [])
                <a href="tel:{{ preg_replace('/[^\d+]/', '', $shopPhones[0]) }}" class="hidden text-sm font-medium md:inline" aria-label="{{ __('storefront.header.phone') }}">{{ $shopPhones[0] }}</a>
            @endif
            @if ($cartEnabled)
                <livewire:storefront.mini-cart variant="header" />
            @endif
        </div>
    </div>

    @if ($menuCategories !== [])
        {{-- SF-01: categories menu, desktop dropdown (CSS hover / focus-within, works without JS). --}}
        <nav class="hidden border-t border-slate-100 md:block" aria-label="{{ __('storefront.header.categories') }}">
            <ul class="mx-auto flex w-full max-w-7xl flex-wrap gap-x-1 px-4 text-sm">
                @foreach ($menuCategories as $item)
                    <li class="group relative">
                        <a href="{{ $item['url'] }}" class="block rounded px-3 py-2 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $item['name'] }}</a>
                        @if ($item['children'] !== [])
                            <ul class="absolute left-0 top-full z-40 hidden min-w-56 rounded border border-slate-200 bg-white py-1 shadow-lg group-focus-within:block group-hover:block">
                                @foreach ($item['children'] as $child)
                                    <li><a href="{{ $child['url'] }}" class="block px-4 py-2 hover:bg-slate-50 hover:text-primary focus-visible:bg-slate-50">{{ $child['name'] }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>

        {{-- Mobile menu (burger). --}}
        <nav id="mobile-menu" x-cloak x-show="menu" class="border-t border-slate-200 md:hidden" aria-label="{{ __('storefront.header.categories') }}">
            <ul class="px-4 py-2 text-sm">
                @foreach ($menuCategories as $item)
                    <li class="py-1">
                        <a href="{{ $item['url'] }}" class="block py-1 font-medium">{{ $item['name'] }}</a>
                        @if ($item['children'] !== [])
                            <ul class="ml-4 border-l border-slate-200 pl-3">
                                @foreach ($item['children'] as $child)
                                    <li><a href="{{ $child['url'] }}" class="block py-1 text-slate-600">{{ $child['name'] }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif
</header>
