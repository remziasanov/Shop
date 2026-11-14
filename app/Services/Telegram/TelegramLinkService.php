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
        return $this->link(TelegramLinkPurpose::Customer, now()->addMinutes(self::CUSTOMER_TTL_MINUTES), customerId: $customer->id);
    }

    /** `s_` link for the staff profile (TS-02). */
    public function staffLink(User $user): ?string
    {
        return $this->link(TelegramLinkPurpose::Staff, now()->addMinutes(self::STAFF_TTL_MINUTES), userId: $user->id);
    }

    /**
     * TG-08: `o_` link for all requests of one checkout; null unless the bot is connected and customer notifications are on.
     *
     * @param  iterable<Order>  $orders
     */
    public function orderLink(iterable $orders): ?string
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

        return $this->link(TelegramLinkPurpose::Order, now()->addDays(self::ORDER_TTL_DAYS), orderIds: $ids);
    }

    /**
     * @param  list<int>|null  $orderIds
     */
    private function link(TelegramLinkPurpose $purpose, \DateTimeInterface $expiresAt, ?int $customerId = null, ?int $userId = null, ?array $orderIds = null): ?string
    {
        if (! $this->setup->isConnected()) {
            return null;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        TelegramLinkToken::query()->create([
            'token_hash' => hash('sha256', $token),
            'purpose' => $purpose,
            'customer_id' => $customerId,
            'user_id' => $userId,
            'order_ids' => $orderIds,
            'expires_at' => $expiresAt,
        ]);

        return $this->setup->startLink($purpose->prefix().$token);
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
     * Links a Telegram to a customer: one customer, one Telegram (a previous Telegram of the customer is released),
     * remembers `customers.auth_version` (TM-04), writes the cabinet note and mails the owner of the account (TG-07).
     * The caller has already confirmed the customer: with a button in the bot (a link token, which is then spent) or
     * with the e-mail code in the Mini App (no token). Returns false when this Telegram belongs to another customer or
     * the token was spent meanwhile; nothing changes then.
     */
    public function bindCustomer(TelegramAccount $account, Customer $customer, ?TelegramLinkToken $token = null): bool
    {
        $linked = DB::transaction(function () use ($account, $token, $customer): bool {
            $fresh = $token !== null ? TelegramLinkToken::query()->lockForUpdate()->whereKey($token->id)->first() : null;
            $account = TelegramAccount::query()->lockForUpdate()->findOrFail($account->id);

            if ($token !== null && ($fresh === null || $fresh->used_at !== null)) {
                return false;
            }

            if ($account->customer_id !== null && $account->customer_id !== $customer->id) {
                return false;
            }

            TelegramAccount::query()->where('customer_id', $customer->id)->where('id', '!=', $account->id)
                ->update(['customer_id' => null, 'customer_auth_version' => null]);

            $account->customer_id = $customer->id;
            $account->customer_auth_version = $customer->auth_version;
            $account->notify_enabled = true;
            $account->save();

            if ($fresh !== null) {
                $fresh->used_at = now();
                $fresh->save();
            }

            return true;
        });

        if (! $linked) {
            return false;
        }

        $label = $this->label($account);

        try {
            $this->cabinet->notify(
                $customer,
                CustomerNotificationType::TelegramLinked,
                __('telegram.link.cabinet_title'),
                __('telegram.link.cabinet_body', ['telegram' => $label]),
                route('customer.notifications', [], false),
            );

            if (filled($customer->email)) {
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

    public function unlinkCustomer(Customer $customer): void
    {
        TelegramAccount::query()->where('customer_id', $customer->id)->update(['customer_id' => null, 'customer_auth_version' => null]);
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
