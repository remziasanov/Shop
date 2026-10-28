<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\Models\Role;
use App\Services\Users\PermissionRegistry;
use App\Services\Users\RoleService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Role form (RB-09): name, code, description and the permission matrix. Rows are sections, columns are
 * «Просмотр / Создание / Изменение / Удаление»; the other actions form the block «Специальные права».
 * Form state keeps `matrix.{section}` and `special.{section}` (dots of section ids are replaced by `__`).
 */
final class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('roles.form.main'))->schema([
                TextInput::make('name')
                    ->label(__('roles.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->label(__('roles.fields.code'))
                    ->helperText(__('roles.form.code_hint'))
                    ->required()
                    ->minLength(2)
                    ->maxLength(32)
                    ->regex(RoleService::CODE_PATTERN)
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?Role $record): bool => $record !== null && $record->is_system)
                    ->dehydrated(fn (?Role $record): bool => $record === null || ! $record->is_system),
                Textarea::make('description')
                    ->label(__('roles.fields.description'))
                    ->maxLength(500)
                    ->rows(2)
                    ->columnSpanFull(),
            ])->columns(2),

            Section::make(__('roles.form.administrator_title'))
                ->schema([Text::make(__('roles.form.administrator_text'))])
                ->visible(fn (?Role $record): bool => $record !== null && $record->isAdministrator()),

            Section::make(__('roles.form.permissions'))
                ->description(__('roles.form.sensitive_hint'))
                ->schema([
                    Toggle::make('all_permissions')
                        ->label(__('roles.form.all'))
                        ->live()
                        ->dehydrated(false)
                        ->afterStateUpdated(function (bool $state, Set $set): void {
                            foreach (PermissionRegistry::sections() as $section => $actions) {
                                $set('matrix.'.self::key($section), $state ? self::matrixKeys($section) : []);
                                $set('special.'.self::key($section), $state ? self::specialKeys($section) : []);
                            }
                        }),
                    ...self::lists('matrix', self::matrixKeys(...)),
                ])
                ->visible(fn (?Role $record): bool => $record === null || ! $record->isAdministrator()),

            Section::make(__('roles.form.special'))
                ->schema(self::lists('special', self::specialKeys(...)))
                ->visible(fn (?Role $record): bool => $record === null || ! $record->isAdministrator()),
        ]);
    }

    /**
     * Form state for a stored role.
     *
     * @return array{matrix: array<string, list<string>>, special: array<string, list<string>>}
     */
    public static function stateFor(Role $role): array
    {
        $granted = array_flip($role->isAdministrator() ? [] : $role->storedPermissions());
        $state = ['matrix' => [], 'special' => []];

        foreach (PermissionRegistry::sections() as $section => $actions) {
            $state['matrix'][self::key($section)] = array_values(array_filter(self::matrixKeys($section), fn (string $p): bool => isset($granted[$p])));
            $state['special'][self::key($section)] = array_values(array_filter(self::specialKeys($section), fn (string $p): bool => isset($granted[$p])));
        }

        return $state;
    }

    /**
     * Permission keys selected in the submitted form state.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function permissionsFrom(array $data): array
    {
        $selected = [];

        foreach (['matrix', 'special'] as $group) {
            foreach ((array) ($data[$group] ?? []) as $keys) {
                foreach ((array) $keys as $key) {
                    $selected[] = $key;
                }
            }
        }

        return PermissionRegistry::normalize($selected);
    }

    /**
     * @param  callable(string): list<string>  $keys
     * @return list<CheckboxList>
     */
    private static function lists(string $group, callable $keys): array
    {
        $lists = [];

        foreach (array_keys(PermissionRegistry::sections()) as $section) {
            $permissions = $keys($section);

            if ($permissions === []) {
                continue;
            }

            $lists[] = CheckboxList::make($group.'.'.self::key($section))
                ->label(PermissionRegistry::sectionLabel($section))
                ->options(self::options($section, $permissions))
                ->columns(['default' => 2, 'md' => 4])
                ->bulkToggleable();
        }

        return $lists;
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string, string>
     */
    private static function options(string $section, array $permissions): array
    {
        $options = [];

        foreach ($permissions as $permission) {
            $action = substr($permission, strlen($section) + 1);
            $label = PermissionRegistry::actionLabel($section, $action);

            $options[$permission] = PermissionRegistry::isSensitive($permission) ? $label.' ⚠' : $label;
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private static function matrixKeys(string $section): array
    {
        return array_values(array_filter(
            PermissionRegistry::forSection($section),
            fn (string $p): bool => in_array(substr($p, strlen($section) + 1), PermissionRegistry::MATRIX_ACTIONS, true),
        ));
    }

    /**
     * @return list<string>
     */
    private static function specialKeys(string $section): array
    {
        return array_values(array_diff(PermissionRegistry::forSection($section), self::matrixKeys($section)));
    }

    private static function key(string $section): string
    {
        return str_replace('.', '__', $section);
    }
}
