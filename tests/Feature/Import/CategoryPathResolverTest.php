<?php

declare(strict_types=1);

use App\Exceptions\ImportRowException;
use App\Models\Category;
use App\Services\Catalog\CategoryTree;
use App\Services\Import\CategoryPathResolver;

it('creates missing levels of a path and returns the last category', function () {
    $id = (new CategoryPathResolver)->resolve(['Электроника', 'Телефоны', 'Смартфоны'], true);

    $category = Category::findOrFail($id);

    expect($category->name)->toBe('Смартфоны')
        ->and($category->depth)->toBe(3)
        ->and($category->ancestors()->pluck('name')->all())->toBe(['Электроника', 'Телефоны']);
});

it('matches names case-insensitively with е for ё and does not duplicate categories', function () {
    $existing = Category::factory()->create(['name' => 'Зелёные']);
    $child = Category::factory()->childOf($existing)->create(['name' => 'Ёлки']);

    $resolver = new CategoryPathResolver;

    expect($resolver->resolve(['зелёные', 'елки'], true))->toBe($child->id)
        ->and($resolver->resolve(['ЗЕЛЕНЫЕ'], true))->toBe($existing->id)
        ->and(Category::count())->toBe(2)
        ->and($resolver->createdCount())->toBe(0);
});

it('returns null for a missing level when creation is not allowed', function () {
    Category::factory()->create(['name' => 'Мебель']);

    expect((new CategoryPathResolver)->resolve(['Мебель', 'Столы'], false))->toBeNull()
        ->and(Category::count())->toBe(1);
});

it('reuses levels created earlier in the same run', function () {
    $resolver = new CategoryPathResolver;
    $first = $resolver->resolve(['A', 'B'], true);
    $second = $resolver->resolve(['a', 'b'], true);
    $third = $resolver->resolve(['A', 'C'], true);

    expect($second)->toBe($first)
        ->and($third)->not->toBe($first)
        ->and($resolver->createdCount())->toBe(3);
});

it('refuses a path deeper than the tree allows', function () {
    (new CategoryPathResolver)->resolve(['1', '2', '3', '4', '5'], true);
})->throws(ImportRowException::class);

it('keeps the cached tree in sync after creating categories', function () {
    $tree = app(CategoryTree::class);
    expect($tree->nodes())->toBeEmpty();

    $id = (new CategoryPathResolver)->resolve(['Новая', 'Вложенная'], true);

    expect($tree->node($id))->not->toBeNull();
});
