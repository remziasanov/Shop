<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Columns of the optional «Категории» sheet (TZ appendix A).
 */
enum CategorySheetField: string
{
    case Path = 'path';
    case Description = 'description';
    case Sort = 'sort';
    case Active = 'active';
    case SalesMode = 'sales_mode';
    case Photo = 'photo';
    case MetaTitle = 'meta_title';
    case MetaDescription = 'meta_description';

    /** Name of the optional sheet with categories. */
    public const SHEET_NAME = 'Категории';

    public function label(): string
    {
        return __('import.category_fields.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public function synonyms(): array
    {
        $own = match ($this) {
            self::Path => ['Путь*', 'путь категории', 'категория', 'раздел', 'path', 'category'],
            self::Description => ['description'],
            self::Sort => ['сортировка', 'sort'],
            self::Active => ['активен', 'active'],
            self::SalesMode => ['режим продаж', 'режим', 'sales mode'],
            self::Photo => ['изображение', 'картинка', 'photo', 'image'],
            self::MetaTitle => ['seo title'],
            self::MetaDescription => ['seo description'],
        };

        return [...$own, $this->label(), $this->value];
    }
}
