<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Settings\LegalSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Data of the cookie consent banner (SF-08): shown only when enabled and the `cookie_consent` cookie is absent.
 */
class CookieBannerComposer
{
    public const COOKIE = 'cookie_consent';

    public function __construct(private readonly LegalSettings $legal) {}

    public function compose(View $view): void
    {
        $show = $this->legal->cookie_banner_enabled
            && trim($this->legal->cookie_banner_text) !== ''
            && request()->cookie(self::COOKIE) === null;

        $view->with([
            'cookieBannerShow' => $show,
            'cookieBannerText' => $show ? $this->legal->cookie_banner_text : '',
            'cookiePolicyUrl' => $show ? $this->policyUrl() : null,
        ]);
    }

    private function policyUrl(): ?string
    {
        $id = $this->legal->privacy_page_id;

        if ($id === null || ! Route::has('page.show')) {
            return null;
        }

        try {
            $slug = DB::table('pages')->where('id', $id)->where('is_active', true)->value('slug');
        } catch (Throwable) {
            return null;
        }

        return is_string($slug) && $slug !== '' ? route('page.show', [$slug]) : null;
    }
}
