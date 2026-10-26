<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Services\Content\MenuService;
use App\Settings\LegalSettings;
use Illuminate\Contracts\View\View;
use Throwable;

/**
 * Data of the cookie consent banner (SF-08): shown only when enabled and the `cookie_consent` cookie is absent.
 */
class CookieBannerComposer
{
    public const COOKIE = 'cookie_consent';

    public function __construct(private readonly LegalSettings $legal, private readonly MenuService $menus) {}

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

    /**
     * Policy link from the cached menus (no query per request); null while the page is not active.
     */
    private function policyUrl(): ?string
    {
        try {
            return $this->menus->all()['privacy']['url'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }
}
