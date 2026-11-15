<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/telegram_staff.php';

use App\Enums\AuditAction;
use App\Enums\StaffOrderAction;
use App\Enums\StatusChangeSource;
use App\Filament\Pages\MyTelegram;
use App\Filament\Resources\AuditLog\Pages\ListAuditLog;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AdminAuditEntry;
use App\Models\Order;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Telegram\Staff\StaffOrderActions;
use Livewire\Livewire;

/**
 * TS-02, TS-07, TS-08, RB-12: the staff link in «Пользователи», the personal switch of the profile, the journal of the
 * actions from Telegram, the staff messages in the order card.
 */
beforeEach(function (): void {
    $this->withoutVite();
    staffBot();
});

describe('«Пользователи» (TS-02)', function (): void {
    it('shows the linked Telegram and lets an administrator remove the link', function (): void {
        $admin = User::factory()->admin()->create();
        [$staff, $account] = linkedStaff();
        $account->forceFill(['username' => 'ivan_tg'])->save();
        $staff->forceFill(['telegram_personal_notify' => true])->save();
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->assertSee('@ivan_tg')
            ->assertSee('не подключён') // the admin himself
            ->callTableAction('unlink_telegram', $staff);

        expect($account->refresh()->user_id)->toBeNull()
            ->and($staff->refresh()->telegram_personal_notify)->toBeFalse();

        $entry = AdminAuditEntry::query()->where('action', AuditAction::UserTelegramUnlinked->value)->firstOrFail();
        expect($entry->user_id)->toBe($admin->id)->and($entry->subject_id)->toBe($staff->id);
    });

    it('stops the buttons at once after the administrator removed the link', function (): void {
        $admin = User::factory()->admin()->create();
        [$staff] = linkedStaff();
        fakeTelegramApi();
        $order = Order::factory()->order()->create();
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)->callTableAction('unlink_telegram', $staff);

        telegramWebhook(pressStaffButton(1, 'o:cf:'.$order->id.':'.$order->refresh()->version))->assertJsonPath('text', 'Подключите Telegram в профиле админки');
        expect($order->refresh()->status->value)->toBe('new');
    });

    it('hides the unlink action for a user without a link and for staff who may not manage users', function (): void {
        $admin = User::factory()->admin()->create();
        $plain = User::factory()->manager()->create();
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)->assertTableActionHidden('unlink_telegram', $plain);

        [$staff] = linkedStaff();
        $this->actingAs(User::factory()->withPermissions(['users.view'])->create());

        Livewire::test(ListUsers::class)->assertTableActionHidden('unlink_telegram', $staff);
    });
});

describe('the profile switch of personal messages (TS-08)', function (): void {
    it('is off by default, saved with the switch and reset by «Отключить»', function (): void {
        [$staff] = linkedStaff();
        $this->actingAs($staff);

        $page = Livewire::test(MyTelegram::class)->assertSee('Присылать новые обращения лично')->assertSet('personalNotify', false);

        $page->set('personalNotify', true);
        expect($staff->refresh()->telegram_personal_notify)->toBeTrue();

        $page->callAction('disconnect');
        expect($staff->refresh()->telegram_personal_notify)->toBeFalse();
    });

    it('refuses to switch on without a linked Telegram', function (): void {
        $user = User::factory()->manager()->create();
        $this->actingAs($user);

        Livewire::test(MyTelegram::class)->set('personalNotify', true)->assertSet('personalNotify', false);

        expect($user->refresh()->telegram_personal_notify)->toBeFalse();
    });
});

describe('the journal (TS-07, RB-12)', function (): void {
    it('lists the status changes and extensions made from Telegram with their source', function (): void {
        [$staff] = linkedStaff();
        $order = Order::factory()->reservation()->create();
        $actions = app(StaffOrderActions::class);
        $actions->perform($staff, $order->id, StaffOrderAction::Extend, null, 'chat');
        $actions->perform($staff, $order->id, StaffOrderAction::Confirm, null, 'chat');

        $this->actingAs(User::factory()->admin()->create());
        $entries = AdminAuditEntry::query()->whereIn('action', [AuditAction::OrderStatusChanged->value, AuditAction::OrderReservationExtended->value])->get();

        expect($entries)->toHaveCount(2)->and($entries->pluck('source')->unique()->all())->toBe([StatusChangeSource::Telegram]);

        Livewire::test(ListAuditLog::class)->assertCanSeeTableRecords($entries)->filterTable('source', 'telegram')->assertCanSeeTableRecords($entries);
        expect(AuditAction::OrderStatusChanged->label())->toBe('Статус обращения изменён');
    });

    it('does not write personal data into the journal entry', function (): void {
        [$staff] = linkedStaff();
        $order = Order::factory()->order()->create(['customer_name' => 'Секретный Покупатель', 'customer_phone' => '+79990001122']);
        app(StaffOrderActions::class)->perform($staff, $order->id, StaffOrderAction::Confirm, null, 'chat');

        $json = (string) json_encode(AdminAuditEntry::query()->get()->toArray(), JSON_UNESCAPED_UNICODE);

        expect($json)->not->toContain('Секретный')->not->toContain('79990001122');
    });
});

describe('the order card (TS-01)', function (): void {
    it('shows the staff messages of the request in a section of their own', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        $order = Order::factory()->order()->create();
        TelegramMessage::factory()->sent()->create(['order_id' => $order->id, 'kind' => 'staff_new_order', 'telegram_account_id' => null]);

        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSee('Сообщения сотрудникам в Telegram')
            ->assertSeeHtml('data-staff-telegram-messages')
            ->assertSee('Рабочий чат');
    });

    it('hides the section while no staff message was sent', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        $order = Order::factory()->order()->create();

        Livewire::test(ViewOrder::class, ['record' => $order->uuid])->assertDontSee('Сообщения сотрудникам в Telegram');
    });
});
