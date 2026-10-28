<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Enums\AgeRestriction;
use App\Http\Controllers\Controller;
use App\Services\Age\AgeGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Age gate answers (TZ 16, AG-06, AG-08). The overlay is a plain HTML form, so both answers work without JavaScript.
 */
class AgeGateController extends Controller
{
    /**
     * «Да»: remember the confirmed level in the cookie and go back to the page the visitor came from.
     * A cookie that already holds a higher level is kept.
     */
    public function confirm(Request $request, AgeGate $gate): RedirectResponse
    {
        $data = $request->validate([
            'level' => ['required', 'string', Rule::in(array_keys(AgeRestriction::options()))],
            'return' => ['nullable', 'string'],
        ]);

        $level = AgeRestriction::from($data['level']);
        $current = $gate->confirmedAge($request);

        if ($current > $level->minAge()) {
            $level = AgeRestriction::fromAge($current) ?? $level;
        }

        return redirect()
            ->to($gate->safeReturnPath($data['return'] ?? null))
            ->withCookie($gate->cookie($level, $request->isSecure()));
    }

    /**
     * «Нет»: back to the home page with the polite message from the settings (the home page of the «site» mode
     * shows the overlay in its refusal state).
     */
    public function decline(Request $request, AgeGate $gate): RedirectResponse
    {
        $level = AgeRestriction::tryFrom((string) $request->query('level')) ?? $gate->siteLevel();

        return redirect()
            ->route('home')
            ->with(AgeGate::FLASH_DECLINED, $level->value);
    }
}
