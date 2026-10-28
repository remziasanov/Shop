<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Services\Customers\CustomerAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sign-out of the customer (CA-32): the staff session of the same browser is left alone.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request, CustomerAuthService $auth): RedirectResponse
    {
        $auth->signOut($request);

        return redirect()->route('home');
    }
}
