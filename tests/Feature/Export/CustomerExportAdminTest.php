<?php

declare(strict_types=1);

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Filament\Pages\Settings\ManageCustomerSettings;
use App\Filament\Pages\Settings\ManageLegalSettings;
use App\Filament\Resources\CustomerGroups\Pages\EditCustomerGroup;
use App\Filament\Resources\Exports\ExportResource;
use App\Filament\Resources\Exports\Pages\ListExports;
use App\Models\CustomerGroup;
use App\Models\Export;
use App\Models\User;
use App\Settings\CustomerSettings;
use App\Settings\LegalSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * CX-13, TZ 18.4: the admin side: the tab «Выгрузки покупателей» with the deletion of a file, the group flag, the
 * settings of the exports and the bank requisites.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

it('shows the tab «Выгрузки покупателей» to an administrator with the customer, status, size and date', function (): void {
    actingAs(User::factory()->admin()->create());
    $customer = makeCustomer(['name' => 'Пётр Покупателев']);
    Storage::disk('local')->put('exports/customers/c/a.pdf', str_repeat('x', 2048));
    $mine = Export::factory()->forCustomer($customer)->status(ExportStatus::Completed)->create(['file_path' => 'exports/customers/c/a.pdf', 'file_size' => 2048]);
    $staff = Export::factory()->create(['type' => ExportType::Orders, 'status' => ExportStatus::Completed]);

    $component = Livewire::test(ListExports::class);

    expect(array_keys($component->instance()->getCachedTabs()))->toBe(['staff', 'customers']);

    $component->set('activeTab', 'customers')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$staff])
        ->assertSee('Пётр Покупателев')
        ->assertSee('2 КБ')
        ->assertSee('История заказов покупателя (PDF)');

    Livewire::test(ListExports::class)->set('activeTab', 'staff')
        ->assertCanSeeTableRecords([$staff])
        ->assertCanNotSeeTableRecords([$mine]);
});

it('lets an administrator delete the file of a customer export; the customer link then answers 410', function (): void {
    actingAs(User::factory()->admin()->create());
    $customer = makeCustomer();
    Storage::disk('local')->put('exports/customers/c/a.pdf', 'pdf');
    $export = Export::factory()->forCustomer($customer)->status(ExportStatus::Completed)->create(['file_path' => 'exports/customers/c/a.pdf', 'file_size' => 3]);

    Livewire::test(ListExports::class)->set('activeTab', 'customers')
        ->assertTableActionVisible('delete_file', $export)
        ->callAction(TestAction::make('delete_file')->table($export));

    expect(Storage::disk('local')->exists('exports/customers/c/a.pdf'))->toBeFalse()
        ->and($export->refresh()->file_path)->toBeNull()
        ->and($export->file_size)->toBeNull();

    auth()->logout();
    signInCustomer($this, $customer);
    $this->withoutVite();

    $this->get(route('customer.exports.download', $export->uuid))->assertStatus(410);
});

it('gives a manager no tabs and none of the customer exports', function (): void {
    $manager = User::factory()->manager()->create();
    actingAs($manager);
    $customerExport = Export::factory()->forCustomer(makeCustomer())->create();
    $own = Export::factory()->create(['user_id' => $manager->id, 'type' => ExportType::Catalog]);

    $component = Livewire::test(ListExports::class);

    expect($component->instance()->getCachedTabs())->toBe([]);
    $component->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$customerExport]);
    expect(ExportResource::isAdministrator())->toBeFalse();
});

it('does not offer the download of a customer export file in the admin table', function (): void {
    actingAs(User::factory()->admin()->create());
    $export = Export::factory()->forCustomer(makeCustomer())->status(ExportStatus::Completed)->create(['file_path' => 'exports/customers/c/a.pdf']);

    Livewire::test(ListExports::class)->set('activeTab', 'customers')->assertTableActionHidden('download', $export);
});

it('edits the flag «Может скачивать бланк/счёт» in the customer group form', function (): void {
    actingAs(User::factory()->admin()->create());
    $group = CustomerGroup::factory()->create();

    Livewire::test(EditCustomerGroup::class, ['record' => $group->getRouteKey()])
        ->assertFormFieldExists('allow_order_blank')
        ->fillForm(['allow_order_blank' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($group->refresh()->allow_order_blank)->toBeTrue();
});

it('edits the export settings with their validation (TZ 18.4)', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(ManageCustomerSettings::class)
        ->assertFormSet(['export_sync_xlsx_max' => 300, 'export_sync_pdf_max' => 200, 'export_sync_pdf_items_max' => 50, 'export_retention_hours' => 24])
        ->fillForm(['export_sync_pdf_max' => 120, 'export_retention_hours' => 48, 'export_email_link' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(CustomerSettings::class)->refresh();
    expect($settings->export_sync_pdf_max)->toBe(120)
        ->and($settings->export_retention_hours)->toBe(48)
        ->and($settings->export_email_link)->toBeTrue();

    Livewire::test(ManageCustomerSettings::class)
        ->fillForm(['export_sync_pdf_max' => 0, 'export_max_pending' => 99])
        ->call('save')
        ->assertHasFormErrors(['export_sync_pdf_max', 'export_max_pending']);
});

it('edits the bank requisites, the VAT note and the footer of the legal settings (TZ 18.4)', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(ManageLegalSettings::class)
        ->assertFormSet(['vat_note' => 'Без НДС', 'bank_name' => null])
        ->fillForm([
            'bank_name' => 'ПАО Тестбанк',
            'bik' => '044525225',
            'account' => '40702810000000000001',
            'corr_account' => '30101810400000000225',
            'vat_note' => 'НДС 20%',
            'invoice_footer' => 'Оплата в течение 3 дней',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $legal = app(LegalSettings::class)->refresh();
    expect($legal->bank_name)->toBe('ПАО Тестбанк')
        ->and($legal->hasBankRequisites())->toBeTrue()
        ->and($legal->vat_note)->toBe('НДС 20%')
        ->and($legal->invoice_footer)->toBe('Оплата в течение 3 дней');

    Livewire::test(ManageLegalSettings::class)
        ->fillForm(['bik' => '123', 'account' => 'abc'])
        ->call('save')
        ->assertHasFormErrors(['bik', 'account']);
});

it('has empty bank requisites by default: the document is a blank', function (): void {
    $legal = app(LegalSettings::class);

    expect($legal->hasBankRequisites())->toBeFalse()
        ->and($legal->vat_note)->toBe('Без НДС')
        ->and($legal->invoice_footer)->toBe('')
        ->and($legal->signature_image)->toBeNull();
});
