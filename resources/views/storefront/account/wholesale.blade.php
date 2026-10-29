@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-xl py-6">
        @if ($customer)
            <p class="text-sm"><a href="{{ route('account') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('wholesale.page.back') }}</a></p>
        @endif
        <h1 class="mt-2 text-2xl font-bold md:text-3xl">{{ __('wholesale.page.title') }}</h1>
        <div class="mt-4">
            <x-account.errors />
        </div>

        @if (! $customer)
            {{-- A guest: an invitation to register or sign in; the form is for signed-in customers. --}}
            <section class="rounded-lg border border-slate-200 p-4" aria-labelledby="ws-guest-title">
                <h2 id="ws-guest-title" class="text-lg font-semibold">{{ __('wholesale.page.guest_title') }}</h2>
                <p class="mt-2 text-slate-600">{{ __('wholesale.page.guest_text') }}</p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <a href="{{ route('customer.register') }}" class="rounded bg-primary px-6 py-2 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('wholesale.page.register') }}</a>
                    <a href="{{ route('customer.login') }}" class="rounded border border-slate-300 px-4 py-2 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('wholesale.page.login') }}</a>
                </div>
            </section>
        @else
            @if ($latest)
                <section class="mb-6 rounded-lg border border-slate-200 p-4" aria-labelledby="ws-status-title">
                    <h2 id="ws-status-title" class="text-lg font-semibold">{{ __('wholesale.page.status_title') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('wholesale.page.sent_at', ['date' => $latest->created_at?->format('d.m.Y')]) }}</p>
                    @if ($latest->status === \App\Enums\WholesaleApplicationStatus::New)
                        <p class="mt-2">{{ __('wholesale.page.status_new') }}</p>
                    @elseif ($latest->status === \App\Enums\WholesaleApplicationStatus::Approved)
                        <p class="mt-2">{{ __('wholesale.page.status_approved', ['date' => $latest->decided_at?->format('d.m.Y'), 'group' => $latest->decidedGroup?->name ?? '']) }}</p>
                    @else
                        <p class="mt-2">{{ __('wholesale.page.status_rejected', ['date' => $latest->decided_at?->format('d.m.Y')]) }}</p>
                        @if (filled($latest->reject_reason))
                            <p class="mt-2 text-sm"><span class="font-medium">{{ __('wholesale.page.reject_reason') }}:</span> {{ $latest->reject_reason }}</p>
                        @endif
                        @if ($blockReason === null)
                            <p class="mt-2 text-sm text-slate-600">{{ __('wholesale.page.reapply') }}</p>
                        @endif
                    @endif
                </section>
            @endif

            @if ($blockReason !== null)
                @if (! $latest || $latest->status !== \App\Enums\WholesaleApplicationStatus::New)
                    <p class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">{{ $blockReason }}</p>
                @endif
            @else
                <p class="mb-4 text-slate-600">{{ __('wholesale.page.lead') }}</p>

                <form method="POST" action="{{ route('customer.wholesale.store') }}" class="relative space-y-4" novalidate>
                    @csrf
                    <x-account.field prefix="ws" name="company_name" :label="__('wholesale.fields.company_name')" :hint="__('wholesale.form.company_hint')" autocomplete="organization" maxlength="255" />
                    <x-account.field prefix="ws" name="inn" :label="__('wholesale.fields.inn')" :hint="__('wholesale.form.inn_hint')" inputmode="numeric" maxlength="12" autocomplete="off" />
                    <x-account.field prefix="ws" name="kpp" :label="__('wholesale.fields.kpp')" :hint="__('wholesale.form.kpp_hint')" :required="false" inputmode="text" maxlength="9" autocomplete="off" />
                    <x-account.field prefix="ws" name="contact_name" :label="__('wholesale.fields.contact_name')" :value="$customer->name" autocomplete="name" maxlength="150" />
                    <x-account.field prefix="ws" name="phone" type="tel" :label="__('wholesale.fields.phone')" :value="$customer->phone" autocomplete="tel" inputmode="tel"
                                     x-data="phoneMask" x-on:input="format($event)" />
                    <p class="text-sm text-slate-600">{{ __('wholesale.form.email_account', ['email' => $customer->email]) }}</p>

                    <div>
                        <label for="ws-comment" class="mb-1 block text-sm font-medium">{{ __('wholesale.fields.comment') }}</label>
                        <textarea id="ws-comment" name="comment" rows="4" maxlength="2000" aria-describedby="ws-comment-hint"
                                  @if ($errors->has('comment')) aria-invalid="true" @endif
                                  class="w-full rounded border border-slate-300 px-3 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary aria-invalid:border-red-600">{{ old('comment') }}</textarea>
                        <p id="ws-comment-hint" class="mt-1 text-xs text-slate-500">{{ __('wholesale.form.comment_hint') }}</p>
                    </div>

                    {{-- CG-20: consent, never checked by default; the time, the IP and the browser are stored with the application. --}}
                    <div class="flex items-start gap-2">
                        <input id="ws-consent" name="consent" type="checkbox" value="1" required aria-required="true" @checked(old('consent'))
                               class="mt-1 h-4 w-4 flex-none rounded border-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                               @if ($errors->has('consent')) aria-invalid="true" @endif>
                        <label for="ws-consent" class="text-sm">
                            {{ $consentLink['text'] }} <span class="text-red-700" aria-hidden="true">*</span>
                            @if (! empty($consentLink['url']))
                                <a href="{{ $consentLink['url'] }}" target="_blank" rel="noopener" class="whitespace-nowrap text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront_sales.checkout.consent_link') }}</a>
                            @endif
                        </label>
                    </div>

                    {{-- Spam protection (CG-24): the honeypot is hidden from people; the token proves how long the form was open. --}}
                    <div class="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
                        <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                    </div>
                    <input type="hidden" name="form_token" value="{{ $formToken }}">

                    <x-account.button>{{ __('wholesale.form.submit') }}</x-account.button>
                </form>
            @endif
        @endif
    </div>
@endsection
