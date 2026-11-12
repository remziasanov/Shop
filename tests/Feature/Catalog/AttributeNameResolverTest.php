<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use App\Models\Attribute;
use App\Services\Catalog\AttributeNameResolver;
use App\Services\Catalog\AttributeService;
use App\Services\Catalog\AttributeStats;
use App\Services\Catalog\AttributeValueParser;
use App\Services\Catalog\ResolvedAttributeName;

/**
 * FL-16 rules (1)-(3), name normalization and FL-04 type inference.
 */
describe('normalization', function (): void {
    it('ignores case, repeated and non-breaking spaces and ё', function (): void {
        expect(AttributeNameResolver::normalize("  ЗЕЛЁНЫЙ \u{00A0} цвет "))->toBe('зеленый цвет')
            ->and(AttributeNameResolver::normalize('Ёлка'))->toBe('елка');
    });
});

describe('resolve (FL-16)', function (): void {
    it('rule 1: matches the signature "name, unit"', function (): void {
        $power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);

        $resolved = (new AttributeNameResolver)->resolve('мощность,  Вт');

        // "мощность,  Вт" collapses to "мощность, Вт".
        expect($resolved->rule)->toBe(ResolvedAttributeName::RULE_SIGNATURE)
            ->and($resolved->attribute?->is($power))->toBeTrue();
    });

    it('rule 2: matches an attribute named like the full header (v1 «Размер, см»)', function (): void {
        $v1 = Attribute::factory()->create(['name' => 'Размер, см']);
        $power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
        $resolver = new AttributeNameResolver;

        $a = $resolver->resolve('Размер, см');
        $b = $resolver->resolve('Мощность');

        expect($a->rule)->toBe(ResolvedAttributeName::RULE_SIGNATURE)
            ->and($a->attribute?->is($v1))->toBeTrue()
            // The name without the unit still finds the attribute (rule 2).
            ->and($b->rule)->toBe(ResolvedAttributeName::RULE_FULL_NAME)
            ->and($b->attribute?->is($power))->toBeTrue();
    });

    it('rule 2 wins over rule 3: an existing «Размер, см» is not split into «Размер» + «см»', function (): void {
        Attribute::factory()->create(['name' => 'Размер, см']);

        $resolved = (new AttributeNameResolver)->resolve('Размер, см');

        expect($resolved->attribute)->not->toBeNull()->and($resolved->unit)->toBeNull();
    });

    it('rule 1 wins over rule 2 when both exist', function (): void {
        $plain = Attribute::factory()->create(['name' => 'Вес, кг', 'sort_order' => 2]);
        $unit = Attribute::factory()->number('кг')->create(['name' => 'Вес', 'sort_order' => 1]);

        $resolved = (new AttributeNameResolver)->resolve('Вес, кг');

        expect($resolved->rule)->toBe(ResolvedAttributeName::RULE_SIGNATURE)
            ->and($resolved->attribute?->is($unit))->toBeTrue()
            ->and($resolved->attribute?->is($plain))->toBeFalse();
    });

    it('rule 3: parses the last part after ", " as the unit when nothing is found', function (): void {
        $resolver = new AttributeNameResolver;

        $size = $resolver->resolve('Размер, см');
        $long = $resolver->resolve('Состав, 100% хлопок и ещё что-то');
        $commaInName = $resolver->resolve('Вес, нетто, кг');

        expect($size->rule)->toBe(ResolvedAttributeName::RULE_PARSED_UNIT)
            ->and($size->attribute)->toBeNull()
            ->and($size->name)->toBe('Размер')
            ->and($size->unit)->toBe('см')
            // Suffix longer than 12 characters is not a unit: the full header is the name.
            ->and($long->rule)->toBe(ResolvedAttributeName::RULE_NEW_FULL)
            ->and($long->name)->toBe('Состав, 100% хлопок и ещё что-то')
            ->and($long->unit)->toBeNull()
            // Only the last part is the unit.
            ->and($commaInName->name)->toBe('Вес, нетто')
            ->and($commaInName->unit)->toBe('кг');
    });

    it('rule 3 needs a non-empty prefix and suffix', function (): void {
        expect(AttributeNameResolver::splitUnit(', см'))->toBeNull()
            ->and(AttributeNameResolver::splitUnit('Размер, '))->toBeNull()
            ->and(AttributeNameResolver::splitUnit('Цвет'))->toBeNull()
            ->and(AttributeNameResolver::splitUnit('Размер, 123456789012'))->toBe(['Размер', '123456789012'])
            ->and(AttributeNameResolver::splitUnit('Размер, 1234567890123'))->toBeNull();
    });

    it('uses a plain name for headers without a unit', function (): void {
        $resolved = (new AttributeNameResolver)->resolve('  Цвет ');

        expect($resolved->rule)->toBe(ResolvedAttributeName::RULE_NEW_FULL)
            ->and($resolved->name)->toBe('Цвет')
            ->and($resolved->header)->toBe('Цвет');
    });

    it('finds attributes created afterwards through remember()', function (): void {
        $resolver = new AttributeNameResolver;
        expect($resolver->resolve('Цвет')->attribute)->toBeNull();

        $resolver->remember(Attribute::factory()->create(['name' => 'Цвет']));

        expect($resolver->resolve('цвет')->attribute)->not->toBeNull();
    });
});

describe('creating by header', function (): void {
    it('creates a unit attribute from rule 3 for numeric samples, not filterable by default', function (): void {
        $service = app(AttributeService::class);

        $attribute = $service->findOrCreateByHeader('Мощность, Вт', ['500', '750', '1200']);

        expect($attribute->name)->toBe('Мощность')
            ->and($attribute->unit)->toBe('Вт')
            ->and($attribute->type)->toBe(AttributeType::Number)
            ->and($attribute->is_filterable)->toBeFalse()
            // The next call (export → import cycle) finds it by its signature: no duplicates.
            ->and($service->findOrCreateByHeader('Мощность, Вт', ['1'])->is($attribute))->toBeTrue()
            ->and(Attribute::query()->count())->toBe(1);
    });

    it('keeps the full header as the name when the values are not numbers', function (): void {
        $attribute = app(AttributeService::class)->findOrCreateByHeader('Размер, см', ['S', 'M', 'L']);

        expect($attribute->name)->toBe('Размер, см')->and($attribute->unit)->toBeNull()->and($attribute->type)->toBe(AttributeType::List);
    });

    it('takes the unit from the values when the header has none', function (): void {
        $attribute = app(AttributeService::class)->findOrCreateByHeader('Вес', ['2 кг', '3 кг', '10 кг']);

        expect($attribute->type)->toBe(AttributeType::Number)->and($attribute->unit)->toBe('кг')->and($attribute->name)->toBe('Вес');
    });

    it('returns null for an unknown header when creating is off', function (): void {
        expect(app(AttributeService::class)->findOrCreateByHeader('Цвет', [], create: false))->toBeNull();
    });
});

describe('value parser', function (): void {
    it('parses numbers with spaces, comma and unit suffix', function (): void {
        expect(AttributeValueParser::parseNumber('1 200,5 кг'))->toBe(['number' => '1200.5', 'suffix' => 'кг'])
            ->and(AttributeValueParser::parseNumber('-3'))->toBe(['number' => '-3', 'suffix' => ''])
            ->and(AttributeValueParser::parseNumber('12 м2'))->toBe(['number' => '12', 'suffix' => 'м2'])
            ->and(AttributeValueParser::parseNumber('абв'))->toBeNull()
            ->and(AttributeValueParser::parseNumber('5-7'))->toBeNull()
            ->and(AttributeValueParser::parseNumber('5 очень длинная единица'))->toBeNull();
    });

    it('splits list cells on semicolons', function (): void {
        expect(AttributeValueParser::splitList(' хлопок ;лён;; '))->toBe(['хлопок', 'лён']);
    });

    it('formats decimals without trailing zeros', function (): void {
        expect(AttributeValueParser::formatNumber('50.0000'))->toBe('50')
            ->and(AttributeValueParser::formatNumber('2.5000'))->toBe('2.5')
            ->and(AttributeValueParser::formatNumber('0.0000'))->toBe('0')
            ->and(AttributeValueParser::formatNumber(7.25))->toBe('7.25');
    });
});

describe('type inference (FL-04)', function (): void {
    it('infers boolean when every value is да/нет/yes/no/1/0', function (): void {
        $inference = AttributeStats::from(['да', 'Нет', 'YES', 'no', '1', '0'])->infer();

        expect($inference->type)->toBe(AttributeType::Boolean)->and($inference->filterable)->toBeTrue();
        expect(AttributeStats::from(['да', 'нет', 'возможно'])->infer()->type)->toBe(AttributeType::List);
    });

    it('infers number at 95% numeric values with a common unit', function (): void {
        $values = array_merge(array_fill(0, 19, '500 Вт'), ['750 Вт']);
        $inference = AttributeStats::from($values)->infer();

        expect($inference->type)->toBe(AttributeType::Number)
            ->and($inference->unit)->toBe('Вт')
            ->and($inference->numericShare)->toBe(1.0)
            ->and($inference->disputed)->toBeFalse();
    });

    it('accepts values without a suffix next to the common unit and a few bad values at 95%', function (): void {
        $values = array_merge(array_fill(0, 18, '10 кг'), ['12'], ['много']);
        $inference = AttributeStats::from(array_merge($values, array_fill(0, 80, '5 кг')))->infer();

        // 99 of 100 fit.
        expect($inference->type)->toBe(AttributeType::Number)->and($inference->unit)->toBe('кг')->and($inference->disputed)->toBeTrue();
    });

    it('falls back to a list below 95% or with mixed units', function (): void {
        $mixed = AttributeStats::from(['5 кг', '6 кг', '7 м', '8 м'])->infer();
        $few = AttributeStats::from(array_merge(array_fill(0, 9, '5'), ['быстро']))->infer();

        expect($mixed->type)->toBe(AttributeType::List)
            ->and($mixed->disputed)->toBeTrue()
            ->and($few->type)->toBe(AttributeType::List);
    });

    it('uses the unit known from the header', function (): void {
        $inference = AttributeStats::from(['5 см', '6', '7 см'])->infer('см');

        expect($inference->type)->toBe(AttributeType::Number)->and($inference->unit)->toBe('см');
        expect(AttributeStats::from(['5 мм', '6 мм'])->infer('см')->type)->toBe(AttributeType::List);
    });

    it('makes lists with more than 200 distinct values non-filterable', function (): void {
        $many = AttributeStats::from(array_map(fn (int $i): string => 'Значение '.$i, range(1, 201)))->infer();
        $edge = AttributeStats::from(array_map(fn (int $i): string => 'Значение '.$i, range(1, 200)))->infer();

        expect($many->filterable)->toBeFalse()->and($many->nonFilterableReason)->toBe('distinct')
            ->and($edge->filterable)->toBeTrue();
    });

    it('makes lists with values longer than 100 characters non-filterable', function (): void {
        $inference = AttributeStats::from([str_repeat('я', 101), 'коротко'])->infer();

        expect($inference->filterable)->toBeFalse()->and($inference->nonFilterableReason)->toBe('long');
        expect(AttributeStats::from([str_repeat('я', 100)])->infer()->filterable)->toBeTrue();
    });

    it('counts distinct values after splitting on semicolons, ignoring case', function (): void {
        $stats = AttributeStats::from(['Хлопок; лён', 'хлопок', 'ХЛОПОК;Шёлк']);

        expect(array_values($stats->distinctValues()))->toBe(['Хлопок', 'лён', 'Шёлк']);
    });
});
