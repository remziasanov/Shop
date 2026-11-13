<?php

declare(strict_types=1);

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Enums\PromoBatchStatus;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoCodeBatch;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Services\Export\ExportManager;
use App\Services\Import\ImportReader;
use App\Services\Promo\PromoBatchGenerator;
use App\Services\Promo\PromoBatchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * @param  array<string, mixed>  $template
 */
function makeBatch(int $count = 20, int $length = 8, string $prefix = 'gift-', array $template = ['type' => 'percent', 'value' => '15.00']): PromoCodeBatch
{
    return app(PromoBatchService::class)->create('Офлайн-акция', $prefix, $count, $length, $template);
}

it('generates unique one-time codes with the template, alphabet and prefix (PM-15)', function (): void {
    $batch = makeBatch(1200, 8);   // QUEUE_CONNECTION=sync: the job has run

    $codes = PromoCode::query()->where('batch_id', $batch->id)->get();

    expect($batch->refresh()->status)->toBe(PromoBatchStatus::Done)->and($batch->generated)->toBe(1200)
        ->and($codes)->toHaveCount(1200)
        ->and($codes->pluck('code')->unique())->toHaveCount(1200)
        ->and($codes->every(fn (PromoCode $c): bool => preg_match('/^GIFT-['.PromoBatchGenerator::ALPHABET.']{8}$/', $c->code) === 1))->toBeTrue()
        ->and($codes->every(fn (PromoCode $c): bool => $c->usage_limit === 1 && $c->per_customer_limit === 1 && $c->usage_count === 0))->toBeTrue()
        ->and($codes->every(fn (PromoCode $c): bool => $c->value === '15.00' && $c->is_active))->toBeTrue();
});

it('gives the codes of a batch the default groups and the template targets (PM-04, PM-15)', function (): void {
    $category = Category::factory()->create();
    $batch = makeBatch(3, 6, '', ['type' => 'fixed', 'value' => '200.00', 'scope' => 'categories', 'targets' => [['type' => 'category', 'id' => $category->id]]]);

    foreach (PromoCode::query()->where('batch_id', $batch->id)->get() as $code) {
        expect($code->groups()->pluck('code')->all())->toEqualCanonicalizing(['guest', 'retail'])
            ->and($code->targets()->get())->toHaveCount(1)->and($code->targets()->first()->target_id)->toBe($category->id)
            ->and($code->scope->value)->toBe('categories')->and(strlen($code->code))->toBe(6);
    }
});

it('works in chunks of 500 and reports the progress on the batch (PM-15)', function (): void {
    $batch = PromoCodeBatch::factory()->create(['count' => 1200, 'code_length' => 8, 'prefix' => 'P-']);
    $generator = app(PromoBatchGenerator::class);

    expect($generator->chunk($batch))->toBe(700)->and($batch->refresh()->generated)->toBe(500)
        ->and($generator->chunk($batch))->toBe(200)->and($batch->refresh()->generated)->toBe(1000)
        ->and($generator->chunk($batch))->toBe(0)->and($batch->refresh()->generated)->toBe(1200)
        ->and($generator->chunk($batch))->toBe(0)->and(PromoCode::query()->where('batch_id', $batch->id)->count())->toBe(1200);
});

it('stops at the deadline and continues on the next run (PM-15, section 2: ≤ 40 s)', function (): void {
    $batch = PromoCodeBatch::factory()->create(['count' => 1200, 'code_length' => 8]);
    $generator = app(PromoBatchGenerator::class);

    expect($generator->run($batch, microtime(true) - 1))->toBeFalse()
        ->and($batch->refresh()->generated)->toBe(500)->and($batch->status)->toBe(PromoBatchStatus::Running)
        ->and($generator->run($batch))->toBeTrue()
        ->and($batch->refresh()->generated)->toBe(1200)->and($batch->status)->toBe(PromoBatchStatus::Done);
});

it('is safe to run again and completes an interrupted batch without duplicates (PM-15)', function (): void {
    $batch = makeBatch(50, 8);
    PromoCode::query()->where('batch_id', $batch->id)->orderByDesc('id')->limit(20)->get()->each->delete();

    expect(PromoCode::query()->where('batch_id', $batch->id)->count())->toBe(30);

    app(PromoBatchGenerator::class)->run($batch->refresh());

    expect(PromoCode::query()->where('batch_id', $batch->id)->count())->toBe(50)
        ->and(PromoCode::query()->where('batch_id', $batch->id)->distinct()->count('code'))->toBe(50);

    // A repeat of a finished batch changes nothing.
    app(PromoBatchGenerator::class)->run($batch->refresh());

    expect(PromoCode::query()->where('batch_id', $batch->id)->count())->toBe(50)
        ->and(DB::table('promo_code_groups')->count())->toBe(100);
});

it('never reuses a code that exists already, also from another batch (PM-15)', function (): void {
    makeBatch(300, 6, 'AA-');
    $second = makeBatch(300, 6, 'AA-');

    expect(PromoCode::query()->where('code', 'like', 'AA-%')->count())->toBe(600)
        ->and(PromoCode::query()->where('batch_id', $second->id)->count())->toBe(300);
});

it('refuses parameters out of range (PM-15)', function (): void {
    $service = app(PromoBatchService::class);

    expect(fn () => $service->create('x', 'A-', 5001, 8, []))->toThrow(InvalidArgumentException::class, 'от 1 до 5000')
        ->and(fn () => $service->create('x', 'A-', 0, 8, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->create('x', 'A-', 10, 5, []))->toThrow(InvalidArgumentException::class, 'от 6 до 12')
        ->and(fn () => $service->create('x', 'A-', 10, 13, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->create('x', 'bad prefix!', 10, 8, []))->toThrow(InvalidArgumentException::class)
        ->and(PromoCodeBatch::query()->count())->toBe(0);

});

it('allows the largest batch of 5 000 codes (PM-15)', function (): void {
    $batch = makeBatch(5000, 12, 'MAX-');

    expect($batch->refresh()->status)->toBe(PromoBatchStatus::Done)->and(PromoCode::query()->where('batch_id', $batch->id)->count())->toBe(5000);
});

it('carries a changed template over to the unused codes only (PM-15)', function (): void {
    $batch = makeBatch(10, 8);
    $used = PromoCode::query()->where('batch_id', $batch->id)->orderBy('id')->first();
    $used->forceFill(['usage_count' => 1])->save();
    $wholesale = CustomerGroup::factory()->create();

    $updated = app(PromoBatchService::class)->applyTemplate($batch, [
        'type' => 'percent', 'value' => '25.00', 'min_order_amount' => '1000.00', 'group_ids' => [$wholesale->id], 'only_registered' => true,
    ]);

    expect($updated)->toBe(9)->and($batch->refresh()->template['value'])->toBe('25.00');

    $fresh = PromoCode::query()->where('batch_id', $batch->id)->where('id', '!=', $used->id)->first();

    expect($fresh->value)->toBe('25.00')->and($fresh->min_order_amount)->toBe('1000.00')->and($fresh->only_registered)->toBeTrue()
        ->and($fresh->groups()->pluck('customer_groups.id')->all())->toBe([$wholesale->id])
        ->and($used->refresh()->value)->toBe('15.00')->and($used->only_registered)->toBeFalse()
        ->and($used->groups()->count())->toBe(2)
        // The per-code limit of 1 stays whatever the template says.
        ->and($fresh->usage_limit)->toBe(1);
});

it('leaves individually deactivated codes inactive and skips a code redeemed meanwhile when applying the template (PM-15)', function (): void {
    $batch = makeBatch(4, 8);
    [$off, $redeemed] = PromoCode::query()->where('batch_id', $batch->id)->orderBy('id')->get()->all();
    $off->forceFill(['is_active' => false])->save();
    // Redeemed with a redemption row although the counter was not bumped (e.g. read before the claim committed).
    PromoRedemption::factory()->create(['promo_code_id' => $redeemed->id, 'order_id' => Order::factory()->create()->id]);

    $updated = app(PromoBatchService::class)->applyTemplate($batch, ['type' => 'percent', 'value' => '30.00', 'is_active' => true]);

    expect($updated)->toBe(3)
        ->and($off->refresh()->value)->toBe('30.00')->and($off->is_active)->toBeFalse()
        ->and($redeemed->refresh()->value)->toBe('15.00')
        ->and(PromoCode::query()->where('batch_id', $batch->id)->where('is_active', true)->count())->toBe(3);
});

it('exports the codes of a batch with status, date and order number through the exports journal (PM-16, EX-03)', function (): void {
    Storage::fake('local');
    $batch = makeBatch(3, 6, 'EXP-');
    [$a, $b, $c] = PromoCode::query()->where('batch_id', $batch->id)->orderBy('id')->get()->all();
    $order = Order::factory()->create(['number' => 'Z-00077']);
    PromoRedemption::factory()->create(['promo_code_id' => $a->id, 'order_id' => $order->id, 'created_at' => '2026-03-05 10:30:00']);
    $a->forceFill(['usage_count' => 1])->save();
    $released = Order::factory()->create();
    PromoRedemption::factory()->released()->create(['promo_code_id' => $b->id, 'order_id' => $released->id]);

    $export = app(PromoBatchService::class)->requestExport($batch, User::factory()->admin()->create());
    $rows = array_values(iterator_to_array((new ImportReader)->open(Storage::disk('local')->path((string) $export->file_path), 'Коды партии')));

    expect($export->type)->toBe(ExportType::PromoBatch)->and($export->status)->toBe(ExportStatus::Completed)->and($export->rows)->toBe(3)
        ->and($rows[0])->toBe(['Код', 'Статус', 'Использован', 'Дата использования', 'Номер заказа'])
        ->and($rows[1][0])->toBe($a->code)->and($rows[1][1])->toBe('Использован')->and($rows[1][2])->toBe('Да')
        ->and($rows[1][3])->toBe('05.03.2026 10:30')->and($rows[1][4])->toBe('Z-00077')
        ->and($rows[2][1])->toBe('Возвращён')->and($rows[2][2])->toBe('Нет')
        ->and($rows[3][1])->toBe('Не использован')->and($rows[3][4] ?? null)->toBeNull()
        ->and(app(ExportManager::class)->fileExists($export))->toBeTrue();
});

it('needs the generate right to export a batch (PM-16, RB-11)', function (): void {
    $batch = makeBatch(2, 6);
    $viewer = staffWith(['promo_codes.view']);

    expect(fn () => app(PromoBatchService::class)->requestExport($batch, $viewer))->toThrow(AuthorizationException::class);
});
