<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\AuditAction;
use App\Enums\CustomerNotificationType;
use App\Enums\StorefrontSection;
use App\Enums\WholesaleApplicationStatus;
use App\Exceptions\WholesaleApplicationException;
use App\Jobs\SendWholesaleApplicationNotification;
use App\Mail\WholesaleDecisionMail;
use App\Models\Customer;
use App\Models\CustomerCompany;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Models\WholesaleApplication;
use App\Services\Notifications\JobFanOut;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Users\AuditLogger;
use App\Services\Users\PermissionService;
use App\Settings\NotificationSettings;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wholesale access applications (CG-20…CG-24): the customer applies, the manager approves with a chosen group or
 * rejects with a reason, an administrator can return a decided application to «new».
 *
 * Rules:
 *  - one pending application per customer, at most {@see MAX_PER_PERIOD} in {@see PERIOD_DAYS} days (CG-20);
 *  - a decision is made once; a repeat is refused (CG-22);
 *  - the decision, the group change and the copy of the requisites are one transaction; the customer's cabinet
 *    notification and e-mail (CG-23) and the shop notification (CG-21) are sent after it;
 *  - the application and every decision go to the action journal without personal data (CG-24, RB-12).
 */
final class WholesaleApplicationService
{
    public const MAX_PER_PERIOD = 3;

    public const PERIOD_DAYS = 30;

    public const COUNT_CACHE_KEY = 'wholesale:new-count';

    public const COUNT_TTL_SECONDS = 30;

    /** Most ids listed in a duplicate warning. */
    private const DUPLICATES_SHOWN = 5;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ customer side (CG-20)

    /**
     * Whether the customer may send a new application now.
     *
     * @throws WholesaleApplicationException with the reason in Russian
     */
    public function assertCanApply(Customer $customer): void
    {
        if (! $customer->hasVerifiedEmail()) {
            throw new WholesaleApplicationException(__('wholesale.errors.unverified'));
        }

        if (! $customer->canSignIn()) {
            throw new WholesaleApplicationException(__('wholesale.errors.blocked'));
        }

        $group = CustomerGroup::query()->find($customer->customer_group_id);

        if ($group !== null && $group->is_wholesale_default) {
            throw new WholesaleApplicationException(__('wholesale.errors.already_wholesale', ['group' => $group->name]));
        }

        if ($this->pendingOf($customer) !== null) {
            throw new WholesaleApplicationException(__('wholesale.errors.pending'));
        }

        if ($this->recentCount($customer) >= self::MAX_PER_PERIOD) {
            throw new WholesaleApplicationException(__('wholesale.errors.limit', ['count' => self::MAX_PER_PERIOD]));
        }
    }

    /**
     * @return string|null the reason the customer cannot apply, null when the form may be shown
     */
    public function applyBlockReason(Customer $customer): ?string
    {
        try {
            $this->assertCanApply($customer);
        } catch (WholesaleApplicationException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Stores a new application. The e-mail is the account's, the consent is recorded with the time, IP and agent.
     *
     * @param  array{company_name: string, inn: string, kpp?: string|null, contact_name: string, phone: string, comment?: string|null}  $data
     *
     * @throws WholesaleApplicationException
     */
    public function submit(Customer $customer, array $data, ?string $ip, ?string $userAgent): WholesaleApplication
    {
        $application = DB::transaction(function () use ($customer, $data, $ip, $userAgent): WholesaleApplication {
            // Serialises two submits of one customer: the limit checks and the insert are not interleaved.
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            $this->assertCanApply($customer);

            $application = new WholesaleApplication;
            $application->fill([
                'company_name' => $data['company_name'],
                'inn' => trim($data['inn']),
                'kpp' => $this->blankToNull($data['kpp'] ?? null),
                'contact_name' => $data['contact_name'],
                'phone' => $data['phone'],
                'email' => $customer->email,
                'comment' => $this->blankToNull($data['comment'] ?? null),
            ]);
            $application->forceFill([
                'customer_id' => $customer->id,
                'status' => WholesaleApplicationStatus::New->value,
                'consent_at' => now(),
                'consent_ip' => $ip,
                'consent_user_agent' => $userAgent === null ? null : Str::limit($userAgent, 500, ''),
            ])->save();

            // The journal keeps identifiers only; the customer's IP that AuditLogger takes from the request is dropped.
            $this->audit->record(AuditAction::WholesaleApplicationCreated, null, $application, [
                'customer_id' => $customer->id,
            ])->update(['ip' => null]);

            return $application;
        });

        $this->forgetCount();
        $this->notifyShop($application);

        return $application;
    }

    /**
     * The latest application of the customer, for the status page.
     */
    public function latestOf(Customer $customer): ?WholesaleApplication
    {
        return WholesaleApplication::query()->where('customer_id', $customer->id)->orderByDesc('id')->first();
    }

    public function pendingOf(Customer $customer): ?WholesaleApplication
    {
        return WholesaleApplication::query()
            ->where('customer_id', $customer->id)
            ->where('status', WholesaleApplicationStatus::New->value)
            ->first();
    }

    // ------------------------------------------------------------------ staff side (CG-22)

    /**
     * Approves the application: the customer moves to `$group`, the requisites are copied when the customer has none.
     *
     * @throws WholesaleApplicationException already decided, a group that cannot be granted, an anonymized customer
     * @throws AuthorizationException the actor lacks `wholesale_applications.decide`
     */
    public function approve(WholesaleApplication $application, CustomerGroup $group, User $actor): WholesaleApplication
    {
        $this->authorizeDecision($actor);

        if (! $group->exists || $group->isGuest() || ! $group->is_active) {
            throw new WholesaleApplicationException(__('wholesale.errors.group_invalid'));
        }

        $copied = false;

        $decided = DB::transaction(function () use ($application, $group, $actor, &$copied): WholesaleApplication {
            $locked = $this->lockPending($application);
            $customer = Customer::query()->whereKey($locked->customer_id)->lockForUpdate()->firstOrFail();

            if ($customer->anonymized_at !== null) {
                throw new WholesaleApplicationException(__('wholesale.errors.anonymized'));
            }

            $locked->forceFill([
                'status' => WholesaleApplicationStatus::Approved->value,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decided_group_id' => $group->id,
                'reject_reason' => null,
            ])->save();

            // CG-11: the group is the one thing that changes; carts are re-priced on the next load by the viewer's group.
            $customer->forceFill(['customer_group_id' => $group->id])->save();

            $copied = $this->copyRequisites($locked, $customer);

            $this->audit->record(AuditAction::WholesaleApplicationApproved, $actor, $locked, [
                'customer_id' => $customer->id,
                'group_id' => $group->id,
                'requisites_copied' => $copied,
            ]);

            return $locked;
        });

        $this->forgetCount();
        $this->notifyCustomer($decided, $group);

        return $decided;
    }

    /**
     * Rejects the application with a reason the customer will see.
     *
     * @throws WholesaleApplicationException already decided, an empty reason
     * @throws AuthorizationException
     */
    public function reject(WholesaleApplication $application, string $reason, User $actor): WholesaleApplication
    {
        $this->authorizeDecision($actor);

        $reason = trim($reason);

        if ($reason === '') {
            throw new WholesaleApplicationException(__('wholesale.errors.reason_required'));
        }

        $decided = DB::transaction(function () use ($application, $reason, $actor): WholesaleApplication {
            $locked = $this->lockPending($application);

            $locked->forceFill([
                'status' => WholesaleApplicationStatus::Rejected->value,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decided_group_id' => null,
                'reject_reason' => Str::limit($reason, 1000, ''),
            ])->save();

            $this->audit->record(AuditAction::WholesaleApplicationRejected, $actor, $locked, [
                'customer_id' => $locked->customer_id,
            ]);

            return $locked;
        });

        $this->forgetCount();
        $this->notifyCustomer($decided, null);

        return $decided;
    }

    /**
     * Returns a decided application to «new» (CG-22): Administrator only, with a comment. The customer's group and
     * requisites stay as they are; the decision fields are cleared.
     *
     * @throws WholesaleApplicationException not decided, an empty comment, another pending application of the customer
     * @throws AuthorizationException the actor is not an administrator
     */
    public function returnToNew(WholesaleApplication $application, string $comment, User $actor): WholesaleApplication
    {
        if (! $this->permissions->isAdministrator($actor)) {
            throw new AuthorizationException;
        }

        $comment = trim($comment);

        if ($comment === '') {
            throw new WholesaleApplicationException(__('wholesale.errors.comment_required'));
        }

        $returned = DB::transaction(function () use ($application, $comment, $actor): WholesaleApplication {
            /** @var WholesaleApplication $locked */
            $locked = WholesaleApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->isNew()) {
                throw new WholesaleApplicationException(__('wholesale.errors.not_decided'));
            }

            $other = WholesaleApplication::query()
                ->where('customer_id', $locked->customer_id)
                ->where('status', WholesaleApplicationStatus::New->value)
                ->whereKeyNot($locked->id)
                ->exists();

            if ($other) {
                throw new WholesaleApplicationException(__('wholesale.errors.pending'));
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => WholesaleApplicationStatus::New->value,
                'decided_by' => null,
                'decided_at' => null,
                'decided_group_id' => null,
                'reject_reason' => null,
                'return_comment' => Str::limit($comment, 1000, ''),
            ])->save();

            $this->audit->record(AuditAction::WholesaleApplicationReturned, $actor, $locked, [
                'customer_id' => $locked->customer_id,
                'from_status' => $previous->value,
            ]);

            return $locked;
        });

        $this->forgetCount();

        return $returned;
    }

    // ------------------------------------------------------------------ duplicates (CG-24)

    /**
     * Warnings for the manager: the same INN in an application or in the requisites of another customer. The decision
     * stays with the manager.
     *
     * @return list<string>
     */
    public function duplicateWarnings(WholesaleApplication $application): array
    {
        $inn = trim((string) $application->inn);

        if ($inn === '') {
            return [];
        }

        $applications = WholesaleApplication::query()
            ->where('inn', $inn)
            ->where('customer_id', '!=', $application->customer_id)
            ->orderBy('id')
            ->limit(self::DUPLICATES_SHOWN)
            ->pluck('customer_id', 'id');

        $companies = CustomerCompany::query()
            ->where('inn', $inn)
            ->where('customer_id', '!=', $application->customer_id)
            ->orderBy('customer_id')
            ->limit(self::DUPLICATES_SHOWN)
            ->pluck('customer_id');

        $warnings = [];

        foreach ($applications as $id => $customerId) {
            $warnings[] = __('wholesale.duplicate.application', ['id' => $id, 'customer' => $customerId]);
        }

        foreach ($companies as $customerId) {
            $warnings[] = __('wholesale.duplicate.customer', ['customer' => $customerId]);
        }

        return $warnings;
    }

    // ------------------------------------------------------------------ counters (CG-21)

    /**
     * Applications waiting for a decision: the badge of the menu item and the dashboard widget (cached 30 s).
     */
    public function pendingCount(): int
    {
        return (int) Cache::remember(self::COUNT_CACHE_KEY, self::COUNT_TTL_SECONDS, fn (): int => WholesaleApplication::query()->new()->count());
    }

    public function forgetCount(): void
    {
        Cache::forget(self::COUNT_CACHE_KEY);
    }

    // ------------------------------------------------------------------ anonymization (CA-17)

    /**
     * Erases the personal data of the customer's applications; the rows, statuses and decisions stay (CA-17).
     */
    public function anonymizeFor(Customer $customer): int
    {
        return WholesaleApplication::query()->where('customer_id', $customer->id)->update([
            'company_name' => null,
            'inn' => null,
            'kpp' => null,
            'contact_name' => null,
            'phone' => null,
            'email' => null,
            'comment' => null,
            'consent_ip' => null,
            'consent_user_agent' => null,
            'reject_reason' => null,
            'return_comment' => null,
        ]);
    }

    // ------------------------------------------------------------------ what the group offers (CG-23)

    /**
     * What an approved customer gets with the group, for the e-mail and the cabinet.
     *
     * @return list<string>
     */
    public function availableSummary(CustomerGroup $group): array
    {
        $lines = [__('wholesale.available.prices', ['group' => $group->name])];

        if ($group->allowsSection(StorefrontSection::PriceList)) {
            $lines[] = __('wholesale.available.price_list');
        }

        if ($group->min_order_amount !== null) {
            $lines[] = __('wholesale.available.min_order', ['amount' => Money::format(Money::fromRubles($group->min_order_amount))]);
        }

        return $lines;
    }

    // ------------------------------------------------------------------ internals

    private function recentCount(Customer $customer): int
    {
        return WholesaleApplication::query()
            ->where('customer_id', $customer->id)
            ->where('created_at', '>=', now()->subDays(self::PERIOD_DAYS))
            ->count();
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeDecision(User $actor): void
    {
        if (! $this->permissions->allows($actor, 'wholesale_applications.decide')) {
            throw new AuthorizationException;
        }
    }

    /**
     * The application row locked for the decision; a second decision is refused (CG-22).
     */
    private function lockPending(WholesaleApplication $application): WholesaleApplication
    {
        /** @var WholesaleApplication $locked */
        $locked = WholesaleApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

        if ($locked->isDecided()) {
            throw new WholesaleApplicationException(__('wholesale.errors.already_decided'));
        }

        return $locked;
    }

    /**
     * Copies the requisites of the application when the customer has none yet (CG-22). The legal address is not part of
     * the application: the customer fills it in the cabinet.
     */
    private function copyRequisites(WholesaleApplication $application, Customer $customer): bool
    {
        if (CustomerCompany::query()->where('customer_id', $customer->id)->exists()) {
            return false;
        }

        if (blank($application->company_name) || blank($application->inn)) {
            return false;
        }

        $company = new CustomerCompany;
        $company->forceFill([
            'customer_id' => $customer->id,
            'name' => $application->company_name,
            'inn' => $application->inn,
            'kpp' => $application->kpp,
            'ogrn' => null,
            'legal_address' => '',
        ])->save();

        return true;
    }

    /**
     * CG-23: the cabinet record and the queued e-mail about the decision.
     */
    private function notifyCustomer(WholesaleApplication $application, ?CustomerGroup $group): void
    {
        $customer = Customer::query()->find($application->customer_id);

        if ($customer === null || $customer->anonymized_at !== null) {
            return;
        }

        $approved = $application->status === WholesaleApplicationStatus::Approved;

        // CA-18: the cabinet record; the key keeps a repeated call for the same decision from doubling it.
        app(CustomerNotificationService::class)->notify(
            $customer,
            CustomerNotificationType::WholesaleDecision,
            $approved ? __('wholesale.notification.approved_title') : __('wholesale.notification.rejected_title'),
            $approved && $group !== null
                ? __('wholesale.notification.approved_body', ['group' => $group->name])
                : __('wholesale.notification.rejected_body', ['reason' => (string) $application->reject_reason]),
            route('customer.wholesale', absolute: false),
            'wholesale:'.$application->id.':'.$application->status->value.':'.($application->decided_at?->getTimestamp() ?? 0),
        );

        try {
            Mail::to($customer->email)->send(new WholesaleDecisionMail(
                name: $customer->name,
                approved: $approved,
                groupName: $group?->name,
                available: $group !== null ? $this->availableSummary($group) : [],
                reason: $approved ? null : $application->reject_reason,
                url: route('customer.wholesale'),
            ));
        } catch (Throwable $e) {
            // The decision is already stored; a mail failure must not roll it back or show an error to the manager.
            Log::warning('Wholesale decision mail failed', ['application_id' => $application->id, 'exception' => $e::class]);
        }
    }

    /**
     * CG-21: e-mail and Telegram to the shop, two independent toggles. Never blocks the customer's submit.
     */
    private function notifyShop(WholesaleApplication $application): void
    {
        try {
            $settings = app(NotificationSettings::class);
            /** @var list<ShouldQueue> $jobs */
            $jobs = [];

            if ($settings->notify_wholesale_email) {
                foreach (array_unique(array_filter($settings->notify_emails)) as $email) {
                    $jobs[] = new SendWholesaleApplicationNotification($application->id, SendWholesaleApplicationNotification::MAIL, $email);
                }
            }

            if ($settings->telegram_enabled && $settings->notify_wholesale_telegram && app(TelegramNotifier::class)->isConfigured()) {
                $jobs[] = new SendWholesaleApplicationNotification($application->id, SendWholesaleApplicationNotification::TELEGRAM);
            }

            JobFanOut::dispatch($jobs);
        } catch (Throwable $e) {
            Log::warning('Wholesale application notification not queued', ['application_id' => $application->id, 'exception' => $e::class]);
        }
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
