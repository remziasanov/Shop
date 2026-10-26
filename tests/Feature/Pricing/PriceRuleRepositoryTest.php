<?php

declare(strict_types=1);

use App\Enums\PriceRuleAction;
use App\Enums\PriceRuleScope;
use App\Enums\ProductFlag;
use App\Models\Category;
use App\Models\PriceRule;
use App\Models\PriceRuleTarget;
use App\Services\Pricing\PriceRuleRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->repository = app(PriceRuleRepository::class);
});

it('loads active rules with their targets in two queries', function (): void {
    $category = Category::factory()->create();
    PriceRule::factory()->forCategories([$category], false)->markupPercent(5)->create(['name' => 'Категория']);
    PriceRule::factory()->forBrands([' Север ', 'Аврора'])->create();
    PriceRule::factory()->forFlags([ProductFlag::New])->create();
    PriceRule::factory()->inactive()->create();
    $this->repository->flush();
    Cache::flush();

    DB::enableQueryLog();
    $rules = $this->repository->activeRules();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(2)
        ->and($rules)->toHaveCount(3)
        ->and($rules[0]->scope)->toBe(PriceRuleScope::Categories)
        ->and($rules[0]->action)->toBe(PriceRuleAction::MarkupPercent)
        ->and($rules[0]->categoryIds)->toBe([$category->id])
        ->and($rules[0]->includeSubcategories)->toBeFalse()
        ->and($rules[0]->value)->toBe('5.0000')
        ->and($rules[1]->brands)->toBe(['север', 'аврора'])
        ->and($rules[2]->flags)->toBe([ProductFlag::New]);
});

it('serves repeated calls from memory and a fresh process from the cache', function (): void {
    PriceRule::factory()->create();
    $this->repository->flush();

    $this->repository->activeRules();

    DB::enableQueryLog();
    $this->repository->activeRules();
    $this->repository->forgetMemo();
    $again = $this->repository->activeRules();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($again)->toHaveCount(1)
        ->and(Cache::has(PriceRuleRepository::CACHE_KEY))->toBeTrue();
});

it('round-trips periods through the cache', function (): void {
    PriceRule::factory()->between('2026-06-01 10:00:00', '2026-06-02 10:00:00')->create();
    $this->repository->flush();
    $this->repository->activeRules();
    $this->repository->forgetMemo();

    $rule = $this->repository->activeRules()[0];

    expect($rule->startsAt?->equalTo(CarbonImmutable::parse('2026-06-01 10:00:00')))->toBeTrue()
        ->and($rule->endsAt?->equalTo(CarbonImmutable::parse('2026-06-02 10:00:00')))->toBeTrue();
});

it('flushes when a rule is created, updated, deactivated or deleted', function (): void {
    expect($this->repository->activeRules())->toBe([]);

    $rule = PriceRule::factory()->create();
    expect($this->repository->activeRules())->toHaveCount(1);

    $rule->update(['value' => '15']);
    expect($this->repository->activeRules()[0]->value)->toBe('15.0000');

    $rule->update(['is_active' => false]);
    expect($this->repository->activeRules())->toHaveCount(0);

    $rule->update(['is_active' => true]);
    expect($this->repository->activeRules())->toHaveCount(1);

    $rule->delete();
    expect($this->repository->activeRules())->toHaveCount(0);
});

it('flushes when a target is added or removed', function (): void {
    $rule = PriceRule::factory()->forBrands(['Север'])->create();
    expect($this->repository->activeRules()[0]->brands)->toBe(['север']);

    $target = PriceRuleTarget::factory()->create(['price_rule_id' => $rule->id, 'target_value' => 'Аврора']);
    expect($this->repository->activeRules()[0]->brands)->toBe(['север', 'аврора']);

    $target->delete();
    expect($this->repository->activeRules()[0]->brands)->toBe(['север']);
});

it('exposes target helpers and scopes on the model', function (): void {
    $category = Category::factory()->create();
    $rule = PriceRule::factory()->forCategories([$category])->create();
    $inactive = PriceRule::factory()->inactive()->create();
    $expired = PriceRule::factory()->between(null, now()->subDay())->create();
    $future = PriceRule::factory()->between(now()->addDay(), null)->create();

    expect($rule->fresh()->categoryIds())->toBe([$category->id])
        ->and($rule->fresh()->productIds())->toBe([])
        ->and(PriceRule::query()->active()->pluck('id')->all())->toContain($rule->id, $expired->id, $future->id)->not->toContain($inactive->id)
        ->and(PriceRule::query()->inEffect(now())->pluck('id')->all())->toBe([$rule->id]);
});
