<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\CategorySheetField;
use App\Enums\SalesMode;
use App\Services\Export\CatalogExporter;
use App\Services\Export\XlsxBook;
use RuntimeException;
use ZipArchive;

/**
 * Builds the demo import files for `examples/` (TZ stage 3): `import-demo.xlsx` with about 50 fictional products in
 * a 3-level category tree and `import-demo-photos.zip` with the small GD-drawn JPEGs the file refers to.
 * Reuses the data of {@see DemoCatalogSeeder}; SKUs use another prefix so the files never collide with `shop:demo`.
 */
final class DemoImportBuilder
{
    public const SKU_PREFIX = 'IMP-';

    public const XLSX_NAME = 'import-demo.xlsx';

    public const ZIP_NAME = 'import-demo-photos.zip';

    private const PHOTO_WIDTH = 800;

    private const PHOTO_HEIGHT = 600;

    private const PHOTO_QUALITY = 70;

    public function __construct(private readonly DemoImageDrawer $drawer) {}

    /**
     * Write both files into the directory.
     *
     * @return array{xlsx: string, zip: string, products: int, photos: int}
     */
    public function build(string $dir): array
    {
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}.");
        }

        $xlsx = rtrim($dir, '/').'/'.self::XLSX_NAME;
        $zip = rtrim($dir, '/').'/'.self::ZIP_NAME;

        $photos = $this->writeWorkbook($xlsx);
        $this->writeZip($zip, $photos);

        return ['xlsx' => $xlsx, 'zip' => $zip, 'products' => count(DemoCatalogSeeder::PRODUCTS), 'photos' => count($photos)];
    }

    public static function sku(int $index): string
    {
        return self::SKU_PREFIX.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<string> file names of the photos in the order they are numbered (a1.jpg, a2.jpg, …)
     */
    private function writeWorkbook(string $path): array
    {
        $paths = $this->categoryPaths();
        $attributeNames = ['Цвет', 'Материал', 'Размер', 'Гарантия'];
        $book = new XlsxBook($path);
        $files = [];

        try {
            $book->sheet(__('export.sheets.products'), [1 => 16, 2 => 40, 3 => 40, 12 => 20]);
            $book->header(CatalogExporter::productHeaders($attributeNames));

            foreach (DemoCatalogSeeder::PRODUCTS as $index => [$categoryKey, $name, $price, $material]) {
                $photos = [];
                $count = $index % 3 === 2 ? 2 : 1;

                for ($n = 0; $n < $count; $n++) {
                    $files[] = $photos[] = 'a'.(count($files) + 1).'.jpg';
                }

                $book->row($this->productRow($index, $paths[$categoryKey], $name, (float) $price, $material, $photos));
            }

            $this->writeCategories($book, $paths);
        } finally {
            $book->close();
        }

        return $files;
    }

    /**
     * @param  list<string>  $photos
     * @return list<string|int|float|null>
     */
    private function productRow(int $index, string $categoryPath, string $name, float $price, string $material, array $photos): array
    {
        $brand = DemoCatalogSeeder::BRANDS[$index % count(DemoCatalogSeeder::BRANDS)];
        $yes = __('export.yes');
        $no = __('export.no');

        $stock = match (true) {
            $index % 9 === 0 => 0,
            $index % 11 === 0 => null,
            default => ($index * 7) % 40 + 3,
        };

        $mode = match (true) {
            $index % 10 === 3 => SalesMode::Inquiry->label(),
            $index % 13 === 5 => SalesMode::Showcase->label(),
            $index % 8 === 4 => SalesMode::Both->label(),
            default => null,
        };

        return [
            self::sku($index),
            $name,
            $categoryPath,
            $price,
            round($price * 0.7, 2),
            $index % 6 === 2 ? round($price * 1.2, 2) : null,
            $stock,
            'шт',
            $brand,
            $name.' от бренда '.$brand.': надёжно, удобно и по честной цене.',
            '<p><strong>'.e($name).'</strong> — демонстрационный товар бренда '.e($brand).'.</p>'
                .'<ul><li>Материал: '.e($material).'</li><li>Гарантия производителя</li></ul>',
            implode('; ', $photos),
            $index % 17 === 16 ? $no : $yes,
            $index % 7 === 0 ? $yes : $no,
            $index % 5 === 1 ? $yes : $no,
            $mode,
            in_array($index, DemoCatalogSeeder::PRICE_ON_REQUEST, true) ? $yes : $no,
            $index * 10,
            $index % 4 === 0 ? $name.' — купить' : null,
            $index % 4 === 0 ? 'Купить «'.$name.'» с доставкой. Демонстрационный товар.' : null,
            DemoCatalogSeeder::COLORS[$index % count(DemoCatalogSeeder::COLORS)],
            $material,
            'M'.(($index % 4) + 1),
            (($index % 3) + 1).' '.(($index % 3) === 0 ? 'год' : 'года'),
        ];
    }

    /**
     * @param  array<string, string>  $paths
     */
    private function writeCategories(XlsxBook $book, array $paths): void
    {
        $book->sheet(CategorySheetField::SHEET_NAME, [1 => 40, 2 => 40]);
        $book->header(CatalogExporter::categoryHeaders());

        $order = 0;

        foreach (DemoCatalogSeeder::CATEGORIES as $key => $definition) {
            $mode = $definition[2] ?? null;

            $book->row([
                $paths[$key],
                'Демонстрационная категория «'.$definition[1].'».',
                $order++,
                __('export.yes'),
                $mode?->label(),
                null,
                null,
                null,
            ]);
        }
    }

    /**
     * @return array<string, string> category key => «A / B / C»
     */
    private function categoryPaths(): array
    {
        $paths = [];

        foreach (DemoCatalogSeeder::CATEGORIES as $key => $definition) {
            $paths[$key] = $definition[0] !== null
                ? $paths[$definition[0]].CatalogExporter::PATH_SEPARATOR.$definition[1]
                : $definition[1];
        }

        return $paths;
    }

    /**
     * @param  list<string>  $files
     */
    private function writeZip(string $path, array $files): void
    {
        if (is_file($path)) {
            unlink($path);
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create {$path}.");
        }

        $temporary = [];

        try {
            foreach ($files as $i => $file) {
                $tmp = $this->drawer->draw(pathinfo($file, PATHINFO_FILENAME), $i + 1, self::PHOTO_WIDTH, self::PHOTO_HEIGHT, self::PHOTO_QUALITY);
                $temporary[] = $tmp;
                $zip->addFile($tmp, $file);
            }

            $zip->close();
        } finally {
            foreach ($temporary as $tmp) {
                @unlink($tmp);
            }
        }
    }
}
