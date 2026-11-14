<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use App\Services\Customers\CustomerAuthService;
use App\Services\Telegram\MiniApp\TelegramCustomerLinker;
use App\Support\TelegramMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sign-out of the customer (CA-32): the staff session of the same browser is left alone.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request, CustomerAuthService $auth, TelegramCustomerLinker $linker): RedirectResponse
    {
        // TM-04 (г): in the Mini App the sign-out also unlinks the Telegram, so that it does not sign in again at once.
        $accountId = TelegramMode::accountId();
        $account = $accountId !== null ? TelegramAccount::query()->find($accountId) : null;

        $auth->signOut($request);

        if ($account !== null) {
            $linker->unlink($account);
        }

        return redirect()->route('home');
    }
}
