<?php

declare(strict_types=1);

use App\Enums\WholesaleApplicationStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Customers\RelationManagers\WholesaleApplicationsRelationManager;
use App\Filament\Resources\WholesaleApplications\Actions\WholesaleDecisionActions;
use App\Filament\Resources\WholesaleApplications\Pages\ListWholesaleApplications;
use App\Filament\Resources\WholesaleApplications\Pages\ViewWholesaleApplication;
use App\Filament\Resources\WholesaleApplications\WholesaleApplicationResource;
use App\Filament\Widgets\WholesaleApplicationsWidget;
use App\Models\CustomerCompany;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Models\WholesaleApplication;
use App\Services\Customers\WholesaleApplicationService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * CG-21, CG-22, CG-24, CA-20, CA-22 (TZ 25.2): the «Заявки на опт» resource, its rights, the counter and the widget.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    $this->opt = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
    $this->customer = makeCustomer(['email' => 'buyer@example.com']);
    $this->application = WholesaleApplication::factory()->forCustomer($this->customer)->create(['company_name' => 'ООО «Ромашка»']);
});

describe('rights (RB-05)', function (): void {
    it('is closed without wholesale_applications.view', function (): void {
        $this->actingAs(staffWith(['orders.view']));

        $this->get(WholesaleApplicationResource::getUrl('index'))->assertForbidden();
        expect(WholesaleApplicationResource::canViewAny())->toBeFalse();
    });

    it('shows the list and the card to a viewer who cannot decide', function (): void {
        $this->actingAs(staffWith(['wholesale_applications.view']));

        Livewire::test(ListWholesaleApplications::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->application])
            ->assertTableActionHidden('approve', $this->application)
            ->assertTableActionHidden('reject', $this->application);
        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('approve')
            ->assertActionHidden('reject');
    });

    it('lets the default Manager decide but not return to new', function (): void {
        $this->actingAs(User::factory()->manager()->create());

        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->assertActionVisible('approve')
            ->assertActionVisible('reject')
            ->assertActionHidden('return_to_new');
    });

    it('has no create, edit or delete', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        expect(WholesaleApplicationResource::canCreate())->toBeFalse()
            ->and(WholesaleApplicationResource::canEdit($this->application))->toBeFalse()
            ->and(WholesaleApplicationResource::canDelete($this->application))->toBeFalse();
    });
});

describe('list', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->manager()->create());
    });

    it('opens on the new applications and has tabs for the decided ones', function (): void {
        $done = WholesaleApplication::factory()->rejected()->create();

        Livewire::test(ListWholesaleApplications::class)
            ->assertCanSeeTableRecords([$this->application])
            ->assertCanNotSeeTableRecords([$done])
            ->set('activeTab', 'rejected')
            ->assertCanSeeTableRecords([$done])
            ->assertCanNotSeeTableRecords([$this->application])
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$this->application, $done]);
    });

    it('shows the number of new applications on the menu item (CG-21)', function (): void {
        WholesaleApplication::factory()->count(2)->create();
        WholesaleApplication::factory()->rejected()->create();

        expect(WholesaleApplicationResource::getNavigationBadge())->toBe('3');
    });

    it('has no badge when nothing waits', function (): void {
        WholesaleApplication::query()->delete();

        expect(WholesaleApplicationResource::getNavigationBadge())->toBeNull();
    });
});

describe('card (CG-24)', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->manager()->create());
    });

    it('shows the application data', function (): void {
        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->assertSee('ООО «Ромашка»')
            ->assertSee('7707083893')
            ->assertSee('buyer@example.com');
    });

    it('warns the manager about a duplicate INN', function (): void {
        $other = WholesaleApplication::factory()->create(['inn' => $this->application->inn]);

        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->assertSee('Этот ИНН уже в заявке #'.$other->id);
    });

    it('does not warn when the INN is unique', function (): void {
        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->assertDontSee('Этот ИНН уже');
    });
});

describe('approve (CG-22)', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->manager()->create());
    });

    it('offers the wholesale default group preselected, without «Гость»', function (): void {
        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->mountAction('approve')
            ->assertSchemaStateSet(['group_id' => $this->opt->id]);

        expect(array_keys(WholesaleDecisionActions::assignableGroups()))
            ->toContain($this->opt->id, CustomerGroup::retail()->id)
            ->not->toContain(guestGroup()->id);
    });

    it('requires a group', function (): void {
        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->callAction('approve', ['group_id' => null])
            ->assertHasActionErrors(['group_id' => 'required']);

        expect($this->application->fresh()->isNew())->toBeTrue();
    });

    it('approves with the chosen group from the card', function (): void {
        $custom = CustomerGroup::factory()->create(['name' => 'Дилеры']);

        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->callAction('approve', ['group_id' => $custom->id])
            ->assertNotified(__('wholesale.actions.approved'));

        $application = $this->application->fresh();
        expect($application->status)->toBe(WholesaleApplicationStatus::Approved)
            ->and($application->decided_group_id)->toBe($custom->id)
            ->and($this->customer->fresh()->customer_group_id)->toBe($custom->id)
            ->and(CustomerCompany::query()->where('customer_id', $this->customer->id)->exists())->toBeTrue();
    });

    it('approves from the list row', function (): void {
        Livewire::test(ListWholesaleApplications::class)
            ->callAction(TestAction::make('approve')->table($this->application), ['group_id' => $this->opt->id])
            ->assertNotified();

        expect($this->application->fresh()->status)->toBe(WholesaleApplicationStatus::Approved)
            ->and($this->customer->fresh()->customer_group_id)->toBe($this->opt->id);
    });

    it('hides the decision buttons once decided', function (): void {
        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->callAction('approve', ['group_id' => $this->opt->id])
            ->assertActionHidden('approve')
            ->assertActionHidden('reject');
    });
});

describe('reject (CG-22)', function (): void {
    beforeEach(function (): void {
        $this->manager = User::factory()->manager()->create();
        $this->actingAs($this->manager);
    });

    it('requires a reason', function (): void {
        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->callAction('reject', ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        expect($this->application->fresh()->isNew())->toBeTrue();
    });

    it('rejects with the reason, the staff member and the time', function (): void {
        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->callAction('reject', ['reason' => 'Не нашли компанию в ЕГРЮЛ'])
            ->assertNotified(__('wholesale.actions.rejected'));

        $application = $this->application->fresh();
        expect($application->status)->toBe(WholesaleApplicationStatus::Rejected)
            ->and($application->reject_reason)->toBe('Не нашли компанию в ЕГРЮЛ')
            ->and($application->decided_by)->toBe($this->manager->id)
            ->and($application->decided_at)->not->toBeNull();
    });

    it('offers no second decision once the application is decided', function (): void {
        app(WholesaleApplicationService::class)->approve($this->application, $this->opt, $this->manager);

        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->assertActionHidden('reject')
            ->assertActionHidden('approve');
        Livewire::test(ListWholesaleApplications::class)
            ->set('activeTab', 'approved')
            ->assertTableActionHidden('reject', $this->application)
            ->assertTableActionHidden('approve', $this->application);
    });
});

describe('return to new (CG-22)', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
        $this->application->forceFill(['status' => 'rejected', 'decided_at' => now(), 'reject_reason' => 'Нет'])->save();
    });

    it('needs a comment', function (): void {
        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->callAction('return_to_new', ['comment' => ''])
            ->assertHasActionErrors(['comment' => 'required']);

        expect($this->application->fresh()->status)->toBe(WholesaleApplicationStatus::Rejected);
    });

    it('is offered to the Administrator for a decided application', function (): void {
        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->assertActionVisible('return_to_new')
            ->assertActionHidden('approve')
            ->callAction('return_to_new', ['comment' => 'Менеджер ошибся'])
            ->assertNotified(__('wholesale.actions.returned'));

        $application = $this->application->fresh();
        expect($application->status)->toBe(WholesaleApplicationStatus::New)
            ->and($application->return_comment)->toBe('Менеджер ошибся');
    });

    it('is not offered for a new application', function (): void {
        $this->application->forceFill(['status' => 'new', 'decided_at' => null, 'reject_reason' => null])->save();

        Livewire::test(ViewWholesaleApplication::class, ['record' => $this->application->getRouteKey()])
            ->assertActionHidden('return_to_new');
    });
});

describe('dashboard widget (CG-21)', function (): void {
    it('shows the number of new applications to staff who may view them', function (): void {
        WholesaleApplication::factory()->count(2)->create();
        $this->actingAs(staffWith(['wholesale_applications.view', 'dashboard.view']));

        expect(WholesaleApplicationsWidget::canView())->toBeTrue();
        Livewire::test(WholesaleApplicationsWidget::class)->assertSee(__('wholesale.widget.new'))->assertSee('3');
    });

    it('is hidden from staff without the right', function (): void {
        $this->actingAs(staffWith(['dashboard.view']));

        expect(WholesaleApplicationsWidget::canView())->toBeFalse();
    });

    it('is on the dashboard', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        expect(Livewire::test(Dashboard::class)->instance()->getWidgets())->toContain(WholesaleApplicationsWidget::class);
    });
});

describe('tab of a customer (CA-20)', function (): void {
    it('lists only the applications of that customer', function (): void {
        $this->actingAs(User::factory()->manager()->create());
        $mine = WholesaleApplication::factory()->rejected()->forCustomer($this->customer)->create();
        $foreign = WholesaleApplication::factory()->create();
        $owner = $this->customer;

        Livewire::test(WholesaleApplicationsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => ViewCustomer::class])
            ->assertOk()
            ->assertCanSeeTableRecords([$this->application, $mine])
            ->assertCanNotSeeTableRecords([$foreign]);
    });
});
