<form wire:submit="submit" class="relative grid gap-6 lg:grid-cols-[1fr_22rem]" novalidate aria-label="{{ __('storefront_sales.checkout.title') }}">
    <div class="space-y-6">
        <x-storefront.form-error />

        @if ($hasFlexible)
            {{-- MR-04: the switch works on the «Корзина и резерв» rows; rows of other kinds go where their mode says. --}}
            <fieldset class="space-y-2 rounded-lg border border-slate-200 p-4" data-checkout-choice>
                <legend class="px-1 text-lg font-semibold">{{ __('storefront_sales.checkout.choice_legend') }}</legend>
                <p class="text-sm text-slate-600">{{ __('storefront_sales.checkout.choice_hint') }}</p>
                @foreach (['order' => 'choice_order', 'reservation' => 'choice_reservation'] as $value => $key)
                    <label class="flex cursor-pointer items-center gap-3 rounded border border-slate-200 p-3 has-[:checked]:border-primary has-[:checked]:bg-slate-50">
                        <input type="radio" name="choice" value="{{ $value }}" wire:model.live="choice" class="h-4 w-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        <span class="font-medium">{{ __('storefront_sales.checkout.'.$key) }}</span>
                    </label>
                @endforeach
            </fieldset>
        @endif

        <fieldset class="space-y-4 rounded-lg border border-slate-200 p-4">
            <legend class="px-1 text-lg font-semibold">{{ __('storefront_sales.checkout.contacts') }}</legend>
            <x-storefront.field prefix="co" name="name" :label="__('storefront_sales.fields.name')" required autocomplete="name" maxlength="150" />
            <x-storefront.field prefix="co" name="phone" type="tel" :label="__('storefront_sales.fields.phone')" required autocomplete="tel" inputmode="tel"
                                :placeholder="__('storefront_sales.checkout.phone_placeholder')" x-data="phoneMask" x-on:input="format($event)" />
            <x-storefront.field prefix="co" name="email" type="email" :label="__('storefront_sales.fields.email')" autocomplete="email" maxlength="190" />
        </fieldset>

        @if ($hasOrder)
        <fieldset class="space-y-3 rounded-lg border border-slate-200 p-4">
            <legend class="px-1 text-lg font-semibold">{{ __('storefront_sales.checkout.delivery') }} <span class="text-red-700" aria-hidden="true">*</span></legend>
            @foreach ($deliveries as $option)
                @php($method = $option['method'])
                <label class="flex cursor-pointer items-start gap-3 rounded border border-slate-200 p-3 has-[:checked]:border-primary has-[:checked]:bg-slate-50">
                    <input type="radio" name="delivery" value="{{ $method->key }}" wire:model.live="delivery" class="mt-1 h-4 w-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    <span class="flex-1">
                        <span class="block font-medium">{{ $method->name }}</span>
                        @if ($method->description)
                            <span class="block text-sm text-slate-600">{{ $method->description }}</span>
                        @endif
                        @if ($method->freeFrom !== null && $method->price > 0)
                            <span class="block text-xs text-slate-500">{{ __('storefront_sales.checkout.free_from', ['amount' => \App\Support\Money::format($method->freeFrom)]) }}</span>
                        @endif
                    </span>
                    <span class="flex-none font-medium">{{ $option['price'] === 0 ? __('storefront_sales.checkout.free') : \App\Support\Money::format($option['price']) }}</span>
                </label>
            @endforeach
            @error('delivery')
                <p class="text-sm text-red-700">{{ $message }}</p>
            @enderror

            @if (count($savedAddresses) > 0)
                {{-- CA-09: saved addresses of the signed-in customer. --}}
                <div>
                    <label for="co-address-book" class="mb-1 block text-sm font-medium">{{ __('account.addresses.checkout_pick') }}</label>
                    <select id="co-address-book" wire:model.live="addressId" class="w-full rounded border border-slate-300 px-3 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" data-address-book>
                        <option value="">{{ __('account.addresses.checkout_other') }}</option>
                        @foreach ($savedAddresses as $saved)
                            <option value="{{ $saved->id }}">{{ $saved->label }}: {{ \Illuminate\Support\Str::limit($saved->address, 60) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <x-storefront.field prefix="co" name="address" type="textarea" :label="__('storefront_sales.fields.address')" :required="$selectedDelivery?->requiresAddress ?? false"
                                :placeholder="__('storefront_sales.checkout.address_placeholder')" autocomplete="street-address" maxlength="500" />
        </fieldset>

        <fieldset class="space-y-3 rounded-lg border border-slate-200 p-4">
            <legend class="px-1 text-lg font-semibold">{{ __('storefront_sales.checkout.payment') }} <span class="text-red-700" aria-hidden="true">*</span></legend>
            @foreach ($payments as $option)
                <label class="flex cursor-pointer items-start gap-3 rounded border border-slate-200 p-3 has-[:checked]:border-primary has-[:checked]:bg-slate-50">
                    <input type="radio" name="payment" value="{{ $option->key }}" wire:model="payment" class="mt-1 h-4 w-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    <span>
                        <span class="block font-medium">{{ $option->name }}</span>
                        @if ($option->description)
                            <span class="block text-sm text-slate-600">{{ $option->description }}</span>
                        @endif
                    </span>
                </label>
            @endforeach
            @error('payment')
                <p class="text-sm text-red-700">{{ $message }}</p>
            @enderror
        </fieldset>

        @endif

        @if ($hasReservation)
            {{-- MR-06: one term for the whole reservation, one optional desired date; no delivery and payment. --}}
            <div class="space-y-3 rounded-lg border border-slate-200 p-4" data-reservation-fields>
                <p class="text-sm text-slate-700">{{ __('storefront_sales.checkout.reservation_text', ['date' => $reservedUntil->format('d.m.Y H:i')]) }}</p>
                <x-storefront.field prefix="co" name="desiredDate" type="date" :label="__('storefront_sales.reservation.desired_date')" :min="now()->format('Y-m-d')" :max="$reservedUntil->format('Y-m-d')"
                                    :hint="__('storefront_sales.reservation.desired_hint', ['date' => $reservedUntil->format('d.m.Y')])" />
            </div>
        @endif

        <x-storefront.field prefix="co" name="comment" type="textarea" :label="__('storefront_sales.fields.comment')" maxlength="2000" />
    </div>

    {{-- SF-45: order summary; the delivery price and the total follow the chosen method. --}}
    <aside class="h-fit space-y-4 rounded-lg border border-slate-200 p-4 lg:sticky lg:top-4" aria-label="{{ __('storefront_sales.checkout.your_order') }}">
        <h2 class="text-lg font-semibold">{{ __('storefront_sales.checkout.your_order') }}</h2>
        @if ($summary !== null)
            @foreach (['order' => $orderLines, 'reservation' => $reservationLines] as $blockKey => $blockLines)
                @if ($blockLines !== [])
                    <div data-checkout-block="{{ $blockKey }}">
                        @if ($hasOrder && $hasReservation)
                            <h3 class="mb-1 font-semibold">{{ __('storefront_sales.checkout.block_'.$blockKey) }}</h3>
                        @endif
                        <ul class="space-y-1 text-sm">
                            @foreach ($blockLines as $line)
                                @php($lineDiscount = $promo['applied'] ? $promo['result']->discountFor($line->item->id) : 0)
                                <li class="flex justify-between gap-3"><span>{{ $line->product?->name }} × {{ $line->qty }}</span>
                                    <span class="flex-none text-right">
                                        @if ($lineDiscount > 0)
                                            <s class="block text-slate-500" data-line-old>{{ \App\Support\Money::format($line->lineTotal) }}</s>
                                            <span data-line-new>{{ \App\Support\Money::format($line->lineTotal - $lineDiscount) }}</span>
                                        @else
                                            {{ \App\Support\Money::format($line->lineTotal) }}
                                        @endif
                                    </span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        @endif
        @if ($summary !== null && $hasOrder)
            @include('livewire.storefront.partials.promo-box')
        @endif
        <dl class="space-y-1 border-t border-slate-200 pt-3 text-sm" aria-live="polite">
            @if ($hasOrder || ! $hasReservation)
                <div class="flex justify-between"><dt>{{ __($hasReservation ? 'storefront_sales.checkout.order_total' : 'storefront_sales.cart.items_total') }}</dt><dd>{{ \App\Support\Money::format($summary !== null ? $summary->itemsTotal : 0) }}</dd></div>
                @if ($promo['applied'] && $promo['discount'] > 0)
                    <div class="flex justify-between text-emerald-800" data-promo-discount><dt>{{ __('promo.box.discount', ['code' => $promo['code']]) }}</dt><dd>−{{ \App\Support\Money::format($promo['discount']) }}</dd></div>
                @endif
                <div class="flex justify-between"><dt>{{ __('storefront_sales.checkout.delivery_price') }}</dt><dd data-delivery-price>{{ $deliveryPrice === 0 ? __('storefront_sales.checkout.free') : \App\Support\Money::format($deliveryPrice) }}</dd></div>
            @endif
            @if ($hasReservation)
                <div class="flex justify-between" data-reservation-total><dt>{{ __('storefront_sales.checkout.reservation_total') }}</dt><dd>{{ \App\Support\Money::format($summary->reservationTotal) }}</dd></div>
            @endif
            <div class="flex justify-between border-t border-slate-200 pt-2 text-lg font-semibold"><dt>{{ __('storefront_sales.checkout.total') }}</dt><dd data-total>{{ \App\Support\Money::format($total + ($hasReservation && $summary !== null ? $summary->reservationTotal : 0)) }}</dd></div>
        </dl>

        @if ($hasOrder && $summary !== null && $summary->minOrderMissing > 0)
            <p class="rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900" data-min-order>
                {{ __('storefront_sales.checkout.min_order', ['min' => \App\Support\Money::format($summary->minOrderAmount), 'missing' => \App\Support\Money::format($summary->minOrderMissing)]) }}
            </p>
        @endif

        @if ($hasOrder && $hasReservation)
            {{-- MR-05: two requests, two numbers. --}}
            <p class="rounded border border-slate-300 bg-slate-50 p-2 text-sm text-slate-800" data-mixed-note>{{ __('storefront_sales.checkout.mixed_note') }}</p>
        @endif

        @if ($ageLevel)
            <x-storefront.age-confirm prefix="co" :level="$ageLevel" />
        @endif
        <x-storefront.consent prefix="co" :link="$consentLink" />
        <x-storefront.guard />

        <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="w-full rounded bg-primary px-4 py-3 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">
            <span wire:loading.remove wire:target="submit">{{ __($hasOrder && $hasReservation ? 'storefront_sales.checkout.submit_both' : ($hasReservation ? 'storefront_sales.checkout.submit_reserve' : 'storefront_sales.checkout.submit')) }}</span>
            <span wire:loading wire:target="submit">{{ __('storefront_sales.checkout.sending') }}</span>
        </button>
        <a href="{{ route('cart') }}" class="block text-center text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront_sales.checkout.back_to_cart') }}</a>
    </aside>
</form>
