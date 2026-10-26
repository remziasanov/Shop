<div x-data="{ open: @js((bool) ($open && $product !== null && $actions?->canReserve)) }">
    @if ($product !== null && $actions !== null && $actions->canReserve)
        <button type="button" @click="open = true" aria-haspopup="dialog" data-action="reservation"
                class="rounded border border-primary px-6 py-3 font-medium text-primary hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('storefront.actions.reservation') }}</button>

        <x-storefront.modal id="reservation-{{ $productId }}" :title="__('storefront_sales.reservation.title')">
            <form wire:submit="submit" class="space-y-4" novalidate>
                <p class="text-sm text-slate-600">{{ __('storefront_sales.reservation.text', ['date' => $reservedUntil->format('d.m.Y H:i')]) }}</p>
                <x-storefront.form-error />

                <x-storefront.field prefix="rs" name="qty" type="number" :label="__('storefront_sales.fields.qty')" required min="1" :max="$actions->maxReserveQty"
                                    :hint="$actions->maxReserveQty !== null ? __('storefront_sales.reservation.qty_max', ['max' => $actions->maxReserveQty]) : null" />
                <x-storefront.field prefix="rs" name="name" :label="__('storefront_sales.fields.name')" required autocomplete="name" maxlength="150" />
                <x-storefront.field prefix="rs" name="phone" type="tel" :label="__('storefront_sales.fields.phone')" required autocomplete="tel" inputmode="tel"
                                    :placeholder="__('storefront_sales.checkout.phone_placeholder')" x-data="phoneMask" x-on:input="format($event)" />
                <x-storefront.field prefix="rs" name="email" type="email" :label="__('storefront_sales.fields.email')" autocomplete="email" maxlength="190" />
                <x-storefront.field prefix="rs" name="desiredDate" type="date" :label="__('storefront_sales.reservation.desired_date')" :min="now()->format('Y-m-d')" :max="$reservedUntil->format('Y-m-d')"
                                    :hint="__('storefront_sales.reservation.desired_hint', ['date' => $reservedUntil->format('d.m.Y')])" />
                <x-storefront.field prefix="rs" name="comment" type="textarea" :label="__('storefront_sales.fields.comment')" maxlength="2000" />
                <x-storefront.consent prefix="rs" :link="$consentLink" />
                <x-storefront.guard />

                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" @click="open = false" class="rounded border border-slate-300 px-4 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront_sales.modal.cancel') }}</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="rounded bg-primary px-6 py-2 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ __('storefront_sales.reservation.submit') }}</button>
                </div>
            </form>
        </x-storefront.modal>
    @endif
</div>
