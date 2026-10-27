<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the previous file of a settings FileUpload field from the public disk once the new
 * value is saved (replaced or cleared). Only files under `settings/` are ever touched.
 *
 * The using SettingsPage must implement `settingsFileFields()`.
 */
trait DeletesReplacedSettingFiles
{
    /** @var array<string, string|null> */
    protected array $previousSettingFiles = [];

    /**
     * Names of the settings properties that hold a public disk path.
     *
     * @return list<string>
     */
    abstract protected function settingsFileFields(): array;

    protected function beforeSave(): void
    {
        $settings = app(static::getSettings());
        $this->previousSettingFiles = [];

        foreach ($this->settingsFileFields() as $field) {
            $value = $settings->{$field} ?? null;
            $this->previousSettingFiles[$field] = is_string($value) && $value !== '' ? $value : null;
        }
    }

    protected function afterSave(): void
    {
        $settings = app(static::getSettings());
        $previous = $this->previousSettingFiles;

        $current = [];
        foreach ($this->settingsFileFields() as $field) {
            $value = $settings->{$field} ?? null;
            $current[$field] = is_string($value) && $value !== '' ? $value : null;
        }

        // Delete only after the surrounding transaction has been committed.
        DB::afterCommit(function () use ($previous, $current): void {
            foreach ($previous as $field => $old) {
                if ($old === null || $old === $current[$field] || in_array($old, $current, true)) {
                    continue;
                }

                if (! str_starts_with($old, 'settings/') || str_contains($old, '..')) {
                    continue;
                }

                Storage::disk('public')->delete($old);
            }
        });
    }
}
