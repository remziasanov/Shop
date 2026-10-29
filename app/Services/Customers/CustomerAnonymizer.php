<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\AuditAction;
use App\Events\CustomerAnonymizing;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerCompany;
use App\Models\CustomerNotification;
use App\Models\CustomerVerificationCode;
use App\Models\Order;
use App\Models\User;
use App\Services\Users\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Anonymization of a customer (CA-17, 152-FZ). Everything runs in one transaction and repeating it changes nothing.
 *
 *  - The customer: name «Удалённый покупатель», e-mail `deleted-{id}@anonymous.invalid`, no phone, a random password,
 *    blocked, `anonymized_at` set, every session ended. The row stays, so requests keep pointing at it.
 *  - Deleted: addresses, company, cabinet notifications, e-mail codes, carts. Features with personal data of their
 *    own (wholesale applications) erase it in a listener of {@see CustomerAnonymizing}, which fires first, inside
 *    the transaction.
 *  - Requests of the customer lose name, phone, e-mail, delivery address, comment, user agent and consent IP; items,
 *    amounts and statuses stay for the accounting.
 *  - The journal entry (RB-12) carries ids and the source only, no personal data.
 */
final class CustomerAnonymizer
{
    public const DELETED_NAME = 'Удалённый покупатель';

    public const EMAIL_DOMAIN = 'anonymous.invalid';

    /** Filler for the NOT NULL consent IP of a request. */
    public const NO_IP = '0.0.0.0';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  string  $source  `admin`, `system` (the grace period passed) or `self`; goes to the journal
     * @return bool false when the customer was anonymized before (nothing is done)
     */
    public function anonymize(Customer $customer, ?User $actor = null, string $source = 'system'): bool
    {
        return DB::transaction(function () use ($customer, $actor, $source): bool {
            /** @var Customer|null $locked */
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->anonymized_at !== null) {
                return false;
            }

            $oldEmail = $locked->email;

            // Before anything is wiped: listeners read the customer's data they have to erase.
            CustomerAnonymizing::dispatch($locked);

            Order::withTrashed()->where('customer_id', $locked->id)->update([
                'customer_name' => self::DELETED_NAME,
                'customer_phone' => '',
                'customer_email' => null,
                'delivery_address' => null,
                'customer_comment' => null,
                'user_agent' => null,
                'consent_ip' => self::NO_IP,
            ]);

            CustomerAddress::query()->where('customer_id', $locked->id)->delete();
            CustomerCompany::query()->where('customer_id', $locked->id)->delete();
            CustomerNotification::query()->where('customer_id', $locked->id)->delete();
            CustomerVerificationCode::query()->where('customer_id', $locked->id)->orWhere('email', $oldEmail)->delete();

            foreach (Cart::query()->where('customer_id', $locked->id)->pluck('id') as $cartId) {
                Cart::query()->whereKey($cartId)->delete();
            }

            $locked->forceFill([
                'name' => self::DELETED_NAME,
                'email' => 'deleted-'.$locked->id.'@'.self::EMAIL_DOMAIN,
                'phone' => null,
                'password' => Str::random(64),
                'remember_token' => Str::random(60),
                'is_blocked' => true,
                'blocked_reason' => null,
                'consent_ip' => null,
                'deletion_requested_at' => null,
                'anonymized_at' => now(),
            ])->save();
            $locked->increment('auth_version');

            $this->audit->record(AuditAction::CustomerAnonymized, $actor, $locked, ['source' => $source]);

            return true;
        });
    }
}
