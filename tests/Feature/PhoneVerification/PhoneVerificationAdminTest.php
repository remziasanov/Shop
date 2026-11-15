<?php

declare(strict_types=1);

use App\Filament\Pages\PhoneVerificationJournal;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\Content\DefaultPageTemplates;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * PV-09: the badge «Телефон подтверждён» and its filter in the customers and requests, the journal of 30 days and the
 * totals of the month, the access right.
 */
afterEach(fn () => Carbon::setTestNow());

describe('badge and filter', function (): void {
    beforeEach(fn () => $this->actingAs(User::factory()->admin()->create()));

    it('filters customers by the verified phone and shows it on the card', function (): void {
        $yes = makeCustomer(['name' => 'Подтверждённый', 'phone_verified_at' => now()]);
        $no = makeCustomer(['name' => 'Неподтверждённый', 'phone_verified_at' => null]);

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$yes, $no])
            ->assertSee('Телефон подтверждён')
            ->filterTable('phone_verified', true)->assertCanSeeTableRecords([$yes])->assertCanNotSeeTableRecords([$no])
            ->removeTableFilters()
            ->filterTable('phone_verified', false)->assertCanSeeTableRecords([$no])->assertCanNotSeeTableRecords([$yes]);

        $this->get(CustomerResource::getUrl('view', ['record' => $yes]))->assertOk()->assertSee('Телефон подтверждён');
        $this->get(CustomerResource::getUrl('view', ['record' => $no]))->assertOk()->assertSee('Не подтверждён');
    });

    it('filters requests by the verified phone and shows it on the card', function (): void {
        $yes = Order::factory()->create(['phone_verified' => true]);
        $no = Order::factory()->create(['phone_verified' => false]);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$yes, $no])
            ->filterTable('phone_verified', true)->assertCanSeeTableRecords([$yes])->assertCanNotSeeTableRecords([$no])
            ->removeTableFilters()
            ->filterTable('phone_verified', false)->assertCanSeeTableRecords([$no])->assertCanNotSeeTableRecords([$yes]);

        $this->get(OrderResource::getUrl('view', ['record' => $yes]))->assertOk()->assertSee('Телефон подтверждён');
        $this->get(OrderResource::getUrl('view', ['record' => $no]))->assertOk()->assertSee('Не подтверждён');
    });
});

describe('journal «Подтверждения телефона»', function (): void {
    it('opens only with settings.phone_verification.view', function (): void {
        $this->actingAs(User::factory()->manager()->create());
        expect(PhoneVerificationJournal::canAccess())->toBeFalse();

        $this->actingAs(staffWith(['settings.phone_verification.update']));
        expect(PhoneVerificationJournal::canAccess())->toBeFalse();

        $this->actingAs(staffWith(['settings.phone_verification.view']));
        expect(PhoneVerificationJournal::canAccess())->toBeTrue();
        $this->get(PhoneVerificationJournal::getUrl())->assertOk()->assertSee('Подтверждения телефона');

        $this->actingAs(User::factory()->manager()->create());
        $this->get(PhoneVerificationJournal::getUrl())->assertForbidden();
    });

    it('lists the last 30 days with a masked number, method, outcome and cost', function (): void {
        $this->actingAs(staffWith(['settings.phone_verification.view']));
        $fresh = PhoneVerification::factory()->verified()->create(['phone' => '+79991234567', 'cost' => '1.3000', 'created_at' => now()->subDays(2)]);
        $skipped = PhoneVerification::factory()->skipped()->create(['phone' => '+79990001122', 'created_at' => now()->subDay()]);
        $old = PhoneVerification::factory()->create(['phone' => '+79995554433', 'created_at' => now()->subDays(45)]);

        $component = Livewire::test(PhoneVerificationJournal::class)
            ->assertCanSeeTableRecords([$fresh, $skipped])
            ->assertCanNotSeeTableRecords([$old]);
        $html = $component->html();

        expect($html)->toContain('+7******4567')->toContain('Звонок-сброс')->toContain('Подтверждён')->toContain('Пропущено: сбой провайдера')->toContain('1.30')
            ->and($html)->not->toContain('+79991234567')->not->toContain('9991234567');
    });

    it('totals the month: the count of accepted requests and the cost rounded to kopecks', function (): void {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->actingAs(staffWith(['settings.phone_verification.view']));
        PhoneVerification::factory()->verified()->create(['cost' => '1.3000']);
        PhoneVerification::factory()->create(['cost' => '1.3049']);
        PhoneVerification::factory()->create(['cost' => '5.9500', 'method' => 'sms']);
        // Not accepted by the provider and from last month: not counted.
        PhoneVerification::factory()->skipped()->create();
        PhoneVerification::factory()->create(['cost' => '9.0000', 'created_at' => '2026-10-31 23:00:00']);

        $component = Livewire::test(PhoneVerificationJournal::class);

        expect($component->instance()->monthTotals())->toBe(['count' => 3, 'cost' => '8.55'])
            ->and($component->html())->toContain('data-total-count')->toContain('8.55 ₽');
        expect(PhoneVerificationJournal::roundedCost('0.0050'))->toBe('0.01')->and(PhoneVerificationJournal::roundedCost('0.0049'))->toBe('0.00');
    });
});

it('names the provider as a processor of personal data in the privacy policy and the consent (PV-10)', function (): void {
    $pages = collect(DefaultPageTemplates::all())->keyBy('slug');

    expect($pages[DefaultPageTemplates::PRIVACY_SLUG]['content'])->toContain('SMS Aero')->toContain('по поручению Оператора')
        ->and($pages[DefaultPageTemplates::CONSENT_SLUG]['content'])->toContain('SMS Aero')->toContain('Обработчики по поручению Оператора');
});
