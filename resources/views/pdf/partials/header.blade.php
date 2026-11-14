{{-- Shop header (CX-03): logo and requisites from the settings. $logo is a data URI or null. --}}
<table class="head">
    <tr>
        <td style="width: 45%;">
            @if ($logo)
                <img class="logo" src="{{ $logo }}" alt="">
            @else
                <strong style="font-size: 12pt;">{{ $general->shop_name }}</strong>
            @endif
        </td>
        <td class="right">
            @if ($logo)
                <strong>{{ $general->shop_name }}</strong><br>
            @endif
            @if ($legal->seller_details)
                <span class="muted">{!! nl2br(e($legal->seller_details)) !!}</span><br>
            @endif
            @if ($general->address)
                <span class="muted">{{ $general->address }}</span><br>
            @endif
            @if (array_filter((array) $general->phones) !== [])
                <span class="muted">{{ implode(', ', array_filter((array) $general->phones)) }}</span><br>
            @endif
            @if ($general->email)
                <span class="muted">{{ $general->email }}</span>
            @endif
        </td>
    </tr>
</table>
