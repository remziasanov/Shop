<div x-data="{ open: @js((bool) ($open && $product !== null && $actions?->canInquire)) }">
    @if ($product !== null && $actions !== null && $actions->canInquire && $mustSignIn)
        <a href="{{ route('customer.login') }}" data-action="inquiry-login"
           class="inline-block rounded border border-primary px-6 py-3 font-medium text-primary hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('account.actions.login_to_inquire') }}</a>
    @elseif ($product !== null && $actions !== null && $actions->canInquire)
        <button type="button" @click="open = true" aria-haspopup="dialog" data-action="inquiry"
                class="rounded border border-primary px-6 py-3 font-medium text-primary hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('storefront.actions.inquiry') }}</button>

        <x-storefront.modal id="inquiry-{{ $productId }}" :title="__('storefront_sales.inquiry.title')">
            <form wire:submit="submit" class="space-y-4" novalidate>
                <p class="text-sm text-slate-600">{{ __('storefront_sales.inquiry.text') }}</p>
                <x-storefront.form-error />

                <x-storefront.field prefix="iq" name="name" :label="__('storefront_sales.fields.name')" required autocomplete="name" maxlength="150" />
                <x-storefront.field prefix="iq" name="phone" type="tel" :label="__('storefront_sales.fields.phone')" required autocomplete="tel" inputmode="tel"
                                    :placeholder="__('storefront_sales.checkout.phone_placeholder')" x-data="phoneMask" x-on:input="format($event)" />
                <x-storefront.field prefix="iq" name="email" type="email" :label="__('storefront_sales.fields.email')" autocomplete="email" maxlength="190" />
                <x-storefront.field prefix="iq" name="qty" type="number" :label="__('storefront_sales.fields.qty')" min="1" />
                <x-storefront.field prefix="iq" name="comment" type="textarea" :label="__('storefront_sales.fields.comment')" maxlength="2000" />
                <x-storefront.consent prefix="iq" :link="$consentLink" />
                <x-storefront.guard />

                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" @click="open = false" class="rounded border border-slate-300 px-4 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront_sales.modal.cancel') }}</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="rounded bg-primary px-6 py-2 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ __('storefront_sales.inquiry.submit') }}</button>
                </div>
            </form>
        </x-storefront.modal>
    @endif
</div>
