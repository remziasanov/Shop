<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\CategorySheetField;
use App\Enums\ImportField;
use App\Enums\ImportMode;
use App\Enums\SalesMode;
use App\Models\Category;

/**
 * Row checks of the import (IM-07). Pure: knows nothing about the database; whether the product exists
 * is passed in, duplicates are tracked in the caller-owned `$seenSkus` map.
 */
final class RowValidator
{
    public const MAX_SKU = 100;

    public const MAX_NAME = 500;

    public const MAX_BRAND = 255;

    public const MAX_UNIT = 20;

    public const MAX_META = 255;

    public const MAX_SHORT_DESCRIPTION = 500;

    public const MAX_CATEGORY_NAME = 255;

    public const MAX_PHOTO = 2048;

    public const MAX_ATTRIBUTE_NAME = 255;

    public const MAX_ATTRIBUTE_VALUE = 1000;

    public function __construct(private readonly ValueParser $parser = new ValueParser) {}

    /**
     * Validate one mapped row.
     *
     * @param  bool  $productExists  a product (also a soft-deleted one) with this SKU is already in the catalog
     * @param  array<string, int>  $seenSkus  by reference: lower-cased SKU => row number of its first occurrence;
     *                                        updated by this call (also for invalid rows)
     * @param  int  $rowNumber  source row number (used for the duplicate message and the map)
     */
    public function validate(
        MappedRow $row,
        ImportMode $mode,
        bool $productExists,
        array &$seenSkus,
        string $categorySeparator = '/',
        int $rowNumber = 0,
    ): RowValidationResult {
        $errors = [];
        $warnings = [];
        $values = [];
        $pricesOnly = $mode === ImportMode::PricesStock;
        $isNew = ! $productExists;

        $error = function (ImportField $field, string $message, ?string $value = null) use (&$errors): void {
            $errors[] = new RowIssue($field->value, $field->label(), $message, $value);
        };

        // SKU: required, length, uniqueness within the file.
        $sku = $this->text($row->get('sku'));

        if ($sku === null) {
            $error(ImportField::Sku, __('import.errors.required', ['field' => ImportField::Sku->label()]));
        } else {
            $values['sku'] = $sku;

            if (mb_strlen($sku) > self::MAX_SKU) {
                $error(ImportField::Sku, __('import.errors.too_long', ['field' => ImportField::Sku->label(), 'max' => self::MAX_SKU]), $sku);
            }

            $key = mb_strtolower($sku, 'UTF-8');

            if (isset($seenSkus[$key])) {
                $error(ImportField::Sku, __('import.errors.duplicate_sku', ['row' => $seenSkus[$key]]), $sku);
            } else {
                $seenSkus[$key] = $rowNumber;
            }
        }

        // Prices and stock (the only fields considered in "prices and stock" mode).
        $price = $this->parsed($row, ImportField::Price, fn (?string $v): ?int => $this->parser->price($v), $values, $errors);
        $this->parsed($row, ImportField::CostPrice, fn (?string $v): ?int => $this->parser->price($v), $values, $errors);
        $oldPrice = $this->parsed($row, ImportField::OldPrice, fn (?string $v): ?int => $this->parser->price($v), $values, $errors);
        $this->parsed($row, ImportField::Stock, fn (?string $v): ?int => $this->parser->stock($v), $values, $errors);

        if ($price !== null && $oldPrice !== null && $oldPrice > 0 && $oldPrice <= $price) {
            $warnings[] = new RowIssue(ImportField::OldPrice->value, ImportField::OldPrice->label(), __('import.warnings.old_price_not_greater'), $row->get('old_price'));
        }

        if ($pricesOnly) {
            if ($productExists && ! $this->hasAny($row, [ImportField::Price, ImportField::CostPrice, ImportField::OldPrice, ImportField::Stock])) {
                $warnings[] = new RowIssue('', '', __('import.warnings.nothing_to_update'));
            }

            return new RowValidationResult($errors, $warnings, $values, [], $this->action($errors, $productExists, $mode));
        }

        // Product data.
        $this->stringField($row, ImportField::Name, self::MAX_NAME, $values, $errors);
        $this->stringField($row, ImportField::Brand, self::MAX_BRAND, $values, $errors);
        $this->stringField($row, ImportField::Unit, self::MAX_UNIT, $values, $errors);
        $this->stringField($row, ImportField::ShortDescription, self::MAX_SHORT_DESCRIPTION, $values, $errors);
        $this->stringField($row, ImportField::MetaTitle, self::MAX_META, $values, $errors);
        $this->stringField($row, ImportField::MetaDescription, self::MAX_META, $values, $errors);

        if ($row->has('description')) {
            $values['description'] = $this->text($row->get('description'));
        }

        $this->category($row, $categorySeparator, $values, $errors);
        $this->photos($row, $values, $errors);

        foreach ([ImportField::Active, ImportField::Featured, ImportField::New, ImportField::PriceOnRequest] as $field) {
            $this->parsed($row, $field, fn (?string $v): ?bool => $this->parser->bool($v), $values, $errors);
        }

        $this->parsed($row, ImportField::Sort, fn (?string $v): ?int => $this->parser->integer($v), $values, $errors);

        if ($row->has('sales_mode')) {
            try {
                $parsedMode = $this->parser->salesMode($row->get('sales_mode'));
                $values['sales_mode'] = $parsedMode instanceof SalesMode ? $parsedMode->value : $parsedMode;
            } catch (ValueParseException $e) {
                $error(ImportField::SalesMode, $e->getMessage(), $row->get('sales_mode'));
            }
        }

        if ($row->has('age')) {
            try {
                $values['age'] = $this->parser->age($row->get('age'));
            } catch (ValueParseException $e) {
                $error(ImportField::Age, $e->getMessage(), $row->get('age'));
            }
        }

        // Required for a new product.
        if ($isNew) {
            foreach ([ImportField::Name, ImportField::Category, ImportField::Price] as $field) {
                $blank = match ($field) {
                    ImportField::Category => array_key_exists('category', $values)
                        ? $values['category'] === []
                        : $this->text($row->get('category')) === null,
                    default => $this->text($row->get($field->value)) === null,
                };

                if ($blank) {
                    $error($field, __('import.errors.required_new', ['field' => $field->label()]));
                }
            }
        }

        // Characteristics.
        $attributes = [];

        foreach ($row->attributes as $attribute) {
            if (mb_strlen($attribute['name']) > self::MAX_ATTRIBUTE_NAME || mb_strlen($attribute['value']) > self::MAX_ATTRIBUTE_VALUE) {
                $max = mb_strlen($attribute['name']) > self::MAX_ATTRIBUTE_NAME ? self::MAX_ATTRIBUTE_NAME : self::MAX_ATTRIBUTE_VALUE;
                $errors[] = new RowIssue(
                    ImportField::attributeKey($attribute['name']),
                    ColumnMapper::fieldLabel(ImportField::attributeKey($attribute['name'])),
                    __('import.errors.too_long', ['field' => $attribute['name'], 'max' => $max]),
                    $attribute['value'],
                );

                continue;
            }

            $attributes[] = $attribute;
        }

        return new RowValidationResult($errors, $warnings, $values, $attributes, $this->action($errors, $productExists, $mode));
    }

    /**
     * Validate a row of the «Категории» sheet.
     *
     * @param  array<string, string|null>  $values  field key => raw cell (see ColumnMapper::mapCategoryRow)
     * @return array{errors: list<RowIssue>, values: array<string, mixed>}
     */
    public function validateCategory(array $values, string $separator = '/'): array
    {
        $errors = [];
        $out = [];

        $error = function (CategorySheetField $field, string $message, ?string $value = null) use (&$errors): void {
            $errors[] = new RowIssue($field->value, $field->label(), $message, $value);
        };

        $parts = $this->parser->categoryPath($values['path'] ?? null, $separator);

        if ($parts === []) {
            $error(CategorySheetField::Path, __('import.errors.required', ['field' => CategorySheetField::Path->label()]));
        } elseif (count($parts) > Category::MAX_DEPTH) {
            $error(CategorySheetField::Path, __('import.errors.category_too_deep', ['max' => Category::MAX_DEPTH]), $values['path'] ?? null);
        } else {
            foreach ($parts as $part) {
                if (mb_strlen($part) > self::MAX_CATEGORY_NAME) {
                    $error(CategorySheetField::Path, __('import.errors.too_long', ['field' => CategorySheetField::Path->label(), 'max' => self::MAX_CATEGORY_NAME]), $part);
                }
            }
        }

        $out['path'] = $parts;

        foreach (['description', 'photo'] as $key) {
            if (array_key_exists($key, $values)) {
                $out[$key] = $this->text($values[$key]);
            }
        }

        foreach (['meta_title' => CategorySheetField::MetaTitle, 'meta_description' => CategorySheetField::MetaDescription] as $key => $field) {
            if (array_key_exists($key, $values)) {
                $text = $this->text($values[$key]);

                if ($text !== null && mb_strlen($text) > self::MAX_META) {
                    $error($field, __('import.errors.too_long', ['field' => $field->label(), 'max' => self::MAX_META]), $text);
                } else {
                    $out[$key] = $text;
                }
            }
        }

        if (array_key_exists('sort', $values)) {
            try {
                $out['sort'] = $this->parser->integer($values['sort']);
            } catch (ValueParseException $e) {
                $error(CategorySheetField::Sort, $e->getMessage(), $values['sort']);
            }
        }

        if (array_key_exists('active', $values)) {
            try {
                $out['active'] = $this->parser->bool($values['active']);
            } catch (ValueParseException $e) {
                $error(CategorySheetField::Active, $e->getMessage(), $values['active']);
            }
        }

        if (array_key_exists('sales_mode', $values)) {
            try {
                $parsed = $this->parser->salesMode($values['sales_mode']);
                $out['sales_mode'] = $parsed instanceof SalesMode ? $parsed->value : $parsed;
            } catch (ValueParseException $e) {
                $error(CategorySheetField::SalesMode, $e->getMessage(), $values['sales_mode']);
            }
        }

        if (array_key_exists('age', $values)) {
            try {
                $out['age'] = $this->parser->age($values['age']);
            } catch (ValueParseException $e) {
                $error(CategorySheetField::Age, $e->getMessage(), $values['age']);
            }
        }

        return ['errors' => $errors, 'values' => $out];
    }

    /**
     * @param  list<RowIssue>  $errors
     */
    private function action(array $errors, bool $productExists, ImportMode $mode): string
    {
        if ($errors !== []) {
            return RowValidationResult::ACTION_ERROR;
        }

        if ($productExists) {
            return RowValidationResult::ACTION_UPDATE;
        }

        return $mode === ImportMode::PricesStock ? RowValidationResult::ACTION_SKIP : RowValidationResult::ACTION_CREATE;
    }

    /**
     * Parse a mapped field with the given parser; on success store the value, on failure add an error.
     *
     * @param  callable(?string): mixed  $parse
     * @param  array<string, mixed>  $values
     * @param  list<RowIssue>  $errors
     */
    private function parsed(MappedRow $row, ImportField $field, callable $parse, array &$values, array &$errors): mixed
    {
        if (! $row->has($field->value)) {
            return null;
        }

        try {
            $value = $parse($row->get($field->value));
        } catch (ValueParseException $e) {
            $errors[] = new RowIssue($field->value, $field->label(), $e->getMessage(), $row->get($field->value));

            return null;
        }

        $values[$field->value] = $value;

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<RowIssue>  $errors
     */
    private function stringField(MappedRow $row, ImportField $field, int $max, array &$values, array &$errors): void
    {
        if (! $row->has($field->value)) {
            return;
        }

        $text = $this->text($row->get($field->value));

        if ($text !== null && mb_strlen($text) > $max) {
            $errors[] = new RowIssue($field->value, $field->label(), __('import.errors.too_long', ['field' => $field->label(), 'max' => $max]), $text);

            return;
        }

        $values[$field->value] = $text;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<RowIssue>  $errors
     */
    private function category(MappedRow $row, string $separator, array &$values, array &$errors): void
    {
        if (! $row->has('category')) {
            return;
        }

        $field = ImportField::Category;
        $parts = $this->parser->categoryPath($row->get('category'), $separator);

        if (count($parts) > Category::MAX_DEPTH) {
            $errors[] = new RowIssue($field->value, $field->label(), __('import.errors.category_too_deep', ['max' => Category::MAX_DEPTH]), $row->get('category'));

            return;
        }

        foreach ($parts as $part) {
            if (mb_strlen($part) > self::MAX_CATEGORY_NAME) {
                $errors[] = new RowIssue($field->value, $field->label(), __('import.errors.too_long', ['field' => $field->label(), 'max' => self::MAX_CATEGORY_NAME]), $part);

                return;
            }
        }

        $values['category'] = $parts;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<RowIssue>  $errors
     */
    private function photos(MappedRow $row, array &$values, array &$errors): void
    {
        if (! $row->has('photos')) {
            return;
        }

        $field = ImportField::Photos;
        $photos = $this->parser->photos($row->get('photos'));

        foreach ($photos as $photo) {
            if (mb_strlen($photo) > self::MAX_PHOTO) {
                $errors[] = new RowIssue($field->value, $field->label(), __('import.errors.photo_too_long', ['max' => self::MAX_PHOTO]), mb_substr($photo, 0, 100));

                return;
            }
        }

        $values['photos'] = $photos;
    }

    /**
     * @param  list<ImportField>  $fields
     */
    private function hasAny(MappedRow $row, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->text($row->get($field->value)) !== null) {
                return true;
            }
        }

        return false;
    }

    private function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
