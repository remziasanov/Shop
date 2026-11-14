<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\AuditAction;
use App\Enums\CustomerNotificationType;
use App\Enums\TelegramLinkPurpose;
use App\Mail\TelegramLinkedMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Services\Customers\CustomerNotificationService;
use App\Services\Users\AuditLogger;
use App\Settings\TelegramSettings;
use App\Support\PiiMask;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One-time deep links into the bot and the linking itself (TG-07, TG-08, D-102). Only the SHA-256 of a token is
 * stored. Linking a customer or a staff user never happens on the link alone: the bot asks (masked e-mail / staff
 * name) and links on the «Подключить» button, then tells the account owner (anti-hijack).
 */
class TelegramLinkService
{
    public const CUSTOMER_TTL_MINUTES = 15;

    public const STAFF_TTL_MINUTES = 15;

    public const ORDER_TTL_DAYS = 7;

    /** Callback data prefixes of the confirmation buttons (≤ 64 bytes: prefix, token id, 8 hash characters). */
    /** Session key prefix of the order link of the thanks page (one token per order set). */
    public const ORDER_LINK_SESSION_PREFIX = 'telegram_order_link_';

    public const CALLBACK_CONFIRM = 'lc';

    public const CALLBACK_DECLINE = 'ln';

    public function __construct(
        private readonly TelegramSettings $settings,
        private readonly TelegramBotSetup $setup,
        private readonly TelegramMessages $messages,
        private readonly CustomerNotificationService $cabinet,
        private readonly AuditLogger $audit,
    ) {}

    // ---- issuing links (site side) ---------------------------------------------------------------------------

    /** `c_` link for the cabinet button; null while the bot is not connected. */
    public function customerLink(Customer $customer): ?string
    {
        return $this->link(TelegramLinkPurpose::Customer, now()->addMinutes(self::CUSTOMER_TTL_MINUTES), customerId: $customer->id)['link'] ?? null;
    }

    /** `s_` link for the staff profile (TS-02). */
    public function staffLink(User $user): ?string
    {
        return $this->link(TelegramLinkPurpose::Staff, now()->addMinutes(self::STAFF_TTL_MINUTES), userId: $user->id)['link'] ?? null;
    }

    /**
     * TG-08: `o_` link for all requests of one checkout; null unless the bot is connected and customer notifications are on.
     * With a `$session` (the thanks page) the link is issued once per order set: a refresh or a link-preview bot reuses
     * the unexpired, unused token instead of inserting a new row (the plain link is kept in the session only).
     *
     * @param  iterable<Order>  $orders
     */
    public function orderLink(iterable $orders, ?Session $session = null): ?string
    {
        if (! $this->settings->customer_notifications_enabled) {
            return null;
        }

        $ids = [];

        foreach ($orders as $order) {
            $ids[] = $order->id;
        }

        if ($ids === []) {
            return null;
        }

        sort($ids);
        $sessionKey = self::ORDER_LINK_SESSION_PREFIX.hash('sha256', implode(',', $ids));
        $stored = $session?->get($sessionKey);

        if ($session !== null && is_array($stored) && is_string($stored['link'] ?? null) && is_string($stored['hash'] ?? null)
            && TelegramLinkToken::query()->usable()->where('token_hash', $stored['hash'])->exists()) {
            return $stored['link'];
        }

        $issued = $this->link(TelegramLinkPurpose::Order, now()->addDays(self::ORDER_TTL_DAYS), orderIds: $ids);

        if ($issued === null) {
            return null;
        }

        $session?->put($sessionKey, $issued);

        return $issued['link'];
    }

    /**
     * @param  list<int>|null  $orderIds
     * @return array{link: string, hash: string}|null
     */
    private function link(TelegramLinkPurpose $purpose, \DateTimeInterface $expiresAt, ?int $customerId = null, ?int $userId = null, ?array $orderIds = null): ?array
    {
        if (! $this->setup->isConnected()) {
            return null;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);

        TelegramLinkToken::query()->create([
            'token_hash' => $hash,
            'purpose' => $purpose,
            'customer_id' => $customerId,
            'user_id' => $userId,
            'order_ids' => $orderIds,
            'expires_at' => $expiresAt,
        ]);

        $link = $this->setup->startLink($purpose->prefix().$token);

        return $link === null ? null : ['link' => $link, 'hash' => $hash];
    }

    // ---- /start with a parameter ----------------------------------------------------------------------------

    /**
     * Handles `/start {param}`. Returns the Bot API reply, or null when the parameter is not a link token (the caller then
     * shows the normal welcome).
     *
     * @return array<string, mixed>|null
     */
    public function handleStart(TelegramAccount $account, string $param): ?array
    {
        if (! preg_match('/^([cso])_([A-Za-z0-9_-]{20,60})$/', $param, $m)) {
            return null;
        }

        $purpose = match ($m[1]) {
            'c' => TelegramLinkPurpose::Customer,
            's' => TelegramLinkPurpose::Staff,
            default => TelegramLinkPurpose::Order,
        };

        $token = TelegramLinkToken::query()
            ->usable()
            ->where('token_hash', hash('sha256', $m[2]))
            ->where('purpose', $purpose->value)
            ->first();

        if ($token === null) {
            return $this->messages->reply($account, __('telegram.link.expired'));
        }

        return match ($purpose) {
            TelegramLinkPurpose::Order => $this->followOrders($account, $token),
            TelegramLinkPurpose::Customer => $this->askCustomer($account, $token),
            TelegramLinkPurpose::Staff => $this->askStaff($account, $token),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function askCustomer(TelegramAccount $account, TelegramLinkToken $token): array
    {
        $customer = $token->customer;

        if ($customer === null || $customer->anonymized_at !== null) {
            return $this->messages->reply($account, __('telegram.link.expired'));
        }

        if ($account->customer_id !== null && $account->customer_id !== $customer->id) {
            return $this->messages->reply($account, __('telegram.link.already_linked_customer'));
        }

        if ($account->customer_id === $customer->id) {
            $this->markUsed($token);

            return $this->messages->reply($account, __('telegram.link.already_yours'), $this->messages->openShopMarkup());
        }

        return $this->messages->reply($account, __('telegram.link.confirm_customer', ['email' => PiiMask::email($customer->email)]), $this->confirmMarkup($token));
    }

    /**
     * @return array<string, mixed>
     */
    private function askStaff(TelegramAccount $account, TelegramLinkToken $token): array
    {
        $user = $token->user;

        if ($user === null || ! $user->is_active) {
            return $this->messages->reply($account, __('telegram.link.expired'));
        }

        if ($account->user_id !== null && $account->user_id !== $user->id) {
            return $this->messages->reply($account, __('telegram.link.already_linked_staff'));
        }

        if ($account->user_id === $user->id) {
            $this->markUsed($token);

            return $this->messages->reply($account, __('telegram.link.already_yours'));
        }

        return $this->messages->reply($account, __('telegram.link.confirm_staff', ['name' => $user->name]), $this->confirmMarkup($token));
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmMarkup(TelegramLinkToken $token): array
    {
        $suffix = ':'.$token->id.':'.substr($token->token_hash, 0, 8);

        return ['inline_keyboard' => [[
            ['text' => __('telegram.link.button_connect'), 'callback_data' => self::CALLBACK_CONFIRM.$suffix],
            ['text' => __('telegram.link.button_decline'), 'callback_data' => self::CALLBACK_DECLINE.$suffix],
        ]]];
    }

    /**
     * TG-08: the guest follows the requests of one checkout.
     *
     * @return array<string, mixed>
     */
    private function followOrders(TelegramAccount $account, TelegramLinkToken $token): array
    {
        if (! $this->markUsed($token)) {
            return $this->messages->reply($account, __('telegram.link.expired'));
        }

        $orders = Order::query()->whereIn('id', $token->order_ids ?? [])->orderBy('id')->get();

        foreach ($orders as $order) {
            if ($order->telegram_account_id === null || $order->telegram_account_id === $account->id) {
                $order->telegram_account_id = $account->id;
                $order->save();
            }
        }

        if ($orders->isEmpty()) {
            return $this->messages->reply($account, __('telegram.link.expired'));
        }

        $account->notify_enabled = true;
        $account->save();

        return $this->messages->reply(
            $account,
            __('telegram.link.orders_followed')."\n\n".$this->messages->orderLines($orders),
            $this->messages->openShopMarkup(),
        );
    }

    // ---- confirmation buttons -------------------------------------------------------------------------------

    /**
     * Handles `lc:{id}:{hash8}` / `ln:{id}:{hash8}`. Returns the text to show in place of the question, with an optional
     * keyboard, or null when the callback data is not valid for this user.
     *
     * @return array{text: string, markup: array<string, mixed>|null, toast: string}|null
     */
    public function answerConfirmation(TelegramAccount $account, string $action, int $tokenId, string $hash8): ?array
    {
        $token = TelegramLinkToken::query()->usable()->whereKey($tokenId)->first();

        if ($token === null || ! hash_equals(substr($token->token_hash, 0, 8), $hash8)
            || ! in_array($token->purpose, [TelegramLinkPurpose::Customer, TelegramLinkPurpose::Staff], true)) {
            return ['text' => __('telegram.link.expired'), 'markup' => null, 'toast' => __('telegram.link.expired_toast')];
        }

        if ($action === self::CALLBACK_DECLINE) {
            // «Это не я»: the link is burnt so that a planted link cannot be pressed later.
            $this->markUsed($token);

            return ['text' => __('telegram.link.declined'), 'markup' => null, 'toast' => __('telegram.link.declined_toast')];
        }

        try {
            $outcome = $token->purpose === TelegramLinkPurpose::Customer
                ? $this->linkCustomer($account, $token)
                : $this->linkStaff($account, $token);
        } catch (Throwable $e) {
            report($e);

            return ['text' => __('telegram.link.failed'), 'markup' => null, 'toast' => __('telegram.link.failed_toast')];
        }

        return $outcome;
    }

    /**
     * @return array{text: string, markup: array<string, mixed>|null, toast: string}
     */
    private function linkCustomer(TelegramAccount $account, TelegramLinkToken $token): array
    {
        $customer = $token->customer;

        if ($customer === null || $customer->anonymized_at !== null) {
            return ['text' => __('telegram.link.expired'), 'markup' => null, 'toast' => __('telegram.link.expired_toast')];
        }

        if (! $this->bindCustomer($account, $customer, $token)) {
            return ['text' => __('telegram.link.already_linked_customer'), 'markup' => null, 'toast' => __('telegram.link.expired_toast')];
        }

        return ['text' => __('telegram.link.done_customer'), 'markup' => $this->messages->openShopMarkup(), 'toast' => __('telegram.link.done_toast')];
    }

    /**
     * The one place that links a Telegram to a customer (TG-07, TM-04). One customer, one Telegram (unique columns): the
     * previous Telegram of the customer is released. Remembers `customers.auth_version` (TM-04), turns the status
     * messages on, writes the cabinet note «Telegram подключён» and (by default) mails the owner of the account (TG-07).
     * Rows are locked inside one transaction (the account, the customer, the other Telegram of the customer), and a
     * unique-key race that still slips through ends as «false», never as an exception.
     *
     * The caller has already confirmed the customer: with a button in the bot (a link token, which is then spent) or
     * with the e-mail code in the Mini App (no token). Policies:
     * - bot (`$replaceExisting = false`): a Telegram that belongs to another customer is refused (TG-07, anti-hijack);
     * - Mini App (`$replaceExisting = true`, `$notifyByEmail = false`): the e-mail code just proved the ownership of the
     *   new account, so the link moves (TM-04 б), the previous owner gets the cabinet notice «Telegram отключён от
     *   аккаунта», and no e-mail is sent to the person who has just signed in with an e-mail code.
     *
     * Returns false when the Telegram belongs to another customer (and may not be replaced), the token was spent
     * meanwhile or a concurrent link won; nothing changes then.
     */
    public function bindCustomer(
        TelegramAccount $account,
        Customer $customer,
        ?TelegramLinkToken $token = null,
        bool $replaceExisting = false,
        bool $notifyByEmail = true,
    ): bool {
        $detachedOwner = null;

        try {
            $linked = DB::transaction(function () use ($account, $token, $customer, $replaceExisting, &$detachedOwner): bool {
                $fresh = $token !== null ? TelegramLinkToken::query()->lockForUpdate()->whereKey($token->id)->first() : null;
                $locked = TelegramAccount::query()->lockForUpdate()->find($account->id);
                // Serialises concurrent links of the same customer.
                Customer::query()->lockForUpdate()->whereKey($customer->id)->first(['id']);

                if ($locked === null || ($token !== null && ($fresh === null || $fresh->used_at !== null))) {
                    return false;
                }

                if ($locked->customer_id !== null && $locked->customer_id !== $customer->id) {
                    if (! $replaceExisting) {
                        return false;
                    }

                    $detachedOwner = $locked->customer_id;
                }

                // customer_id is unique: the other Telegram of the customer is released first.
                TelegramAccount::query()->where('customer_id', $customer->id)->where('id', '!=', $locked->id)->lockForUpdate()->get(['id']);
                TelegramAccount::query()->where('customer_id', $customer->id)->where('id', '!=', $locked->id)
                    ->update(['customer_id' => null, 'customer_auth_version' => null]);

                $locked->forceFill([
                    'customer_id' => $customer->id,
                    'customer_auth_version' => $customer->auth_version,
                    'notify_enabled' => true,
                ])->save();

                if ($fresh !== null) {
                    $fresh->used_at = now();
                    $fresh->save();
                }

                $account->setRawAttributes($locked->getAttributes(), true);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if (! $linked) {
            return false;
        }

        $label = $this->label($account);

        try {
            if ($detachedOwner !== null) {
                $this->notifyDetached(Customer::query()->find($detachedOwner));
            }

            $this->cabinet->notify(
                $customer,
                CustomerNotificationType::TelegramLinked,
                __('telegram.link.cabinet_title'),
                __('telegram.link.cabinet_body', ['telegram' => $label]),
                route('customer.notifications', [], false),
            );

            if ($notifyByEmail && filled($customer->email)) {
                Mail::to($customer->email)->queue(new TelegramLinkedMail($label, false));
            }
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }

    /**
     * @return array{text: string, markup: array<string, mixed>|null, toast: string}
     */
    private function linkStaff(TelegramAccount $account, TelegramLinkToken $token): array
    {
        $user = $token->user;

        if ($user === null || ! $user->is_active) {
            return ['text' => __('telegram.link.expired'), 'markup' => null, 'toast' => __('telegram.link.expired_toast')];
        }

        $linked = DB::transaction(function () use ($account, $token, $user): bool {
            $fresh = TelegramLinkToken::query()->lockForUpdate()->whereKey($token->id)->first();
            $account = TelegramAccount::query()->lockForUpdate()->findOrFail($account->id);

            if ($fresh === null || $fresh->used_at !== null) {
                return false;
            }

            if ($account->user_id !== null && $account->user_id !== $user->id) {
                return false;
            }

            TelegramAccount::query()->where('user_id', $user->id)->where('id', '!=', $account->id)->update(['user_id' => null]);

            $account->user_id = $user->id;
            $account->save();
            $fresh->used_at = now();
            $fresh->save();

            return true;
        });

        if (! $linked) {
            return ['text' => __('telegram.link.already_linked_staff'), 'markup' => null, 'toast' => __('telegram.link.expired_toast')];
        }

        try {
            $this->audit->record(AuditAction::UserTelegramLinked, $user, $user, ['telegram_account_id' => $account->id]);

            if (filled($user->email)) {
                Mail::to($user->email)->queue(new TelegramLinkedMail($this->label($account), true));
            }
        } catch (Throwable $e) {
            report($e);
        }

        return ['text' => __('telegram.link.done_staff'), 'markup' => null, 'toast' => __('telegram.link.done_toast')];
    }

    // ---- disconnecting (cabinet / staff profile) -------------------------------------------------------------

    /** «Отключить Telegram» in the cabinet. The Mini App sessions of this Telegram end at their next request. */
    public function unlinkCustomer(Customer $customer): void
    {
        TelegramAccount::query()->where('customer_id', $customer->id)->update(['customer_id' => null, 'customer_auth_version' => null]);
    }

    /**
     * «Выйти» in the Mini App and the end of an outdated link: the Mini App no longer signs in on its own.
     */
    public function unlinkAccount(TelegramAccount $account, bool $notify = false): void
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

        $this->cabinet->notify(
            $customer,
            CustomerNotificationType::TelegramUnlinked,
            __('telegram_miniapp.unlinked_title'),
            __('telegram_miniapp.unlinked_body'),
            route('account', [], false),
        );
    }

    public function unlinkStaff(User $user): void
    {
        $updated = TelegramAccount::query()->where('user_id', $user->id)->update(['user_id' => null]);

        if ($updated > 0) {
            $this->audit->record(AuditAction::UserTelegramUnlinked, $user, $user);
        }
    }

    private function markUsed(TelegramLinkToken $token): bool
    {
        return TelegramLinkToken::query()->whereKey($token->id)->whereNull('used_at')->update(['used_at' => now()]) === 1;
    }

    /** «@username» or the first name, for the owner's e-mail and cabinet note. */
    private function label(TelegramAccount $account): string
    {
        return filled($account->username) ? '@'.$account->username : (string) ($account->first_name ?? __('telegram.link.no_username'));
    }
}
