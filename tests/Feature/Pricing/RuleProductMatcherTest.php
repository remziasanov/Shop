<?php

declare(strict_types=1);

use App\Enums\PriceRuleAction;
use App\Enums\PriceRuleScope;
use App\Enums\ProductFlag;
use App\Models\Category;
use App\Models\PriceRule;
use App\Models\Product;
use App\Services\Pricing\PriceRuleData;
use App\Services\Pricing\RuleProductMatcher;

beforeEach(function (): void {
    $this->matcher = app(RuleProductMatcher::class);
});

function ruleData(PriceRule $rule): PriceRuleData
{
    return PriceRuleData::fromModel($rule->load('targets'));
}

/**
 * @return list<int>
 */
function matchedIds(PriceRuleData $rule): array
{
    return app(RuleProductMatcher::class)->productIds($rule)->all();
}

it('matches every live product for the scope "all" and skips deleted ones', function (): void {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    Product::factory()->create()->delete();

    $rule = ruleData(PriceRule::factory()->create());

    expect(matchedIds($rule))->toBe([$a->id, $b->id])
        ->and($this->matcher->count($rule))->toBe(2);
});

it('matches categories with or without subcategories', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $other = Category::factory()->create();

    $inRoot = Product::factory()->inCategory($root)->create();
    $inChild = Product::factory()->inCategory($child)->create();
    Product::factory()->inCategory($other)->create();

    $with = ruleData(PriceRule::factory()->forCategories([$root], true)->create());
    $without = ruleData(PriceRule::factory()->forCategories([$root], false)->create());

    expect(matchedIds($with))->toBe([$inRoot->id, $inChild->id])
        ->and($this->matcher->count($with))->toBe(2)
        ->and(matchedIds($without))->toBe([$inRoot->id])
        ->and($this->matcher->count($without))->toBe(1);
});

it('matches specific products', function (): void {
    $a = Product::factory()->create();
    Product::factory()->create();
    $c = Product::factory()->create();

    $rule = ruleData(PriceRule::factory()->forProducts([$a, $c])->create());

    expect(matchedIds($rule))->toBe([$a->id, $c->id]);
});

it('matches brands case-insensitively, including Cyrillic', function (): void {
    $a = Product::factory()->create(['brand' => 'Аврора']);
    $b = Product::factory()->create(['brand' => 'АВРОРА ']);
    Product::factory()->create(['brand' => 'Север']);
    Product::factory()->create(['brand' => null]);

    $rule = ruleData(PriceRule::factory()->forBrands(['аврора'])->create());

    expect(matchedIds($rule))->toBe([$a->id, $b->id]);
});

it('matches flags', function (): void {
    $hit = Product::factory()->create(['is_featured' => true]);
    $new = Product::factory()->create(['is_new' => true]);
    Product::factory()->create();

    expect(matchedIds(ruleData(PriceRule::factory()->forFlags([ProductFlag::Featured])->create())))->toBe([$hit->id])
        ->and(matchedIds(ruleData(PriceRule::factory()->forFlags([ProductFlag::Featured, ProductFlag::New])->create())))->toBe([$hit->id, $new->id]);
});

it('matches nothing for a scope without targets', function (): void {
    Product::factory()->create();

    $rule = new PriceRuleData(0, 'x', PriceRuleAction::MarkupPercent, '10', PriceRuleScope::Flags);

    expect(matchedIds($rule))->toBe([])
        ->and($this->matcher->count($rule))->toBe(0);
});

it('returns the union of both versions of a changed rule', function (): void {
    $a = Product::factory()->create(['brand' => 'Север']);
    $b = Product::factory()->create(['brand' => 'Уют']);
    $c = Product::factory()->create(['brand' => 'Мастер']);

    $before = ruleData(PriceRule::factory()->forBrands(['Север', 'Уют'])->create());
    $after = ruleData(PriceRule::factory()->forBrands(['Уют', 'Мастер'])->create());
    $all = ruleData(PriceRule::factory()->create());

    expect($this->matcher->affectedIds($before, $after)->all())->toBe([$a->id, $b->id, $c->id])
        ->and($this->matcher->affectedIds(null, $after)->all())->toBe([$b->id, $c->id])
        ->and($this->matcher->affectedIds($before, null)->all())->toBe([$a->id, $b->id])
        ->and($this->matcher->affectedIds($before, $all)->all())->toBe([$a->id, $b->id, $c->id])
        ->and($this->matcher->affectedIds(null, null)->all())->toBe([]);
});
