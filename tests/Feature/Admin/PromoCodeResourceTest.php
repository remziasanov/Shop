<?php

declare(strict_types=1);

use App\Enums\PromoBatchStatus;
use App\Enums\PromoScope;
use App\Enums\PromoType;
use App\Filament\Resources\PromoCodeBatches\Pages\ListPromoCodeBatches;
use App\Filament\Resources\PromoCodeBatches\Pages\ViewPromoCodeBatch;
use App\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Resources\PromoCodes\Pages\EditPromoCode;
use App\Filament\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Filament\Resources\PromoCodes\Pages\ViewPromoCode;
use App\Jobs\GeneratePromoCodeBatch;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Export;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoCodeBatch;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Services\Promo\PromoBatchService;
use App\Services\Users\PermissionRegistry;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function promoCodeForm(array $overrides = []): array
{
    return array_merge([
        'code' => 'spring10',
        'name' => 'Весна',
        'is_active' => true,
        'type' => PromoType::Percent->value,
        'value' => '10',
        'scope' => PromoScope::All->value,
        'group_ids' => PromoCode::defaultGroupIds(),
    ], $overrides);
}

function promoAdmin(): User
{
    return User::factory()->admin()->create();
}

// ------------------------------------------------------------------ permissions (22.4, 25.4)

it('registers the promo permissions and gives managers the view right only (PM-21, 22.4)', function (): void {
    expect(PermissionRegistry::all())->toContain('promo_codes.view', 'promo_codes.generate', 'promo_links.delete')
        ->and(PermissionRegistry::managerDefaults())->toContain('promo_codes.view', 'promo_links.view')
        ->not->toContain('promo_codes.create', 'promo_codes.generate', 'promo_links.update');
});

it('denies the promo pages without the view right and lets a manager only look', function (): void {
    actingAs(staffWith(['dashboard.view']));
    get('/admin/promo-codes')->assertForbidden();
    get('/admin/promo-links')->assertForbidden();
    get('/admin/promo-code-batches')->assertForbidden();

    $code = PromoCode::factory()->create();
    actingAs(User::factory()->manager()->create());

    Livewire::test(ListPromoCodes::class)->assertOk()->assertCanSeeTableRecords([$code])
        ->assertActionHidden('generate')
        ->assertActionHidden('create');
    get('/admin/promo-codes/create')->assertForbidden();
    get('/admin/promo-code-batches')->assertForbidden();
    Livewire::test(ViewPromoCode::class, ['record' => $code->id])->assertOk()->assertActionHidden('edit');
});

it('shows the generate action and the batches with the generate right (PM-15)', function (): void {
    actingAs(staffWith(['promo_codes.view', 'promo_codes.generate']));

    Livewire::test(ListPromoCodes::class)->assertActionVisible('generate');
    Livewire::test(ListPromoCodeBatches::class)->assertOk()->assertActionVisible('generate');
});

it('hides the generate action from a user without the generate right', function (): void {
    Queue::fake();
    actingAs(staffWith(['promo_codes.view', 'promo_codes.create']));

    Livewire::test(ListPromoCodes::class)->assertActionHidden('generate');

    expect(PromoCodeBatch::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

// ------------------------------------------------------------------ create / edit form (PM-01…PM-04)

it('stores the code upper-cased with the default groups (PM-01, PM-04)', function (): void {
    actingAs(promoAdmin());

    $component = Livewire::test(CreatePromoCode::class)
        ->assertFormSet(['group_ids' => PromoCode::defaultGroupIds(), 'scope' => 'all', 'is_active' => true]);

    $component->fillForm(promoCodeForm(['code' => ' spring10 ']))->call('create')->assertHasNoFormErrors();

    $code = PromoCode::query()->firstOrFail();

    expect($code->code)->toBe('SPRING10')
        ->and($code->type)->toBe(PromoType::Percent)
        ->and($code->value)->toBe('10.00')
        ->and($code->groups->pluck('code')->sort()->values()->all())->toBe([CustomerGroup::GUEST, CustomerGroup::RETAIL])
        ->and($code->created_by)->not->toBeNull();
});

it('keeps the code unique without regard to case and validates its format (PM-01)', function (): void {
    actingAs(promoAdmin());
    PromoCode::factory()->create(['code' => 'SPRING10']);

    Livewire::test(CreatePromoCode::class)
        ->fillForm(promoCodeForm(['code' => 'Spring10']))->call('create')
        ->assertHasFormErrors(['code']);

    foreach (['abc', 'bad code', 'кириллица', str_repeat('A', 41)] as $bad) {
        Livewire::test(CreatePromoCode::class)
            ->fillForm(promoCodeForm(['code' => $bad]))->call('create')
            ->assertHasFormErrors(['code']);
    }

    expect(PromoCode::query()->count())->toBe(1);
});

it('validates the value per type: percent 1–100, fixed above 0, none for free delivery (PM-02)', function (): void {
    actingAs(promoAdmin());

    foreach (['0', '0,5', '101', 'abc', '-5', ''] as $i => $bad) {
        Livewire::test(CreatePromoCode::class)
            ->fillForm(promoCodeForm(['code' => 'PCT'.$i, 'value' => $bad]))->call('create')
            ->assertHasFormErrors(['value']);
    }

    foreach (['0', '-1', 'x'] as $i => $bad) {
        Livewire::test(CreatePromoCode::class)
            ->fillForm(promoCodeForm(['code' => 'FIX'.$i, 'type' => 'fixed', 'value' => $bad]))->call('create')
            ->assertHasFormErrors(['value']);
    }

    Livewire::test(CreatePromoCode::class)->fillForm(promoCodeForm(['code' => 'PCT100', 'value' => '100']))->call('create')->assertHasNoFormErrors();
    Livewire::test(CreatePromoCode::class)->fillForm(promoCodeForm(['code' => 'FIX500', 'type' => 'fixed', 'value' => '500,50']))->call('create')->assertHasNoFormErrors();
    Livewire::test(CreatePromoCode::class)->fillForm(promoCodeForm(['code' => 'FREEDELIVERY', 'type' => 'free_delivery', 'value' => null]))->call('create')->assertHasNoFormErrors();

    expect(PromoCode::query()->where('code', 'FIX500')->value('value'))->toBe('500.50')
        ->and(PromoCode::query()->where('code', 'FREEDELIVERY')->value('value'))->toBeNull()
        ->and(PromoCode::query()->count())->toBe(3);
});

it('stores the targets of the chosen scope only (PM-03)', function (): void {
    actingAs(promoAdmin());
    $shoes = Category::factory()->create();

    Livewire::test(CreatePromoCode::class)
        ->fillForm(promoCodeForm(['code' => 'SHOES', 'scope' => 'categories', 'category_ids' => [$shoes->id], 'brands' => ['Nike']]))
        ->call('create')->assertHasNoFormErrors();

    $code = PromoCode::query()->firstOrFail()->load('targets');

    expect($code->scope)->toBe(PromoScope::Categories)
        ->and($code->include_subcategories)->toBeTrue()
        ->and($code->targets)->toHaveCount(1)
        ->and($code->targets->first()->target_id)->toBe($shoes->id);

    Livewire::test(CreatePromoCode::class)
        ->fillForm(promoCodeForm(['code' => 'NOCATS', 'scope' => 'categories', 'category_ids' => []]))
        ->call('create')->assertHasFormErrors(['category_ids']);
});

it('requires at least one customer group and an end after the start (PM-04)', function (): void {
    actingAs(promoAdmin());

    Livewire::test(CreatePromoCode::class)
        ->fillForm(promoCodeForm(['group_ids' => []]))->call('create')
        ->assertHasFormErrors(['group_ids']);

    Livewire::test(CreatePromoCode::class)
        ->fillForm(promoCodeForm(['starts_at' => '2026-06-10 10:00:00', 'ends_at' => '2026-06-01 10:00:00']))->call('create')
        ->assertHasFormErrors(['ends_at']);
});

it('fills the edit form from the stored code and saves changes (PM-01)', function (): void {
    actingAs(promoAdmin());
    $code = PromoCode::factory()->percent(15)->create(['code' => 'EDITME', 'name' => 'Старое']);

    Livewire::test(EditPromoCode::class, ['record' => $code->id])
        ->assertFormSet(['code' => 'EDITME', 'name' => 'Старое', 'value' => '15', 'type' => 'percent'])
        ->fillForm(['name' => 'Новое', 'value' => '20'])
        ->call('save')->assertHasNoFormErrors();

    expect($code->fresh()->name)->toBe('Новое')->and($code->fresh()->value)->toBe('20.00');
});

it('does not change the text of a code that has redemptions (PM-19)', function (): void {
    actingAs(promoAdmin());
    $code = PromoCode::factory()->create(['code' => 'USEDCODE']);
    PromoRedemption::factory()->create(['promo_code_id' => $code->id]);

    Livewire::test(EditPromoCode::class, ['record' => $code->id])
        ->assertFormFieldDisabled('code')
        ->fillForm(['code' => 'HACKED', 'name' => 'Переименован'])
        ->call('save')->assertHasNoFormErrors();

    expect($code->fresh()->code)->toBe('USEDCODE')->and($code->fresh()->name)->toBe('Переименован');
});

it('asks for confirmation before the discount of a used code changes (22.8)', function (): void {
    actingAs(promoAdmin());
    $code = PromoCode::factory()->percent(10)->create();
    PromoRedemption::factory()->create(['promo_code_id' => $code->id]);

    Livewire::test(EditPromoCode::class, ['record' => $code->id])
        ->fillForm(['value' => '30'])
        ->call('save')
        ->assertNotified(__('promo_admin.codes.actions.discount_change_blocked'));

    expect($code->fresh()->value)->toBe('10.00');

    Livewire::test(EditPromoCode::class, ['record' => $code->id])
        ->fillForm(['value' => '30', 'confirm_discount_change' => true])
        ->call('save')->assertHasNoFormErrors();

    expect($code->fresh()->value)->toBe('30.00');
});

// ------------------------------------------------------------------ list actions

it('lists codes with filters (active, type, batch, expired) and shows uses / limit', function (): void {
    actingAs(promoAdmin());
    $batch = PromoCodeBatch::factory()->create();
    $active = PromoCode::factory()->percent(10)->create(['code' => 'ACTIVE1', 'usage_limit' => 100, 'usage_count' => 3]);
    $inactive = PromoCode::factory()->inactive()->create(['code' => 'INACTIVE1']);
    $fixed = PromoCode::factory()->fixed('300')->create(['code' => 'FIXED1']);
    $expired = PromoCode::factory()->create(['code' => 'EXPIRED1', 'ends_at' => now()->subDay()]);
    $inBatch = PromoCode::factory()->create(['code' => 'BATCHED1', 'batch_id' => $batch->id]);

    Livewire::test(ListPromoCodes::class)
        ->assertCanSeeTableRecords([$active, $inactive, $fixed, $expired, $inBatch])
        ->assertSee('3 / 100')
        ->filterTable('is_active', false)->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active])
        ->resetTableFilters()
        ->filterTable('type', 'fixed')->assertCanSeeTableRecords([$fixed])->assertCanNotSeeTableRecords([$active])
        ->resetTableFilters()
        ->filterTable('batch_id', $batch->id)->assertCanSeeTableRecords([$inBatch])->assertCanNotSeeTableRecords([$active])
        ->resetTableFilters()
        ->filterTable('expired')->assertCanSeeTableRecords([$expired])->assertCanNotSeeTableRecords([$active, $inactive]);
});

it('duplicates a code under a new text and deactivates a code (22.4)', function (): void {
    actingAs(promoAdmin());
    $code = PromoCode::factory()->percent(12)->create(['code' => 'ORIGINAL', 'name' => 'Оригинал', 'usage_count' => 4]);

    Livewire::test(ListPromoCodes::class)
        ->callAction(TestAction::make('duplicate')->table($code), ['code' => 'original'])
        ->assertHasActionErrors(['code']);

    Livewire::test(ListPromoCodes::class)
        ->callAction(TestAction::make('duplicate')->table($code), ['code' => 'copy-1'])
        ->assertHasNoActionErrors();

    $copy = PromoCode::query()->where('code', 'COPY-1')->firstOrFail();
    expect($copy->value)->toBe('12.00')->and($copy->usage_count)->toBe(0)->and($copy->name)->toBe('Оригинал');

    Livewire::test(ListPromoCodes::class)->callAction(TestAction::make('deactivate')->table($code));
    expect($code->fresh()->is_active)->toBeFalse();
    Livewire::test(ListPromoCodes::class)->assertActionHidden(TestAction::make('deactivate')->table($code));
});

it('forbids deleting a code with redemptions and deletes an unused one (PM-19)', function (): void {
    actingAs(promoAdmin());
    $used = PromoCode::factory()->create();
    PromoRedemption::factory()->create(['promo_code_id' => $used->id]);
    $unused = PromoCode::factory()->create();

    Livewire::test(ListPromoCodes::class)->assertActionHidden(TestAction::make('delete')->table($used));
    Livewire::test(ListPromoCodes::class)->callAction(TestAction::make('delete')->table($unused));

    expect(PromoCode::query()->whereKey($used->id)->exists())->toBeTrue()
        ->and(PromoCode::query()->whereKey($unused->id)->exists())->toBeFalse();
});

it('keeps the delete right separate from update (22.4)', function (): void {
    $code = PromoCode::factory()->create();
    actingAs(staffWith(['promo_codes.view', 'promo_codes.update']));

    Livewire::test(ListPromoCodes::class)->assertActionHidden(TestAction::make('delete')->table($code))
        ->assertActionHidden('create');

    actingAs(staffWith(['promo_codes.view', 'promo_codes.delete']));
    Livewire::test(ListPromoCodes::class)->assertActionVisible(TestAction::make('delete')->table($code));
});

// ------------------------------------------------------------------ view page and stats (PM-17)

it('renders the card with the statistics of a code (PM-17)', function (): void {
    actingAs(promoAdmin());
    $code = PromoCode::factory()->percent(10)->create(['code' => 'STATS10']);
    $order = Order::factory()->create(['promo_code_id' => $code->id, 'promo_code' => 'STATS10', 'total' => '900.00']);
    PromoRedemption::factory()->create(['promo_code_id' => $code->id, 'order_id' => $order->id, 'discount_amount' => '100.00']);

    Livewire::test(ViewPromoCode::class, ['record' => $code->id])
        ->assertOk()
        ->assertSee('STATS10')
        ->assertSee(__('promo_admin.codes.view.recent'))
        ->assertSee($order->number)
        ->assertSee(__('promo_admin.codes.view.stats_discount'));
});

it('renders the card of a code without any use', function (): void {
    actingAs(promoAdmin());
    $code = PromoCode::factory()->create();

    Livewire::test(ViewPromoCode::class, ['record' => $code->id])->assertOk()
        ->assertSee(__('promo_admin.codes.view.per_day_empty'))
        ->assertSee(__('promo_admin.codes.view.recent_empty'));
});

// ------------------------------------------------------------------ batches (PM-15, PM-16)

it('creates a batch and dispatches the generation job from the generate action (PM-15)', function (): void {
    Queue::fake();
    actingAs(promoAdmin());

    Livewire::test(ListPromoCodes::class)
        ->callAction('generate', [
            'name' => 'Офлайн',
            'prefix' => 'gift-',
            'count' => 50,
            'code_length' => 8,
            'type' => 'fixed',
            'value' => '300',
            'scope' => 'all',
            'min_order_amount' => '1000',
            'is_active' => true,
            'group_ids' => PromoCode::defaultGroupIds(),
        ])
        ->assertHasNoActionErrors();

    $batch = PromoCodeBatch::query()->firstOrFail();

    expect($batch->name)->toBe('Офлайн')
        ->and($batch->prefix)->toBe('GIFT-')
        ->and($batch->count)->toBe(50)
        ->and($batch->code_length)->toBe(8)
        ->and($batch->status)->toBe(PromoBatchStatus::Queued)
        ->and($batch->template['type'])->toBe('fixed')
        ->and($batch->template['value'])->toBe('300.00')
        ->and($batch->template['min_order_amount'])->toBe('1000.00')
        ->and($batch->created_by)->not->toBeNull();

    Queue::assertPushed(GeneratePromoCodeBatch::class, fn (GeneratePromoCodeBatch $job): bool => $job->batchId === $batch->id);
});

it('validates the batch parameters (count up to 5 000, length 6–12, prefix)', function (): void {
    Queue::fake();
    actingAs(promoAdmin());
    $valid = ['name' => 'B', 'prefix' => 'A', 'count' => 10, 'code_length' => 8, 'type' => 'percent', 'value' => '5', 'scope' => 'all', 'group_ids' => PromoCode::defaultGroupIds()];

    foreach ([['count' => 5001], ['count' => 0], ['code_length' => 5], ['code_length' => 13], ['prefix' => 'bad prefix!'], ['name' => '']] as $override) {
        Livewire::test(ListPromoCodes::class)
            ->callAction('generate', array_merge($valid, $override))
            ->assertHasActionErrors();
    }

    expect(PromoCodeBatch::query()->count())->toBe(0);

    Livewire::test(ListPromoCodes::class)->callAction('generate', array_merge($valid, ['count' => 5000]))->assertHasNoActionErrors();
    expect(PromoCodeBatch::query()->count())->toBe(1);
});

it('lists batches with progress and status and opens a batch card (PM-15)', function (): void {
    actingAs(promoAdmin());
    $batch = PromoCodeBatch::factory()->create(['name' => 'Лето', 'count' => 500, 'generated' => 250, 'status' => PromoBatchStatus::Running]);

    Livewire::test(ListPromoCodeBatches::class)->assertCanSeeTableRecords([$batch])
        ->assertSee('250 из 500')
        ->assertSee(PromoBatchStatus::Running->label());

    Livewire::test(ViewPromoCodeBatch::class, ['record' => $batch->id])->assertOk()->assertSee('Лето');
});

it('applies a changed template to the unused codes of a batch only (PM-15)', function (): void {
    Queue::fake();
    actingAs(promoAdmin());
    $batch = app(PromoBatchService::class)->create('Партия', 'T-', 3, 6, ['type' => 'percent', 'value' => '10.00']);
    $unused = PromoCode::factory()->percent(10)->count(2)->create(['batch_id' => $batch->id, 'usage_count' => 0]);
    $used = PromoCode::factory()->percent(10)->create(['batch_id' => $batch->id, 'usage_count' => 1]);

    Livewire::test(ListPromoCodeBatches::class)
        ->mountAction(TestAction::make('applyTemplate')->table($batch))
        ->assertSchemaStateSet(['type' => 'percent', 'value' => '10'])
        ->setActionData([
            'type' => 'percent',
            'value' => '25',
            'scope' => 'all',
            'is_active' => true,
            'group_ids' => PromoCode::defaultGroupIds(),
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified(__('promo_admin.batches.actions.template_applied', ['count' => 2]));

    expect($unused->every(fn (PromoCode $c): bool => $c->fresh()->value === '25.00'))->toBeTrue()
        ->and($used->fresh()->value)->toBe('10.00')
        ->and($batch->fresh()->template['value'])->toBe('25.00');
});

it('requests the export of a batch into the export journal (PM-16)', function (): void {
    Queue::fake();
    $user = promoAdmin();
    actingAs($user);
    $batch = PromoCodeBatch::factory()->create(['generated' => 5, 'status' => PromoBatchStatus::Done]);

    Livewire::test(ListPromoCodeBatches::class)
        ->callAction(TestAction::make('export')->table($batch))
        ->assertNotified(__('promo_admin.batches.actions.export_requested'));

    $export = Export::query()->firstOrFail();

    expect($export->type->value)->toBe('promo_batch')
        ->and($export->params['batch_id'])->toBe($batch->id)
        ->and($export->user_id)->toBe($user->id);
});

it('hides the batch export without the generate right (PM-16)', function (): void {
    $batch = PromoCodeBatch::factory()->create(['generated' => 5, 'status' => PromoBatchStatus::Done]);
    actingAs(staffWith(['promo_codes.view', 'promo_codes.create', 'promo_codes.update', 'exports.view', 'exports.create']));

    get('/admin/promo-code-batches/'.$batch->id)->assertForbidden();
    Livewire::test(ListPromoCodes::class)->assertActionHidden('generate');
});
