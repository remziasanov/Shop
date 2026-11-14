@extends('storefront.layouts.app')

@section('title', __('telegram_miniapp.about.title'))

@section('content')
    {{-- TM-06: the footer of the site, as a page of the Mini App: shop, pages, contacts, requisites. --}}
    <div class="mx-auto w-full max-w-3xl py-2 text-sm text-ink-soft">
        <h1 class="text-2xl font-bold text-ink">{{ __('telegram_miniapp.about.title') }}</h1>

        <section class="mt-4">
            <p class="font-semibold text-ink">{{ $shopName }}</p>
            @if ($shopFooterText)
                <p class="mt-2 whitespace-pre-line">{{ $shopFooterText }}</p>
            @endif
        </section>

        @if ($footerMenu !== [])
            <nav class="mt-6" aria-labelledby="about-menu">
                <h2 id="about-menu" class="font-semibold text-ink">{{ __('storefront.footer.menu') }}</h2>
                <ul class="shop-touch-list mt-2 space-y-1">
                    @foreach ($footerMenu as $item)
                        <li><a href="{{ $item['url'] }}" class="text-primary hover:underline">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif

        <section class="mt-6" aria-labelledby="about-contacts">
            <h2 id="about-contacts" class="font-semibold text-ink">{{ __('storefront.footer.contacts') }}</h2>
            <ul class="shop-touch-list mt-2 space-y-1">
                @foreach ($shopPhones as $phone)
                    <li><a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}" class="text-primary">{{ $phone }}</a></li>
                @endforeach
                @if ($shopEmail)
                    <li><a href="mailto:{{ $shopEmail }}" class="text-primary">{{ $shopEmail }}</a></li>
                @endif
                @if ($shopAddress)
                    <li>{{ $shopAddress }}</li>
                @endif
            </ul>
            @if ($whatsappUrl)
                <p class="mt-3"><a href="{{ $whatsappUrl }}" class="inline-flex min-h-11 items-center rounded-btn border border-line-strong bg-surface px-4 font-medium" data-contact="whatsapp">{{ __('mobile.contacts.whatsapp') }}</a></p>
            @endif
            @if ($shopWorkingHours)
                <h3 class="mt-4 font-semibold text-ink">{{ __('storefront.footer.working_hours') }}</h3>
                <p class="mt-1 whitespace-pre-line">{{ $shopWorkingHours }}</p>
            @endif
        </section>

        @if ($sellerDetails || $footerPrivacyLink || $footerConsentLink)
            <section class="mt-6" aria-labelledby="about-legal">
                <h2 id="about-legal" class="font-semibold text-ink">{{ __('storefront.footer.requisites') }}</h2>
                @if ($sellerDetails)
                    <p class="mt-2 whitespace-pre-line">{{ $sellerDetails }}</p>
                @endif
                @if ($footerPrivacyLink || $footerConsentLink)
                    <ul class="shop-touch-list mt-3 space-y-1">
                        @foreach (array_filter([$footerPrivacyLink, $footerConsentLink]) as $legalLink)
                            <li><a href="{{ $legalLink['url'] }}" class="text-primary hover:underline">{{ $legalLink['label'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    </div>
@endsection
