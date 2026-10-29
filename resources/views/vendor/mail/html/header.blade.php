@props(['url'])
@php
    $shopName = \App\Support\ShopName::get();
    $logoUrl = null;

    try {
        $general = app(\App\Settings\GeneralSettings::class);

        if (filled($general->logo)) {
            $logoUrl = url(\Illuminate\Support\Facades\Storage::disk('public')->url($general->logo));
        }
    } catch (\Throwable) {
        // The header must never break the mail: fall back to the plain shop name.
    }
@endphp
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logoUrl)
<img src="{{ $logoUrl }}" alt="{{ $shopName }}" style="max-height: 60px; max-width: 240px; height: auto; width: auto;"><br>
@endif
{{ $shopName }}
</a>
</td>
</tr>
