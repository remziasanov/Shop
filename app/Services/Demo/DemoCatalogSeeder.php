<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\SalesMode;
use App\Models\Category;
use App\Models\Product;
use App\Services\Media\ImageProcessor;
use Throwable;

/**
 * Demo catalog for development and presentations (TZ stage 1): a 3-level Russian category tree,
 * about 50 fictional products and photos drawn with GD (no network).
 *
 * Demo data is recognisable by the `DEMO-` sku prefix and the `demo-` category slugs, so
 * {@see fresh()} never touches anything else, and {@see run()} is idempotent (updates by sku/slug).
 */
final class DemoCatalogSeeder
{
    public const SKU_PREFIX = 'DEMO-';

    public const SLUG_PREFIX = 'demo-';

    private const BRANDS = ['Acme', 'Nordika', 'Техномир', 'Домовик', 'Лесная Артель', 'Вектор', 'Северный Ветер'];

    private const COLORS = ['Белый', 'Чёрный', 'Серый', 'Бежевый', 'Синий', 'Зелёный', 'Графит'];

    /**
     * Category tree: key => [parent key, name, sales mode]. Parents are listed before children.
     *
     * @var array<string, array{0: string|null, 1: string, 2?: SalesMode}>
     */
    private const CATEGORIES = [
        'elektronika' => [null, 'Электроника'],
        'telefony' => ['elektronika', 'Телефоны'],
        'smartfony' => ['telefony', 'Смартфоны'],
        'chehly' => ['telefony', 'Чехлы и аксессуары'],
        'audio' => ['elektronika', 'Аудио'],
        'naushniki' => ['audio', 'Наушники'],
        'mebel' => [null, 'Мебель', SalesMode::Reservation],
        'gostinaya' => ['mebel', 'Гостиная'],
        'divany' => ['gostinaya', 'Диваны'],
        'kresla' => ['gostinaya', 'Кресла'],
        'spalnya' => ['mebel', 'Спальня'],
        'dom-i-sad' => [null, 'Дом и сад'],
        'kuhnya' => ['dom-i-sad', 'Кухня'],
        'posuda' => ['kuhnya', 'Посуда'],
        'sad' => ['dom-i-sad', 'Сад и огород'],
    ];

    /**
     * Products: [category key, name, base price (rubles), material].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const PRODUCTS = [
        ['elektronika', 'Портативная зарядная станция 300 Вт', '18990', 'Пластик'],
        ['telefony', 'Кнопочный телефон Классика Duo', '2490', 'Пластик'],
        ['telefony', 'Радиотелефон DECT с автоответчиком', '3290', 'Пластик'],
        ['smartfony', 'Смартфон Орион X1 128 ГБ', '24990', 'Стекло, алюминий'],
        ['smartfony', 'Смартфон Орион X1 Pro 256 ГБ', '34990', 'Стекло, алюминий'],
        ['smartfony', 'Смартфон Вега Lite 64 ГБ', '11990', 'Пластик'],
        ['smartfony', 'Смартфон Вега Max 6.8"', '19990', 'Стекло'],
        ['smartfony', 'Смартфон Квант 5G 128 ГБ', '27490', 'Стекло, металл'],
        ['smartfony', 'Смартфон Комета Mini', '9490', 'Пластик'],
        ['chehly', 'Чехол-книжка Нордика для Орион X1', '890', 'Экокожа'],
        ['chehly', 'Силиконовый чехол прозрачный', '390', 'Силикон'],
        ['chehly', 'Защитное стекло 9H', '490', 'Стекло'],
        ['chehly', 'Беспроводная зарядка 15 Вт', '1990', 'Пластик'],
        ['audio', 'Портативная колонка Бас-Мини', '3490', 'Ткань, пластик'],
        ['audio', 'Саундбар Кинозал 2.1', '12990', 'Дерево, ткань'],
        ['naushniki', 'Наушники беспроводные Тишина ANC', '7990', 'Пластик'],
        ['naushniki', 'Наушники-вкладыши Капля', '1990', 'Силикон'],
        ['naushniki', 'Наушники полноразмерные Студия', '5490', 'Экокожа'],
        ['naushniki', 'Гарнитура игровая Арена', '4290', 'Пластик, металл'],
        ['divany', 'Диван прямой Берген трёхместный', '45990', 'Велюр'],
        ['divany', 'Диван угловой Осло с оттоманкой', '68990', 'Рогожка'],
        ['divany', 'Диван-кровать Тюмень', '32990', 'Микрофибра'],
        ['divany', 'Софа Лофт раскладная', '28990', 'Экокожа'],
        ['divany', 'Диван двухместный Мини', '21990', 'Велюр'],
        ['kresla', 'Кресло Скандик с подлокотниками', '14990', 'Ткань, дуб'],
        ['kresla', 'Кресло-качалка Берёзка', '12490', 'Берёза'],
        ['kresla', 'Кресло-мешок Груша XL', '4990', 'Ткань'],
        ['kresla', 'Кресло офисное Эргон', '9990', 'Сетка, металл'],
        ['kresla', 'Кресло Честер классическое', '31990', 'Экокожа'],
        ['gostinaya', 'Журнальный столик Круг', '5990', 'Дерево, металл'],
        ['gostinaya', 'ТВ-тумба Линия 160 см', '11990', 'ЛДСП'],
        ['spalnya', 'Кровать двуспальная Норд 160x200', '29990', 'Массив сосны'],
        ['spalnya', 'Матрас ортопедический Комфорт', '17990', 'Пенополиуретан'],
        ['spalnya', 'Комод Бриз 4 ящика', '13490', 'МДФ'],
        ['spalnya', 'Шкаф-купе Горизонт 200 см', '38990', 'ЛДСП, зеркало'],
        ['kuhnya', 'Набор кухонных ножей Шеф (5 предметов)', '3990', 'Сталь'],
        ['kuhnya', 'Разделочная доска Бук', '890', 'Бук'],
        ['kuhnya', 'Электрочайник Закипай 1.7 л', '2290', 'Нержавеющая сталь'],
        ['posuda', 'Сервиз столовый Лазурь на 6 персон', '5990', 'Фарфор'],
        ['posuda', 'Сковорода Чугунок 26 см', '2490', 'Чугун'],
        ['posuda', 'Набор кастрюль Домашний (3 шт.)', '6490', 'Нержавеющая сталь'],
        ['posuda', 'Кружка керамическая Утро', '390', 'Керамика'],
        ['posuda', 'Термос Путник 1 л', '1790', 'Нержавеющая сталь'],
        ['posuda', 'Набор стаканов Грани (6 шт.)', '1290', 'Стекло'],
        ['sad', 'Садовая лейка Дождик 10 л', '690', 'Пластик'],
        ['sad', 'Набор садовых инструментов Огородник', '2790', 'Сталь'],
        ['sad', 'Шезлонг складной Дача', '4490', 'Металл, ткань'],
        ['sad', 'Мангал стальной Угольки', '5290', 'Сталь'],
        ['sad', 'Садовые качели Лето двухместные', '19990', 'Металл'],
        ['sad', 'Теплица Урожай 3x4 м', '24990', 'Поликарбонат'],
    ];

    /** Indexes (0-based) of products shown as "price on request". */
    private const PRICE_ON_REQUEST = [20, 47];

    public function __construct(private readonly ImageProcessor $images) {}

    /**
     * Create or update the demo data.
     *
     * @param  int|null  $limit  only the first N products (categories are always created)
     * @return array{categories_created: int, categories_updated: int, products_created: int, products_updated: int, images_created: int, images_skipped: int, warnings: list<string>}
     */
    public function run(bool $withImages = true, ?int $limit = null): array
    {
        $report = [
            'categories_created' => 0,
            'categories_updated' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'images_created' => 0,
            'images_skipped' => 0,
            'warnings' => [],
        ];

        $categories = $this->seedCategories($report);

        foreach (self::PRODUCTS as $index => $row) {
            if ($limit !== null && $index >= $limit) {
                break;
            }

            $sku = self::sku($index);

            try {
                $product = $this->seedProduct($index, $row, $categories[$row[0]], $report);

                if ($withImages) {
                    $this->seedImages($product, $index, $report);
                }
            } catch (Throwable $e) {
                $report['warnings'][] = $sku.': '.$e->getMessage();
            }
        }

        return $report;
    }

    /**
     * Delete demo products (permanently, with photos) and the demo categories that became empty.
     *
     * @return array{products: int, categories: int}
     */
    public function fresh(): array
    {
        $products = 0;
        $categories = 0;

        Product::withTrashed()->where('sku', 'like', self::SKU_PREFIX.'%')->each(function (Product $product) use (&$products): void {
            $product->forceDelete();
            $products++;
        });

        $slugs = array_map(fn (string $key): string => self::SLUG_PREFIX.$key, array_keys(self::CATEGORIES));

        // Deepest first, so parents are empty by the time they are reached.
        $demo = Category::query()->whereIn('slug', $slugs)->orderByDesc('depth')->get();

        foreach ($demo as $category) {
            try {
                $category->delete();
                $categories++;
            } catch (Throwable) {
                // Still holds non-demo products or subcategories: leave it alone.
            }
        }

        return ['products' => $products, 'categories' => $categories];
    }

    public static function sku(int $index): string
    {
        return self::SKU_PREFIX.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, int> category key => id
     */
    private function seedCategories(array &$report): array
    {
        $ids = [];
        $order = 0;

        foreach (self::CATEGORIES as $key => $definition) {
            $category = Category::query()->firstOrNew(['slug' => self::SLUG_PREFIX.$key]);
            $isNew = ! $category->exists;

            $category->fill([
                'parent_id' => $definition[0] !== null ? $ids[$definition[0]] : null,
                'name' => $definition[1],
                'description' => '<p>Демонстрационная категория «'.$definition[1].'».</p>',
                'is_active' => true,
                'sales_mode' => $definition[2] ?? null,
                'sort_order' => $order++,
            ]);
            $category->save();

            $ids[$key] = $category->id;
            $report[$isNew ? 'categories_created' : 'categories_updated']++;
        }

        return $ids;
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $row
     * @param  array<string, mixed>  $report
     */
    private function seedProduct(int $index, array $row, int $categoryId, array &$report): Product
    {
        [, $name, $price, $material] = $row;

        $brand = self::BRANDS[$index % count(self::BRANDS)];
        $product = Product::withTrashed()->firstOrNew(['sku' => self::sku($index)]);
        $isNew = ! $product->exists;

        $onSale = $index % 6 === 2;
        $stock = match (true) {
            $index % 9 === 0 => 0,
            $index % 11 === 0 => null,
            default => ($index * 7) % 40 + 3,
        };

        $product->fill([
            'category_id' => $categoryId,
            'name' => $name,
            'brand' => $brand,
            'short_description' => $name.' от бренда '.$brand.': надёжно, удобно и по честной цене.',
            'description' => '<p><strong>'.e($name).'</strong> — демонстрационный товар бренда '.e($brand).'.</p>'
                .'<ul><li>Материал: '.e($material).'</li><li>Гарантия производителя</li><li>Быстрая доставка по городу</li></ul>'
                .'<p>Описание выдумано для демонстрации витрины и не относится к реальным товарам.</p>',
            'attributes' => [
                ['name' => 'Цвет', 'value' => self::COLORS[$index % count(self::COLORS)]],
                ['name' => 'Материал', 'value' => $material],
                ['name' => 'Размер', 'value' => 'M'.(($index % 4) + 1)],
                ['name' => 'Гарантия', 'value' => (($index % 3) + 1).' '.(($index % 3) === 0 ? 'год' : 'года')],
            ],
            'base_price' => number_format((float) $price, 2, '.', ''),
            'compare_at_price' => $onSale ? bcmul(number_format((float) $price, 2, '.', ''), '1.20', 2) : null,
            'price_on_request' => in_array($index, self::PRICE_ON_REQUEST, true),
            'stock_qty' => $stock,
            'unit' => 'шт',
            'is_active' => true,
            'is_featured' => $index % 7 === 0,
            'is_new' => $index % 5 === 1,
            'sort_order' => $index,
        ]);

        if ($product->trashed()) {
            $product->restore();
        }

        $product->save();
        $report[$isNew ? 'products_created' : 'products_updated']++;

        return $product;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function seedImages(Product $product, int $index, array &$report): void
    {
        $count = ($index % 3) + 1;
        $existing = $product->images()->pluck('source_hash')->all();

        for ($n = 1; $n <= $count; $n++) {
            $hash = sha1('demo:'.$product->sku.':'.$n);

            if (in_array($hash, $existing, true)) {
                $report['images_skipped']++;

                continue;
            }

            $file = $this->drawImage($product->sku, $n);

            try {
                $this->images->attachToProduct($product, $file, null, $hash, deleteSource: true);
                $report['images_created']++;
            } finally {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Draw a 1200x900 picture with GD: a coloured background (deterministic per sku), a few shapes and
     * the sku in Latin (built-in GD fonts have no Cyrillic). Returns the path of a temporary JPEG.
     */
    private function drawImage(string $sku, int $n): string
    {
        $width = 1200;
        $height = 900;
        $seed = crc32($sku);
        $rand = fn (int $k, int $max): int => crc32($sku.':'.$n.':'.$k) % ($max + 1);

        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new \RuntimeException('GD is not available.');
        }

        $hue = ($seed + $n * 40) % 360;
        [$r, $g, $b] = self::hsl($hue, 0.45, 0.82);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, $r, $g, $b));

        for ($k = 0; $k < 5; $k++) {
            [$r, $g, $b] = self::hsl(($hue + 25 * ($k + 1)) % 360, 0.5, 0.55 - $k * 0.05);
            $color = (int) imagecolorallocate($image, $r, $g, $b);
            $x = $rand($k * 3, $width - 200);
            $y = $rand($k * 3 + 1, $height - 200);
            $size = 120 + $rand($k * 3 + 2, 280);

            match ($k % 3) {
                0 => imagefilledellipse($image, $x + 100, $y + 100, $size, $size, $color),
                1 => imagefilledrectangle($image, $x, $y, $x + $size, $y + (int) ($size * 0.6), $color),
                default => imagefilledpolygon($image, [$x, $y + $size, $x + $size, $y + $size, $x + intdiv($size, 2), $y], $color),
            };
        }

        $this->drawLabel($image, $sku.' #'.$n, $width, $height);

        $path = tempnam(sys_get_temp_dir(), 'shopdemo');

        if ($path === false || ! imagejpeg($image, $path, 85)) {
            throw new \RuntimeException('Cannot write a temporary image.');
        }

        return $path;
    }

    /**
     * The label is drawn small with a built-in font and magnified, so it is readable.
     */
    private function drawLabel(\GdImage $image, string $text, int $width, int $height): void
    {
        $scale = 5;
        $textWidth = imagefontwidth(5) * strlen($text) + 16;
        $textHeight = imagefontheight(5) + 12;

        $label = imagecreatetruecolor($textWidth, $textHeight);

        if ($label === false) {
            return;
        }

        imagefilledrectangle($label, 0, 0, $textWidth, $textHeight, (int) imagecolorallocate($label, 255, 255, 255));
        imagestring($label, 5, 8, 6, $text, (int) imagecolorallocate($label, 40, 40, 40));

        $w = $textWidth * $scale;
        $h = $textHeight * $scale;
        imagecopyresized($image, $label, intdiv($width - $w, 2), intdiv($height - $h, 2), 0, 0, $w, $h, $textWidth, $textHeight);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hsl(int $h, float $s, float $l): array
    {
        $l = max(0.1, min(0.95, $l));
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (intdiv($h, 60) % 6) {
            0 => [$c, $x, 0],
            1 => [$x, $c, 0],
            2 => [0, $c, $x],
            3 => [0, $x, $c],
            4 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }
}
