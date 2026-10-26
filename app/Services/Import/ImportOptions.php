<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\EmptyCellPolicy;
use App\Enums\ImportMode;
use App\Enums\PhotoMode;

/**
 * Import options (IM-04, IM-05). Stored as JSON in `imports.options`; the mode is duplicated in `imports.mode`.
 */
final readonly class ImportOptions
{
    /** Allowed separators of a category path. */
    public const SEPARATORS = ['/', '>', '|'];

    public function __construct(
        public ImportMode $mode = ImportMode::CreateUpdate,
        public bool $createCategories = true,
        public string $categorySeparator = '/',
        public EmptyCellPolicy $emptyCellPolicy = EmptyCellPolicy::Keep,
        public PhotoMode $photoMode = PhotoMode::Replace,
        public bool $dryRun = false,
    ) {}

    /**
     * Tolerant constructor: unknown or missing keys fall back to defaults.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data, ?ImportMode $mode = null): self
    {
        $data ??= [];

        $separator = $data['category_separator'] ?? '/';

        return new self(
            mode: $mode ?? ImportMode::tryFrom((string) ($data['mode'] ?? '')) ?? ImportMode::CreateUpdate,
            createCategories: (bool) ($data['create_categories'] ?? true),
            categorySeparator: is_string($separator) && in_array($separator, self::SEPARATORS, true) ? $separator : '/',
            emptyCellPolicy: EmptyCellPolicy::tryFrom((string) ($data['empty_cell_policy'] ?? '')) ?? EmptyCellPolicy::Keep,
            photoMode: PhotoMode::tryFrom((string) ($data['photo_mode'] ?? '')) ?? PhotoMode::Replace,
            dryRun: (bool) ($data['dry_run'] ?? false),
        );
    }

    /**
     * @return array{mode: string, create_categories: bool, category_separator: string, empty_cell_policy: string, photo_mode: string, dry_run: bool}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'create_categories' => $this->createCategories,
            'category_separator' => $this->categorySeparator,
            'empty_cell_policy' => $this->emptyCellPolicy->value,
            'photo_mode' => $this->photoMode->value,
            'dry_run' => $this->dryRun,
        ];
    }
}
