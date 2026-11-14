{{-- SF-20, SF-21: search form with live suggestions (Alpine component "searchSuggest" in resources/js/app.js).
     $class: classes of the <form>; $panel: collapsed variant (H2, H4), visually hidden until opened or focused. --}}
@php($panel = $panel ?? false)
<form role="search" action="{{ route('search') }}" method="get"
      @if ($panel) id="header-search-panel" data-search-panel class="{{ $class }}" @unless (request()->routeIs('search')) data-collapsed @endunless :data-collapsed="search ? null : ''" @focusin="search = true" @focusout="if (! $el.contains($event.relatedTarget) && ! $event.relatedTarget?.closest('[data-search-toggle]')) search = {{ request()->routeIs('search') ? 'true' : 'false' }}" @else class="{{ $class }}" @endif
      x-data="searchSuggest(@js(route('search.suggest')), @js($searchQuery))"
      @click.outside="close()" @keydown.escape="close()">
    <label for="header-search" class="sr-only">{{ __('storefront.header.search_label') }}</label>
    <div class="flex">
        <input id="header-search" name="q" type="search" autocomplete="off" maxlength="100"
               placeholder="{{ __('storefront.header.search_placeholder') }}"
               class="w-full rounded-l-btn border border-line-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
               role="combobox" aria-autocomplete="list" aria-controls="search-suggestions" aria-haspopup="listbox"
               :aria-expanded="open.toString()" aria-expanded="false"
               :aria-activedescendant="active >= 0 ? 'suggest-' + active : null"
               x-model="query" @input="onInput()" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
               @keydown.enter="choose($event)" @focus="items.length && (open = true)">
        <button type="submit" class="rounded-r-btn bg-primary px-3 text-on-primary hover:opacity-90 active:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50 motion-reduce:transition-none" aria-label="{{ __('storefront.header.search_submit') }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z"/></svg>
        </button>
    </div>
    <ul id="search-suggestions" role="listbox" aria-label="{{ __('storefront.header.search_suggestions') }}" x-cloak x-show="open && items.length"
        class="absolute inset-x-0 top-full z-40 mt-1 max-h-96 overflow-y-auto rounded-btn border border-line bg-surface shadow-lg">
        <template x-for="(item, index) in items" :key="item.url">
            <li role="option" :id="'suggest-' + index" :aria-selected="(index === active).toString()">
                <a :href="item.url" class="flex items-center gap-3 px-3 py-2 text-sm hover:bg-surface-2" :class="index === active ? 'bg-surface-3' : ''" :data-age="item.age || null" @mouseenter="active = index">
                    <span class="age-media h-10 w-10 flex-none overflow-hidden rounded-btn">
                        <img x-show="item.image" :src="item.image" alt="" width="40" height="40" loading="lazy" class="age-media-img h-10 w-10 object-cover">
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate" x-text="item.name"></span>
                        <span class="block truncate text-xs text-muted" x-text="item.sku"></span>
                    </span>
                    <span class="flex-none whitespace-nowrap font-medium" x-text="item.price"></span>
                </a>
            </li>
        </template>
        <li class="border-t border-hairline">
            <button type="submit" class="block w-full px-3 py-2 text-left text-sm text-primary hover:bg-surface-2">{{ __('storefront.header.search_all_results') }}</button>
        </li>
    </ul>
</form>
