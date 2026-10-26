<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical product import columns (TZ appendix A). The backing value is the field key stored in
 * `imports.mapping` and used as the key of normalized staged rows.
 */
enum ImportField: string
{
    case Sku = 'sku';
    case Name = 'name';
    case Category = 'category';
    case Price = 'price';
    case CostPrice = 'cost_price';
    case OldPrice = 'old_price';
    case Stock = 'stock';
    case Unit = 'unit';
    case Brand = 'brand';
    case ShortDescription = 'short_description';
    case Description = 'description';
    case Photos = 'photos';
    case Active = 'active';
    case Featured = 'featured';
    case New = 'new';
    case SalesMode = 'sales_mode';
    case PriceOnRequest = 'price_on_request';
    case Sort = 'sort';
    case MetaTitle = 'meta_title';
    case MetaDescription = 'meta_description';

    /** Prefix of mapping values that denote a characteristic column: "attr:Цвет". */
    public const ATTRIBUTE_PREFIX = 'attr:';

    public function label(): string
    {
        return __('import.fields.'.$this->value);
    }

    /**
     * Header synonyms from TZ appendix A (raw text; compare through HeaderNormalizer).
     * The Russian label and the key itself are always accepted as well.
     *
     * @return list<string>
     */
    public function synonyms(): array
    {
        $own = match ($this) {
            self::Sku => ['sku', 'код', 'код товара', 'артикул товара'],
            self::Name => ['наименование', 'name', 'товар'],
            self::Category => ['раздел', 'путь категории', 'category'],
            self::Price => ['базовая цена', 'розничная цена', 'price'],
            self::CostPrice => ['закупка', 'себестоимость', 'cost'],
            self::OldPrice => ['цена до скидки', 'old price'],
            self::Stock => ['количество', 'кол-во', 'наличие', 'stock', 'qty'],
            self::Unit => ['ед.', 'ед. изм.', 'unit'],
            self::Brand => ['производитель', 'марка', 'brand'],
            self::ShortDescription => ['анонс', 'short description'],
            self::Description => ['полное описание', 'description'],
            self::Photos => ['изображения', 'картинки', 'photo', 'images'],
            self::Active => ['показывать', 'опубликован', 'active'],
            self::Featured => ['хит продаж', 'featured'],
            self::New => ['new'],
            self::SalesMode => ['режим', 'sales mode'],
            self::PriceOnRequest => ['price on request'],
            self::Sort => ['сортировка', 'sort'],
            self::MetaTitle => ['seo title'],
            self::MetaDescription => ['seo description'],
        };

        return [...$own, $this->label(), $this->value];
    }

    /**
     * Fields that make sense in "prices and stock only" mode (besides the SKU).
     */
    public function isPriceOrStock(): bool
    {
        return in_array($this, [self::Price, self::CostPrice, self::OldPrice, self::Stock], true);
    }

    /**
     * @return array<string, string> field key => Russian label, for mapping selects
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Mapping value of a characteristic column.
     */
    public static function attributeKey(string $name): string
    {
        return self::ATTRIBUTE_PREFIX.$name;
    }

    /**
     * Characteristic name of a mapping value ("attr:Цвет" => "Цвет"), null for plain fields.
     */
    public static function attributeName(?string $mappingValue): ?string
    {
        if ($mappingValue === null || ! str_starts_with($mappingValue, self::ATTRIBUTE_PREFIX)) {
            return null;
        }

        $name = substr($mappingValue, strlen(self::ATTRIBUTE_PREFIX));

        return $name === '' ? null : $name;
    }
}
