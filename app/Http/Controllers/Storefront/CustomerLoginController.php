<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Seo\SeoMeta;
use Illuminate\Contracts\View\View;

/**
 * Route `customer.login` (CG-04, CG-06). Stage 8 has no customer accounts yet: this is a placeholder page that the
 * «войдите» links and the redirects of hidden sections point to. Stage 9 replaces it with the real sign-in.
 */
class CustomerLoginController extends Controller
{
    public function __invoke(): View
    {
        return view('storefront.account.login-stub', [
            'seo' => new SeoMeta(title: __('customer_groups.storefront.login_title'), robots: 'noindex,follow'),
        ]);
    }
}
