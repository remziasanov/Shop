<form wire:submit="submit" class="relative grid gap-6 lg:grid-cols-[1fr_22rem]" novalidate aria-label="{{ __('storefront_sales.checkout.title') }}">
    <div class="space-y-6">
        <x-storefront.form-error />

        <fieldset class="space-y-4 rounded-lg border border-slate-200 p-4">
            <legend class="px-1 text-lg font-semibold">{{ __('storefront_sales.checkout.contacts') }}</legend>
            <x-storefront.field prefix="co" name="name" :label="__('storefront_sales.fields.name')" required autocomplete="name" maxlength="150" />
            <x-storefront.field prefix="co" name="phone" type="tel" :label="__('storefront_sales.fields.phone')" required autocomplete="tel" inputmode="tel"
                                :placeholder="__('storefront_sales.checkout.phone_placeholder')" x-data="phoneMask" x-on:input="format($event)" />
            <x-storefront.field prefix="co" name="email" type="email" :label="__('storefront_sales.fields.email')" autocomplete="email" maxlength="190" />
        </fieldset>

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

        <x-storefront.field prefix="co" name="comment" type="textarea" :label="__('storefront_sales.fields.comment')" maxlength="2000" />
    </div>

    {{-- SF-45: order summary; the delivery price and the total follow the chosen method. --}}
    <aside class="h-fit space-y-4 rounded-lg border border-slate-200 p-4 lg:sticky lg:top-4" aria-label="{{ __('storefront_sales.checkout.your_order') }}">
        <h2 class="text-lg font-semibold">{{ __('storefront_sales.checkout.your_order') }}</h2>
        @if ($summary !== null)
            <ul class="space-y-1 text-sm">
                @foreach ($summary->lines as $line)
                    <li class="flex justify-between gap-3"><span>{{ $line->product?->name }} × {{ $line->qty }}</span> <span class="flex-none">{{ \App\Support\Money::format($line->lineTotal) }}</span></li>
                @endforeach
            </ul>
        @endif
        <dl class="space-y-1 border-t border-slate-200 pt-3 text-sm" aria-live="polite">
            <div class="flex justify-between"><dt>{{ __('storefront_sales.cart.items_total') }}</dt><dd>{{ \App\Support\Money::format($summary !== null ? $summary->itemsTotal : 0) }}</dd></div>
            <div class="flex justify-between"><dt>{{ __('storefront_sales.checkout.delivery_price') }}</dt><dd data-delivery-price>{{ $deliveryPrice === 0 ? __('storefront_sales.checkout.free') : \App\Support\Money::format($deliveryPrice) }}</dd></div>
            <div class="flex justify-between border-t border-slate-200 pt-2 text-lg font-semibold"><dt>{{ __('storefront_sales.checkout.total') }}</dt><dd data-total>{{ \App\Support\Money::format($total) }}</dd></div>
        </dl>

        @if ($ageLevel)
            <x-storefront.age-confirm prefix="co" :level="$ageLevel" />
        @endif
        <x-storefront.consent prefix="co" :link="$consentLink" />
        <x-storefront.guard />

        <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="w-full rounded bg-primary px-4 py-3 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">
            <span wire:loading.remove wire:target="submit">{{ __('storefront_sales.checkout.submit') }}</span>
            <span wire:loading wire:target="submit">{{ __('storefront_sales.checkout.sending') }}</span>
        </button>
        <a href="{{ route('cart') }}" class="block text-center text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront_sales.checkout.back_to_cart') }}</a>
    </aside>
</form>
