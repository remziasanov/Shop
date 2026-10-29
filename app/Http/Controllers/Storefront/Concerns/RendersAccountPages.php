<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Concerns;

use App\Exceptions\CustomerAuthException;
use App\Models\Customer;
use App\Services\Content\MenuService;
use App\Services\Seo\SeoMeta;
use App\Settings\LegalSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Helpers of the customer-account controllers: a page view with a not-indexed SEO block and the way a failed action is
 * shown (the message next to the field it belongs to, the typed values kept except passwords and codes).
 */
trait RendersAccountPages
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function page(string $view, string $title, array $data = []): View
    {
        return view('storefront.account.'.$view, $data + [
            'pageTitle' => $title,
            'seo' => new SeoMeta(title: $title, robots: 'noindex,nofollow'),
        ]);
    }

    /**
     * The signed-in customer of the cabinet (the routes are behind `customer.auth`).
     */
    protected function currentCustomer(Request $request): Customer
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof Customer, 403);

        return $customer;
    }

    protected function failure(Request $request, CustomerAuthException $e): RedirectResponse
    {
        return back()
            ->withInput($request->except(['password', 'password_confirmation', 'current_password', 'code']))
            ->withErrors([$e->field => $e->getMessage()]);
    }

    /**
     * Consent text and link of the registration form (CA-02): the same legal settings as the checkout forms.
     *
     * @return array{text: string, url: string|null}
     */
    protected function consentLink(): array
    {
        return [
            'text' => app(LegalSettings::class)->consent_text,
            'url' => app(MenuService::class)->consentLink()['url'] ?? null,
        ];
    }
}
