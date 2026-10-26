<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SalesMode;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Realistic fake Russian-language catalog data (no real personal data).
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @var list<string> */
    private const TYPES = ['Кресло', 'Стол', 'Стул', 'Лампа', 'Диван', 'Шкаф', 'Полка', 'Тумба', 'Комод', 'Кровать', 'Зеркало', 'Пуф'];

    /** @var list<string> */
    private const ADJECTIVES = ['офисное', 'деревянный', 'мягкий', 'складной', 'настольная', 'угловой', 'навесная', 'компактная', 'классический', 'современный'];

    /** @var list<string> */
    private const BRANDS = ['Север', 'Домовой', 'Лесной двор', 'Аврора', 'Мастер', 'Уют', 'Нордик'];

    /**
     * Slug, search_text, final_price and display_old_price are filled by the observer.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->randomElement(self::TYPES).' '.$this->faker->randomElement(self::ADJECTIVES).' '.$this->faker->bothify('??-###');

        return [
            'category_id' => Category::factory(),
            'sku' => strtoupper($this->faker->unique()->bothify('??-######')),
            'name' => $name,
            'brand' => $this->faker->randomElement(self::BRANDS),
            'short_description' => $this->faker->sentence(10),
            'description' => '<p>'.$this->faker->paragraph(4).'</p>',
            'attributes' => [
                ['name' => 'Материал', 'value' => $this->faker->randomElement(['Дерево', 'Металл', 'Ткань', 'Пластик'])],
                ['name' => 'Цвет', 'value' => $this->faker->randomElement(['Белый', 'Чёрный', 'Серый', 'Бежевый'])],
            ],
            'base_price' => number_format($this->faker->numberBetween(100, 50000), 2, '.', ''),
            'cost_price' => null,
            'compare_at_price' => null,
            'fixed_price' => null,
            'price_on_request' => false,
            'stock_qty' => $this->faker->numberBetween(0, 50),
            'reserved_qty' => 0,
            'unit' => 'шт',
            'sales_mode' => null,
            'is_active' => true,
            'is_featured' => false,
            'is_new' => false,
            'sort_order' => 0,
            'meta_title' => null,
            'meta_description' => null,
        ];
    }

    public function inCategory(Category|int $category): static
    {
        return $this->state(fn (): array => ['category_id' => $category instanceof Category ? $category->id : $category]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function featured(): static
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }

    public function isNew(): static
    {
        return $this->state(fn (): array => ['is_new' => true]);
    }

    public function priceOnRequest(): static
    {
        return $this->state(fn (): array => ['price_on_request' => true]);
    }

    /**
     * Discounted display: compare_at_price is 25% above the base price.
     */
    public function onSale(): static
    {
        return $this->state(function (array $attributes): array {
            $base = (string) $attributes['base_price'];

            return ['compare_at_price' => bcmul($base, '1.25', 2)];
        });
    }

    public function salesMode(SalesMode $mode): static
    {
        return $this->state(fn (): array => ['sales_mode' => $mode]);
    }

    /**
     * Stock not tracked for this product.
     */
    public function untracked(): static
    {
        return $this->state(fn (): array => ['stock_qty' => null]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (): array => ['stock_qty' => 0]);
    }
}
