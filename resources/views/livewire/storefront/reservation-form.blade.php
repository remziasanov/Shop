{{-- VR-14: for a card with variants the button exists whenever the mode has a reservation; the picker of the page (`current`) shows it for the variants that can be reserved, the server decides again on submit. --}}
@php($variantMode = $product !== null && $product->has_variants)
@php($canReserve = $actions !== null && ($variantMode ? $actions->hasReservationButton() : $actions->canReserve))
<div x-data="{ open: @js((bool) ($open && $product !== null && $canReserve)) }" @if ($variantMode) x-cloak x-show="current && current.can.reserve" x-effect="$wire.variantId = current ? current.id : 0" @endif>
    @if ($product !== null && $canReserve && $mustSignIn)
        <a href="{{ route('customer.login') }}" data-action="reservation-login"
           class="inline-block rounded-btn border border-primary px-6 py-3 font-medium text-primary hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('account.actions.login_to_reserve') }}</a>
    @elseif ($product !== null && $canReserve)
        @if ($viaCart)
            {{-- MR-03: the main button is «В резерв» (the cart); this link keeps the one-product form (SF-50). --}}
            <button type="button" @click="open = true" aria-haspopup="dialog" data-action="reservation"
                    class="self-center text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('storefront.actions.reservation_single') }}</button>
        @else
            <button type="button" @click="open = true" aria-haspopup="dialog" data-action="reservation"
                    class="rounded-btn border border-primary px-6 py-3 font-medium text-primary hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('storefront.actions.reservation') }}</button>
        @endif

        <x-storefront.modal id="reservation-{{ $productId }}" :title="__('storefront_sales.reservation.title')">
            <form wire:submit="submit" class="space-y-4" novalidate>
                <p class="text-sm text-ink-mid">{{ __('storefront_sales.reservation.text', ['date' => $reservedUntil->format('d.m.Y H:i')]) }}</p>
                @if ($variantMode)
                    <p class="text-sm font-medium" data-variant-label x-text="current ? current.label : ''"></p>
                @endif
                <x-storefront.form-error />

                <x-storefront.field prefix="rs" name="qty" type="number" :label="__('storefront_sales.fields.qty')" required min="1" :max="$variantMode ? null : $actions->maxReserveQty"
                                    :hint="! $variantMode && $actions->maxReserveQty !== null ? __('storefront_sales.reservation.qty_max', ['max' => $actions->maxReserveQty]) : null" />
                <x-storefront.field prefix="rs" name="name" :label="__('storefront_sales.fields.name')" required autocomplete="name" maxlength="150" />
                <x-storefront.field prefix="rs" name="phone" type="tel" :label="__('storefront_sales.fields.phone')" required autocomplete="tel" inputmode="tel"
                                    :placeholder="__('storefront_sales.checkout.phone_placeholder')" x-data="phoneMask" x-on:input="format($event)" />
                <x-storefront.field prefix="rs" name="email" type="email" :label="__('storefront_sales.fields.email')" autocomplete="email" maxlength="190" />
                <x-storefront.field prefix="rs" name="desiredDate" type="date" :label="__('storefront_sales.reservation.desired_date')" :min="now()->format('Y-m-d')" :max="$reservedUntil->format('Y-m-d')"
                                    :hint="__('storefront_sales.reservation.desired_hint', ['date' => $reservedUntil->format('d.m.Y')])" />
                <x-storefront.field prefix="rs" name="comment" type="textarea" :label="__('storefront_sales.fields.comment')" maxlength="2000" />
                @if ($ageLevel)
                    <x-storefront.age-confirm prefix="rs" :level="$ageLevel" />
                @endif
                <x-storefront.consent prefix="rs" :link="$consentLink" />
                <x-storefront.guard />

                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" @click="open = false" class="rounded-btn border border-line-strong px-4 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront_sales.modal.cancel') }}</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="rounded-btn bg-primary px-6 py-2 font-medium text-on-primary hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ __('storefront_sales.reservation.submit') }}</button>
                </div>
            </form>
        </x-storefront.modal>
    @endif
</div>
