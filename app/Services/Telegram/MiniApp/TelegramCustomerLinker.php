<?php

declare(strict_types=1);

namespace App\Services\Telegram\MiniApp;

use App\Enums\CustomerNotificationType;
use App\Models\Customer;
use App\Models\TelegramAccount;
use App\Services\Customers\CustomerNotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Links a Telegram account to a customer and removes the link (TM-04, D-102). One Telegram belongs to at most one
 * customer and one customer to at most one Telegram (unique columns), so linking moves the previous owner out first.
 * The caller proves the owner of the new account (the e-mail code) before calling {@see link()}.
 */
final class TelegramCustomerLinker
{
    public function __construct(private readonly CustomerNotificationService $notifications) {}

    /**
     * Binds the Telegram to the customer and remembers `auth_version` for the automatic sign-in. Whoever owned this
     * Telegram before, and whichever Telegram the customer had before, are detached; the detached customers get the
     * cabinet notice «Telegram отключён от аккаунта».
     */
    public function link(TelegramAccount $account, Customer $customer): void
    {
        $detached = DB::transaction(function () use ($account, $customer): array {
            $detached = [];

            // The Telegram was linked to another customer: that link ends only now, after the new account's code.
            if ($account->customer_id !== null && $account->customer_id !== $customer->id) {
                $detached[] = $account->customer_id;
            }

            // The customer had another Telegram: it is freed (customer_id is unique).
            $other = TelegramAccount::query()->where('customer_id', $customer->id)->whereKeyNot($account->id)->first();

            if ($other !== null) {
                $other->forceFill(['customer_id' => null, 'customer_auth_version' => null])->save();
                $detached[] = $customer->id;
            }

            $account->forceFill([
                'customer_id' => $customer->id,
                'customer_auth_version' => $customer->auth_version,
            ])->save();

            return $detached;
        });

        foreach (array_unique($detached) as $customerId) {
            $this->notifyDetached(Customer::query()->find($customerId));
        }
    }

    /**
     * «Выйти» in the Mini App and «Отключить Telegram» in the cabinet: the Mini App no longer signs in on its own.
     */
    public function unlink(TelegramAccount $account, bool $notify = false): void
    {
        $customerId = $account->customer_id;

        if ($customerId === null) {
            return;
        }

        $account->forceFill(['customer_id' => null, 'customer_auth_version' => null])->save();

        if ($notify) {
            $this->notifyDetached(Customer::query()->find($customerId));
        }
    }

    private function notifyDetached(?Customer $customer): void
    {
        if ($customer === null) {
            return;
        }

        $this->notifications->notify(
            $customer,
            CustomerNotificationType::TelegramUnlinked,
            __('telegram_miniapp.unlinked_title'),
            __('telegram_miniapp.unlinked_body'),
            route('account', [], false),
        );
    }
}
