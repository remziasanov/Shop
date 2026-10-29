<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\CustomerNotificationType;
use App\Enums\WholesaleApplicationStatus;
use App\Events\CustomerAnonymizing;
use App\Exceptions\WholesaleApplicationException;
use App\Listeners\EraseWholesaleApplicationData;
use App\Mail\WholesaleDecisionMail;
use App\Models\AdminAuditEntry;
use App\Models\Customer;
use App\Models\CustomerCompany;
use App\Models\CustomerGroup;
use App\Models\CustomerNotification;
use App\Models\User;
use App\Models\WholesaleApplication;
use App\Services\Access\ViewerContext;
use App\Services\Customers\CustomerAnonymizer;
use App\Services\Customers\WholesaleApplicationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/**
 * CG-20…CG-24, CA-17 (TZ 25.2): the rules of wholesale applications, away from any screen.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    $this->service = app(WholesaleApplicationService::class);
    $this->opt = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
    $this->customer = makeCustomer(['email' => 'buyer@example.com', 'name' => 'Пётр Покупатель']);
    $this->manager = User::factory()->manager()->create();
    $this->admin = User::factory()->admin()->create();
});

/** @return array{company_name: string, inn: string, kpp: string|null, contact_name: string, phone: string, comment: string|null} */
function wholesaleData(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'ООО «Ромашка»',
        'inn' => '7707083893',
        'kpp' => '770701001',
        'contact_name' => 'Пётр Покупатель',
        'phone' => '+79001234567',
        'comment' => 'Берём оптом',
    ], $overrides);
}

function submitWholesale(Customer $customer, array $data = []): WholesaleApplication
{
    return app(WholesaleApplicationService::class)->submit($customer, wholesaleData($data), '192.0.2.10', 'TestBrowser/1.0');
}

describe('applying (CG-20)', function (): void {
    it('stores the application with the account e-mail and the consent trace', function (): void {
        $application = submitWholesale($this->customer);

        expect($application->status)->toBe(WholesaleApplicationStatus::New)
            ->and($application->customer_id)->toBe($this->customer->id)
            ->and($application->email)->toBe('buyer@example.com')
            ->and($application->company_name)->toBe('ООО «Ромашка»')
            ->and($application->inn)->toBe('7707083893')
            ->and($application->consent_at)->not->toBeNull()
            ->and($application->consent_ip)->toBe('192.0.2.10')
            ->and($application->consent_user_agent)->toBe('TestBrowser/1.0');
    });

    it('allows one pending application per customer', function (): void {
        submitWholesale($this->customer);

        expect(fn () => submitWholesale($this->customer))->toThrow(WholesaleApplicationException::class, __('wholesale.errors.pending'));
        expect(WholesaleApplication::query()->count())->toBe(1);
    });

    it('allows at most 3 applications in 30 days and counts only recent ones', function (): void {
        WholesaleApplication::factory()->count(3)->rejected()->forCustomer($this->customer)->create(['created_at' => now()->subDays(5)]);

        expect(fn () => submitWholesale($this->customer))->toThrow(WholesaleApplicationException::class, __('wholesale.errors.limit', ['count' => 3]));

        // Two of the three fall out of the 30 days window.
        WholesaleApplication::query()->orderBy('id')->limit(2)->update(['created_at' => now()->subDays(31)]);

        expect(submitWholesale($this->customer)->isNew())->toBeTrue();
    });

    it('refuses an unconfirmed, a blocked and an already wholesale customer', function (): void {
        $unverified = makeCustomer(['email_verified_at' => null]);
        $blocked = Customer::factory()->blocked()->create();
        $wholesaler = Customer::factory()->inGroup($this->opt)->create();

        expect(fn () => submitWholesale($unverified))->toThrow(WholesaleApplicationException::class, __('wholesale.errors.unverified'))
            ->and(fn () => submitWholesale($blocked))->toThrow(WholesaleApplicationException::class, __('wholesale.errors.blocked'))
            ->and(fn () => submitWholesale($wholesaler))->toThrow(WholesaleApplicationException::class);
        expect(WholesaleApplication::query()->count())->toBe(0);
    });

    it('writes the application to the journal with identifiers only (CG-24, RB-12)', function (): void {
        $application = submitWholesale($this->customer);

        $entry = AdminAuditEntry::query()->where('action', AuditAction::WholesaleApplicationCreated->value)->sole();

        expect($entry->subject_type)->toBe('wholesale_applications')
            ->and($entry->subject_id)->toBe($application->id)
            ->and($entry->data)->toBe(['customer_id' => $this->customer->id])
            ->and($entry->ip)->toBeNull()
            ->and($entry->user_id)->toBeNull();
    });
});

describe('approving (CG-22, CG-23)', function (): void {
    it('moves the customer to the chosen group and copies the requisites when there are none', function (): void {
        $application = submitWholesale($this->customer);

        $this->service->approve($application, $this->opt, $this->manager);

        $application->refresh();
        $company = CustomerCompany::query()->where('customer_id', $this->customer->id)->sole();

        expect($application->status)->toBe(WholesaleApplicationStatus::Approved)
            ->and($application->decided_by)->toBe($this->manager->id)
            ->and($application->decided_at)->not->toBeNull()
            ->and($application->decided_group_id)->toBe($this->opt->id)
            ->and($this->customer->fresh()->customer_group_id)->toBe($this->opt->id)
            ->and($company->name)->toBe('ООО «Ромашка»')
            ->and($company->inn)->toBe('7707083893')
            ->and($company->kpp)->toBe('770701001');
    });

    it('keeps the requisites the customer already has', function (): void {
        CustomerCompany::factory()->create(['customer_id' => $this->customer->id, 'name' => 'ИП Старый', 'inn' => '500100732259', 'kpp' => null]);
        $application = submitWholesale($this->customer);

        $this->service->approve($application, $this->opt, $this->manager);

        $company = CustomerCompany::query()->where('customer_id', $this->customer->id)->sole();
        expect($company->name)->toBe('ИП Старый')->and($company->inn)->toBe('500100732259');
    });

    it('makes the customer see the new group on the storefront', function (): void {
        $application = submitWholesale($this->customer);

        $this->service->approve($application, $this->opt, $this->manager);

        signInCustomer($this, $this->customer->fresh());
        $this->get('/');

        expect(app(ViewerContext::class)->group()->id)->toBe($this->opt->id);
    });

    it('records the decision in the journal and tells the customer in the cabinet and by e-mail', function (): void {
        $application = submitWholesale($this->customer);

        $this->service->approve($application, $this->opt, $this->manager);

        $entry = AdminAuditEntry::query()->where('action', AuditAction::WholesaleApplicationApproved->value)->sole();
        expect($entry->user_id)->toBe($this->manager->id)
            ->and($entry->data)->toEqual(['customer_id' => $this->customer->id, 'group_id' => $this->opt->id, 'requisites_copied' => true]);

        $notification = CustomerNotification::query()->where('customer_id', $this->customer->id)->sole();
        expect($notification->type)->toBe(CustomerNotificationType::WholesaleDecision->value)
            ->and($notification->body)->toContain('Опт');

        Mail::assertQueued(WholesaleDecisionMail::class, function (WholesaleDecisionMail $mail): bool {
            return $mail->hasTo('buyer@example.com') && $mail->approved && $mail->groupName === 'Опт' && $mail->available !== [];
        });
    });

    it('lists what became available in the e-mail text', function (): void {
        $this->opt->update(['min_order_amount' => '15000', 'visible_sections' => ['catalog', 'search', 'price_list']]);
        $application = submitWholesale($this->customer);

        $this->service->approve($application, $this->opt->fresh(), $this->manager);

        Mail::assertQueued(WholesaleDecisionMail::class, function (WholesaleDecisionMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Опт') && str_contains($html, 'прайс-лист') && str_contains($html, 'Минимальная сумма заказа');
        });
    });

    it('makes no second decision', function (): void {
        $application = submitWholesale($this->customer);
        $this->service->approve($application, $this->opt, $this->manager);

        expect(fn () => $this->service->approve($application, $this->opt, $this->manager))->toThrow(WholesaleApplicationException::class, __('wholesale.errors.already_decided'))
            ->and(fn () => $this->service->reject($application, 'Поздно', $this->manager))->toThrow(WholesaleApplicationException::class);
        expect(AdminAuditEntry::query()->where('action', AuditAction::WholesaleApplicationApproved->value)->count())->toBe(1);
    });

    it('refuses the «Гость» and an inactive group', function (): void {
        $application = submitWholesale($this->customer);
        $inactive = CustomerGroup::factory()->create(['is_active' => false]);

        expect(fn () => $this->service->approve($application, guestGroup(), $this->manager))->toThrow(WholesaleApplicationException::class)
            ->and(fn () => $this->service->approve($application, $inactive, $this->manager))->toThrow(WholesaleApplicationException::class);
        expect($application->fresh()->isNew())->toBeTrue();
    });

    it('needs the decide right and never leaves a partial change', function (): void {
        $application = submitWholesale($this->customer);
        $viewer = staffWith(['wholesale_applications.view']);

        expect(fn () => $this->service->approve($application, $this->opt, $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->reject($application, 'Нет', $viewer))->toThrow(AuthorizationException::class);
        expect($application->fresh()->isNew())->toBeTrue()
            ->and($this->customer->fresh()->customer_group_id)->not->toBe($this->opt->id);
    });

    it('does not approve an anonymized customer', function (): void {
        $application = submitWholesale($this->customer);
        $this->customer->forceFill(['anonymized_at' => now()])->save();

        expect(fn () => $this->service->approve($application, $this->opt, $this->manager))->toThrow(WholesaleApplicationException::class, __('wholesale.errors.anonymized'));
    });
});

describe('rejecting (CG-22, CG-23)', function (): void {
    it('stores the reason with the staff member and the time, and tells the customer', function (): void {
        $application = submitWholesale($this->customer);

        $this->service->reject($application, '  Не удалось подтвердить компанию  ', $this->manager);

        $application->refresh();
        expect($application->status)->toBe(WholesaleApplicationStatus::Rejected)
            ->and($application->reject_reason)->toBe('Не удалось подтвердить компанию')
            ->and($application->decided_by)->toBe($this->manager->id)
            ->and($application->decided_at)->not->toBeNull()
            ->and($this->customer->fresh()->customer_group_id)->toBe(CustomerGroup::retail()->id);

        expect(CustomerNotification::query()->where('customer_id', $this->customer->id)->sole()->body)->toContain('Не удалось подтвердить компанию');

        Mail::assertQueued(WholesaleDecisionMail::class, function (WholesaleDecisionMail $mail): bool {
            $html = $mail->render();

            return ! $mail->approved && str_contains($html, 'Не удалось подтвердить компанию') && str_contains($html, 'новую заявку');
        });
        expect(AdminAuditEntry::query()->where('action', AuditAction::WholesaleApplicationRejected->value)->count())->toBe(1);
    });

    it('requires a reason', function (): void {
        $application = submitWholesale($this->customer);

        expect(fn () => $this->service->reject($application, '   ', $this->manager))->toThrow(WholesaleApplicationException::class, __('wholesale.errors.reason_required'));
        expect($application->fresh()->isNew())->toBeTrue();
    });

    it('lets the customer apply again after a rejection', function (): void {
        $application = submitWholesale($this->customer);
        $this->service->reject($application, 'Нет данных', $this->manager);

        expect(submitWholesale($this->customer, ['inn' => '7830002293', 'kpp' => '783001001'])->isNew())->toBeTrue();
    });
});

describe('returning to «new» (CG-22)', function (): void {
    it('is for the Administrator only and needs a comment', function (): void {
        $application = submitWholesale($this->customer);
        $this->service->reject($application, 'Ошибка менеджера', $this->manager);

        expect(fn () => $this->service->returnToNew($application, 'Вернуть', $this->manager))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->returnToNew($application, ' ', $this->admin))->toThrow(WholesaleApplicationException::class);
        expect($application->fresh()->status)->toBe(WholesaleApplicationStatus::Rejected);
    });

    it('clears the decision, keeps the comment and allows a new decision', function (): void {
        $application = submitWholesale($this->customer);
        $this->service->reject($application, 'Ошибка менеджера', $this->manager);

        $this->service->returnToNew($application, 'Проверили по ЕГРЮЛ', $this->admin);

        $application->refresh();
        expect($application->status)->toBe(WholesaleApplicationStatus::New)
            ->and($application->decided_by)->toBeNull()
            ->and($application->decided_at)->toBeNull()
            ->and($application->reject_reason)->toBeNull()
            ->and($application->return_comment)->toBe('Проверили по ЕГРЮЛ');

        $this->service->approve($application, $this->opt, $this->manager);
        expect($application->fresh()->status)->toBe(WholesaleApplicationStatus::Approved);

        $entry = AdminAuditEntry::query()->where('action', AuditAction::WholesaleApplicationReturned->value)->sole();
        expect($entry->user_id)->toBe($this->admin->id)->and($entry->data['from_status'])->toBe('rejected');
    });

    it('refuses an undecided application and a second pending one', function (): void {
        $application = submitWholesale($this->customer);
        expect(fn () => $this->service->returnToNew($application, 'Зачем', $this->admin))->toThrow(WholesaleApplicationException::class);

        $this->service->reject($application, 'Нет', $this->manager);
        $other = submitWholesale($this->customer);

        expect(fn () => $this->service->returnToNew($application, 'Вернуть', $this->admin))->toThrow(WholesaleApplicationException::class, __('wholesale.errors.pending'));
        expect($other->fresh()->isNew())->toBeTrue();
    });
});

describe('duplicates (CG-24)', function (): void {
    it('warns about the same INN in an application or in the requisites of another customer', function (): void {
        $first = WholesaleApplication::factory()->create(['inn' => '7707083893']);
        $other = makeCustomer();
        CustomerCompany::factory()->create(['customer_id' => $other->id, 'inn' => '7707083893']);
        $mine = submitWholesale($this->customer);

        $warnings = $this->service->duplicateWarnings($mine);

        expect($warnings)->toHaveCount(2)
            ->and($warnings[0])->toContain('Этот ИНН уже в заявке #'.$first->id)->toContain('#'.$first->customer_id)
            ->and($warnings[1])->toContain('Этот ИНН уже у покупателя #'.$other->id);
    });

    it('does not warn about the customer own data or a different INN', function (): void {
        CustomerCompany::factory()->create(['customer_id' => $this->customer->id, 'inn' => '7707083893']);
        WholesaleApplication::factory()->create(['inn' => '7830002293']);
        $mine = submitWholesale($this->customer);

        expect($this->service->duplicateWarnings($mine))->toBe([]);
    });
});

describe('counter', function (): void {
    it('counts the new applications and follows the decisions at once', function (): void {
        expect($this->service->pendingCount())->toBe(0);

        $application = submitWholesale($this->customer);
        expect($this->service->pendingCount())->toBe(1);

        $this->service->reject($application, 'Нет', $this->manager);
        expect($this->service->pendingCount())->toBe(0);
    });
});

describe('anonymization (CA-17)', function (): void {
    it('erases the personal fields of the applications and keeps the rows', function (): void {
        $application = submitWholesale($this->customer);
        $this->service->reject($application, 'Нет, потому что ИП Иванов', $this->manager);

        CustomerAnonymizing::dispatch($this->customer);

        $application->refresh();
        expect($application->status)->toBe(WholesaleApplicationStatus::Rejected)
            ->and($application->decided_by)->toBe($this->manager->id)
            ->and($application->decided_at)->not->toBeNull();

        foreach (['company_name', 'inn', 'kpp', 'contact_name', 'phone', 'email', 'comment', 'consent_ip', 'consent_user_agent', 'reject_reason', 'return_comment'] as $field) {
            expect($application->{$field})->toBeNull();
        }
    });

    it('is part of the real anonymization and leaves other customers alone', function (): void {
        $mine = submitWholesale($this->customer);
        $theirs = WholesaleApplication::factory()->create();

        app(CustomerAnonymizer::class)->anonymize($this->customer, $this->admin);

        expect($mine->fresh()->company_name)->toBeNull()
            ->and($mine->fresh()->phone)->toBeNull()
            ->and($theirs->fresh()->company_name)->not->toBeNull();
    });

    it('is registered exactly once for the anonymization event (CA-17)', function (): void {
        $registered = collect(Event::getRawListeners()[CustomerAnonymizing::class] ?? [])
            ->filter(fn (mixed $listener): bool => is_array($listener) && ($listener[0] ?? null) === EraseWholesaleApplicationData::class
                || (is_string($listener) && str_starts_with($listener, EraseWholesaleApplicationData::class)));

        expect($registered)->toHaveCount(1);
    });

    it('is not counted as a duplicate afterwards', function (): void {
        $old = submitWholesale($this->customer);
        CustomerAnonymizing::dispatch($this->customer);
        $new = WholesaleApplication::factory()->create(['inn' => '7707083893']);

        expect($old->fresh()->inn)->toBeNull()
            ->and($this->service->duplicateWarnings($new))->toBe([]);
    });
});
