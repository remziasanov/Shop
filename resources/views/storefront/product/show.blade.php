@extends('storefront.layouts.app')

@section('content')
    @include('storefront.partials.breadcrumbs')

    <div class="grid gap-6 md:grid-cols-2 md:gap-10">
        {{-- SF-30: gallery. --}}
        <section aria-label="{{ __('storefront.gallery.label') }}" x-data="productGallery({{ count($gallery) }}@if ($picker !== null), @js(array_map(fn ($image) => $image['option_value_id'], $gallery))@endif)"
                 @variant-color.window="setColor($event.detail.id)"
                 @keydown.arrow-left="fullscreen && prev()" @keydown.arrow-right="fullscreen && next()" @keydown.escape.window="fullscreen && closeFullscreen()">
            @if ($gallery === [])
                <div class="aspect-square overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                    @include('storefront.partials.image-placeholder', ['url' => $view->placeholderUrl, 'alt' => $product->name])
                </div>
            @else
                <div class="relative overflow-hidden rounded-lg border border-slate-200 bg-slate-50"
                     @touchstart.passive="touchStart($event)" @touchend.passive="touchEnd($event)"
                     @keydown.arrow-left="prev()" @keydown.arrow-right="next()">
                    @foreach ($gallery as $i => $image)
                        <button type="button" data-gallery-open @click="openFullscreen()" x-show="index === {{ $i }}" @if ($i > 0) x-cloak @endif
                                class="block aspect-square w-full cursor-zoom-in focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                                aria-label="{{ __('storefront.gallery.fullscreen') }}">
                            <img src="{{ $image['src'] }}" srcset="{{ $image['srcset'] }}" sizes="(min-width: 768px) 50vw, 100vw"
                                 width="{{ $image['width'] }}" height="{{ $image['height'] }}" alt="{{ $image['alt'] }}"
                                 @if ($i > 0) loading="lazy" @else fetchpriority="high" @endif class="h-full w-full object-contain">
                        </button>
                    @endforeach

                    @if (count($gallery) > 1)
                        <button type="button" @click="prev()" class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-2 shadow focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" aria-label="{{ __('storefront.gallery.prev') }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <button type="button" @click="next()" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-2 shadow focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" aria-label="{{ __('storefront.gallery.next') }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    @endif
                </div>

                @if (count($gallery) > 1)
                    <ul class="mt-3 flex gap-2 overflow-x-auto pb-1">
                        @foreach ($gallery as $i => $image)
                            <li class="flex-none" x-show="allowed({{ $i }})">
                                <button type="button" @click="go({{ $i }})" :aria-current="(index === {{ $i }}).toString()"
                                        class="block h-16 w-16 overflow-hidden rounded border bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                                        :class="index === {{ $i }} ? 'border-primary ring-1 ring-primary' : 'border-slate-200'"
                                        aria-label="{{ __('storefront.gallery.thumb', ['n' => $i + 1]) }}">
                                    <img src="{{ $image['thumb'] }}" alt="" width="64" height="64" loading="lazy" class="h-full w-full object-cover">
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Fullscreen view. --}}
                <div x-cloak x-show="fullscreen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/90"
                     role="dialog" aria-modal="true" aria-label="{{ __('storefront.gallery.label') }}"
                     @touchstart.passive="touchStart($event)" @touchend.passive="touchEnd($event)">
                    <button type="button" x-ref="close" @click="closeFullscreen()" class="absolute right-3 top-3 rounded-full bg-white/90 p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white" aria-label="{{ __('storefront.gallery.close') }}">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                    @foreach ($gallery as $i => $image)
                        {{-- NF-03: the 1600 px variant is requested only while this slide is shown in the open fullscreen view. --}}
                        <img x-show="index === {{ $i }}" @if ($i > 0) x-cloak @endif :src="fullscreen && index === {{ $i }} ? @js($image['full']) : null"
                             width="{{ $image['width'] }}" height="{{ $image['height'] }}" alt="{{ $image['alt'] }}" class="max-h-full max-w-full object-contain p-4">
                    @endforeach
                    @if (count($gallery) > 1)
                        <button type="button" @click="prev()" class="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white" aria-label="{{ __('storefront.gallery.prev') }}">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <button type="button" @click="next()" class="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white" aria-label="{{ __('storefront.gallery.next') }}">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                        <p class="absolute bottom-3 left-1/2 -translate-x-1/2 rounded bg-black/60 px-3 py-1 text-sm text-white" aria-live="polite" x-text="@js(__('storefront.gallery.counter', ['current' => ':c', 'total' => ':t'])).replace(':c', position()).replace(':t', total())"></p>
                    @endif
                </div>
            @endif
        </section>

        {{-- SF-31, SF-32, SF-34 (short description), SF-33 (actions). --}}
        @if ($picker !== null)
            {{-- VR-09: the variants are embedded as JSON; choosing one changes the price, availability, buttons and photos without a request. --}}
            <script type="application/json" id="variant-data">{!! $picker->json() !!}</script>
        @endif
        @php($tierPairs = array_map(fn (array $tier): array => [$tier['min'], $tier['kopecks']], $view->tiers))
        {{-- GP-10: the quantity field of the buy block feeds the tier table, the unit price and the total (Alpine, no request). --}}
        <div @if ($picker !== null) x-data="variantPicker(@js(['unavailable' => __('variant_storefront.unavailable'), 'cart' => __('storefront.actions.cart'), 'backorder' => __('sales.actions.backorder'), 'reserve' => __('storefront.actions.reserve_to_cart'), 'pair' => __('variants.label.pair', ['option' => ':option', 'value' => ':value']), 'separator' => __('variants.label.separator')]))" @input="onQty($event)" @cart-updated.window="resetQty()" @elseif ($view->tiers !== []) x-data="tierPrice(@js($view->unitKopecks), @js($tierPairs))" @input="onQty($event)" @cart-updated.window="reset()" @endif>
            @include('storefront.partials.labels', ['labels' => $view->labels, 'class' => 'mb-2'])
            <h1 class="text-2xl font-bold md:text-3xl">{{ $product->name }}</h1>

            <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm text-slate-600">
                <dt>{{ __('storefront.product.sku') }}</dt>
                @if ($picker !== null)
                    <dd class="text-slate-900" data-variant-sku x-text="current ? current.sku : ''">{{ $picker->selected->sku }}</dd>
                    @if (collect($picker->variants)->contains(fn ($v) => ! empty($v['bc'])))
                        <dt x-show="current && current.bc">{{ __('variant_storefront.barcode') }}</dt>
                        <dd class="text-slate-900" data-variant-barcode x-show="current && current.bc" x-text="current ? current.bc : ''">{{ $picker->selected->barcode }}</dd>
                    @endif
                @else
                    <dd class="text-slate-900">{{ $product->sku }}</dd>
                @endif
                @if ($product->brand)
                    <dt>{{ __('storefront.product.brand') }}</dt>
                    <dd class="text-slate-900">{{ $product->brand }}</dd>
                @endif
            </dl>

            @if ($picker !== null)
                @include('storefront.product.variant-picker', ['picker' => $picker])
            @endif

            @if ($picker !== null && ! $view->priceOnRequest)
                <p class="mt-4 flex flex-wrap items-baseline gap-x-2" data-variant-price>
                    <span class="text-3xl font-bold" x-text="current ? current.p : ''">{{ $view->price }}</span>
                    <s class="text-sm text-slate-500" x-cloak x-show="current && current.o"><span class="sr-only">{{ __('storefront.labels.sale') }}:</span> <span x-text="current ? current.o : ''">{{ $view->oldPrice }}</span></s>
                    <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700" x-cloak x-show="current && current.d" x-text="current && current.d ? @js(__('storefront.product.discount', ['percent' => ':p'])).replace(':p', current.d) : ''">@if ($view->discount) {{ __('storefront.product.discount', ['percent' => $view->discount]) }} @endif</span>
                    <s class="text-sm text-slate-500" data-retail-price x-cloak x-show="current && current.r"><span class="sr-only">{{ __('group_prices.retail_price') }}:</span> <span x-text="current ? current.r : ''"></span></s>
                </p>
            @else
                @include('storefront.partials.price', ['view' => $view, 'class' => 'mt-4', 'size' => 'text-3xl'])
            @endif
            @unless ($view->priceOnRequest)
                <p class="text-sm text-slate-500">{{ __('storefront.product.per_unit', ['unit' => $product->unit]) }}</p>
            @endunless

            @unless ($view->priceOnRequest)
                @include('storefront.product.tier-table', ['view' => $view, 'picker' => $picker, 'unit' => $product->unit])
            @endunless

            <div class="mt-2">
                @if ($picker !== null)
                    <p class="flex items-center gap-1.5 text-sm" data-variant-stock x-cloak x-show="current && current.s"
                       :class="current ? ({ in: 'text-emerald-700', low: 'text-amber-700', out: 'text-slate-500' }[current.st] || '') : ''">
                        <span class="h-2 w-2 rounded-full" aria-hidden="true" :class="current ? ({ in: 'bg-emerald-600', low: 'bg-amber-500', out: 'bg-slate-400' }[current.st] || '') : ''"></span>
                        <span x-text="current ? current.s : ''">{{ $view->availability['text'] ?? '' }}</span>
                    </p>
                @else
                    @include('storefront.partials.availability', ['availability' => $view->availability])
                @endif
            </div>

            @if ($product->short_description)
                <p class="mt-4 whitespace-pre-line text-slate-700">{{ $product->short_description }}</p>
            @endif

            @if ($view->mode === \App\Enums\SalesMode::Showcase && ! empty($shopPhones))
                {{-- Showcase mode (5.6): the shop phone instead of buttons. --}}
                <p class="mt-6 text-slate-700" data-showcase-phone>
                    {{ __('storefront.product.showcase_call') }}
                    <a href="tel:{{ preg_replace('/[^\d+]/', '', $shopPhones[0]) }}" class="font-semibold text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $shopPhones[0] }}</a>
                </p>
            @endif

            {{-- SF-33: actions by sales mode and stock (ProductActions); Livewire components handle cart, reservation and inquiry. --}}
            @if ($picker !== null)
                {{-- VR-09, VR-14: the picker shows the buttons that are possible for the selected variant; the components send the variant id with their action. --}}
                @php($pa = $view->productActions)
                @if ($pa->mode !== \App\Enums\SalesMode::Showcase)
                    <div class="mt-6 flex flex-wrap items-end gap-2">
                        @if ($pa->hasCartButton() || ($pa->hasReservationButton() && app(\App\Settings\SalesSettings::class)->reservation_via_cart))
                            <livewire:storefront.add-to-cart :product-id="$product->id" :show-qty="true" :variant-id="$picker->selected->id" :key="'cart-'.$product->id" />
                        @else
                            <button type="button" disabled x-cloak x-show="current && current.can.out" class="cursor-not-allowed rounded bg-slate-200 px-6 py-3 font-medium text-slate-500" data-action="unavailable">{{ __('sales.actions.out_of_stock') }}</button>
                        @endif
                        @if ($pa->hasReservationButton())
                            <livewire:storefront.reservation-form :product-id="$product->id" :variant-id="$picker->selected->id" :open="request()->boolean('reserve')" :key="'reserve-'.$product->id" />
                        @endif
                        <livewire:storefront.inquiry-form :product-id="$product->id" :variant-id="$picker->selected->id" :open="request()->boolean('inquiry')" :key="'inquiry-'.$product->id" />
                    </div>
                @endif
            @elseif ($product->has_variants)
                {{-- No active variant: nothing can be bought. --}}
                <div class="mt-6"><button type="button" disabled class="cursor-not-allowed rounded bg-slate-200 px-6 py-3 font-medium text-slate-500" data-action="unavailable">{{ __('sales.actions.out_of_stock') }}</button></div>
            @elseif ($view->actions !== [])
                <div class="mt-6 flex flex-wrap items-end gap-2">
                    @foreach ($view->actions as $action)
                        @if ($action['disabled'])
                            <button type="button" disabled class="cursor-not-allowed rounded bg-slate-200 px-6 py-3 font-medium text-slate-500" data-action="{{ $action['key'] }}">{{ $action['label'] }}</button>
                        @elseif ($action['key'] === 'cart')
                            <livewire:storefront.add-to-cart :product-id="$product->id" :show-qty="true" :key="'cart-'.$product->id" />
                        @elseif ($action['key'] === 'reservation')
                            <livewire:storefront.reservation-form :product-id="$product->id" :open="request()->boolean('reserve')" :key="'reserve-'.$product->id" />
                        @elseif ($action['key'] === 'inquiry')
                            <livewire:storefront.inquiry-form :product-id="$product->id" :open="request()->boolean('inquiry')" :key="'inquiry-'.$product->id" />
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- SF-34: tabs. --}}
    <section class="mt-10" x-data="{ tab: 'description' }">
        <div role="tablist" aria-label="{{ $product->name }}" class="flex gap-1 border-b border-slate-200">
            @foreach (['description' => __('storefront.product.tab_description'), 'specs' => __('storefront.product.tab_specs')] as $key => $label)
                <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}"
                        :aria-selected="(tab === '{{ $key }}').toString()" :tabindex="tab === '{{ $key }}' ? 0 : -1"
                        @click="tab = '{{ $key }}'"
                        @keydown.arrow-right.prevent="tab = tab === 'description' ? 'specs' : 'description'; $nextTick(() => document.getElementById('tab-' + tab).focus())"
                        @keydown.arrow-left.prevent="tab = tab === 'description' ? 'specs' : 'description'; $nextTick(() => document.getElementById('tab-' + tab).focus())"
                        class="-mb-px border-b-2 px-4 py-2 font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                        :class="tab === '{{ $key }}' ? 'border-primary text-primary' : 'border-transparent text-slate-600 hover:text-slate-900'">{{ $label }}</button>
            @endforeach
        </div>

        <div id="panel-description" role="tabpanel" aria-labelledby="tab-description" x-show="tab === 'description'" class="py-4">
            @if ($description !== '')
                {{-- Sanitized by HtmlSanitizer (NF-10). --}}
                <div class="max-w-3xl space-y-3 text-slate-700 [&_a]:text-primary [&_a]:underline [&_h2]:text-xl [&_h2]:font-semibold [&_h3]:text-lg [&_h3]:font-semibold [&_img]:max-w-full [&_ol]:list-decimal [&_ol]:pl-5 [&_table]:w-full [&_td]:border [&_td]:p-2 [&_th]:border [&_th]:p-2 [&_ul]:list-disc [&_ul]:pl-5">{!! $description !!}</div>
            @else
                <p class="text-slate-500">{{ __('storefront.product.no_description') }}</p>
            @endif
        </div>

        <div id="panel-specs" role="tabpanel" aria-labelledby="tab-specs" x-show="tab === 'specs'" x-cloak class="py-4">
            @if ($specs !== [])
                <table class="w-full max-w-3xl text-sm">
                    <caption class="sr-only">{{ __('storefront.product.tab_specs') }}</caption>
                    <tbody>
                        @foreach ($specs as $spec)
                            <tr class="border-b border-slate-100 odd:bg-slate-50">
                                <th scope="row" class="w-1/3 px-3 py-2 text-left font-normal text-slate-600">{{ $spec['name'] }}</th>
                                <td class="px-3 py-2">
                                    @if ($spec['links'] !== [])
                                        @foreach ($spec['links'] as $link)
                                            <a href="{{ $link['url'] }}" class="text-primary underline">{{ $link['text'] }}</a>@if (! $loop->last), @endif
                                        @endforeach
                                    @else
                                        {{ $spec['value'] }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-slate-500">{{ __('storefront.product.no_specs') }}</p>
            @endif
        </div>
    </section>

    {{-- SF-35: similar products. --}}
    @if ($similar !== [])
        <section class="mt-10" aria-labelledby="similar-heading">
            <h2 id="similar-heading" class="mb-4 text-xl font-semibold">{{ __('storefront.product.similar') }}</h2>
            <ul class="grid grid-cols-2 gap-3 md:gap-4 lg:grid-cols-4">
                @foreach ($similar as $card)
                    <li>@include('storefront.partials.product-card', ['view' => $card, 'eager' => false])</li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
