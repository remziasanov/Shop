<?php

declare(strict_types=1);

namespace App\Services\Users;

/**
 * The single catalog of staff permissions (RB-02, RB-03, TZ table 25.4). A permission is `{section}.{action}`.
 * Sections of features that arrive in later stages are listed already, so roles can be prepared in advance;
 * database rows with keys missing here are ignored when permissions are read.
 */
final class PermissionRegistry
{
    /** Actions shown as the columns of the permission matrix (RB-09); every other action is «special». */
    public const MATRIX_ACTIONS = ['view', 'create', 'update', 'delete'];

    /** Sections whose permissions are marked as sensitive in the UI (RB-08). */
    private const SENSITIVE_PREFIXES = ['users.', 'roles.', 'settings.', 'system.'];

    private const CRUD = ['view', 'create', 'update', 'delete'];

    /** Settings pages, one `settings.{page}.view|update` pair each (25.4). */
    private const SETTINGS_PAGES = ['general', 'appearance', 'sales', 'pricing', 'notifications', 'seo', 'legal', 'customers', 'age_gate', 'catalog'];

    /**
     * Permissions of the system role «Менеджер» (6.13, 25.4, RB-15).
     *
     * @var list<string>
     */
    private const MANAGER_DEFAULTS = [
        'dashboard.view',
        'orders.view', 'orders.update', 'orders.export', 'orders.edit_items', 'orders.create_from_inquiry',
        'products.view', 'products.create', 'products.update', 'products.delete', 'products.bulk',
        'price_history.view',
        'price_rules.view',
        'categories.view', 'categories.create', 'categories.update', 'categories.delete',
        'attributes.view', 'attributes.create', 'attributes.update', 'attributes.delete',
        'option_types.view', 'option_types.create', 'option_types.update', 'option_types.delete',
        'imports.view', 'imports.run',
        'exports.view', 'exports.create',
        'home.update',
        'pages.view', 'pages.create', 'pages.update', 'pages.delete',
        'menu.view', 'menu.create', 'menu.update', 'menu.delete',
        'customers.view', 'customers.update', 'customers.change_group', 'customers.block',
        'wholesale_applications.view', 'wholesale_applications.decide',
        'customer_groups.view',
        'promo_codes.view',
        'promo_links.view',
    ];

    /** @var array<string, list<string>>|null */
    private static ?array $sections = null;

    /**
     * Section id => its actions, in display order. The section id is the permission prefix.
     *
     * @return array<string, list<string>>
     */
    public static function sections(): array
    {
        if (self::$sections !== null) {
            return self::$sections;
        }

        $sections = [
            'dashboard' => ['view'],
            'orders' => ['view', 'update', 'export', 'edit_items', 'create_from_inquiry', 'edit_prices', 'delete'],
            'customers' => ['view', 'update', 'change_group', 'block', 'anonymize'],
            'wholesale_applications' => ['view', 'decide'],
            'products' => [...self::CRUD, 'bulk'],
            'categories' => self::CRUD,
            'attributes' => self::CRUD,
            'option_types' => self::CRUD,
            'imports' => ['view', 'run', 'full_sync'],
            'exports' => ['view', 'create'],
            'prices' => ['bulk'],
            'price_history' => ['view'],
            'price_rules' => self::CRUD,
            'customer_groups' => self::CRUD,
            'promo_codes' => [...self::CRUD, 'generate'],
            'promo_links' => self::CRUD,
            'home' => ['update'],
            'html_block' => ['edit'],
            'pages' => self::CRUD,
            'menu' => self::CRUD,
        ];

        foreach (self::SETTINGS_PAGES as $page) {
            $sections['settings.'.$page] = ['view', 'update'];
        }

        $sections += [
            'users' => self::CRUD,
            'roles' => self::CRUD,
            'system' => ['view'],
            'system.audit_log' => ['view'],
        ];

        return self::$sections = $sections;
    }

    /**
     * Every permission key.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $all = [];

        foreach (self::sections() as $section => $actions) {
            foreach ($actions as $action) {
                $all[] = $section.'.'.$action;
            }
        }

        return $all;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * Permission keys of one section.
     *
     * @return list<string>
     */
    public static function forSection(string $section): array
    {
        return array_map(fn (string $action): string => $section.'.'.$action, self::sections()[$section] ?? []);
    }

    /**
     * Known permissions only, unique and in catalog order (unknown keys are dropped, RB-02).
     *
     * @param  iterable<mixed>  $permissions
     * @return list<string>
     */
    public static function normalize(iterable $permissions): array
    {
        $wanted = [];

        foreach ($permissions as $permission) {
            if (is_string($permission)) {
                $wanted[$permission] = true;
            }
        }

        return array_values(array_filter(self::all(), fn (string $permission): bool => isset($wanted[$permission])));
    }

    /**
     * Default permission set of the system role «Менеджер».
     *
     * @return list<string>
     */
    public static function managerDefaults(): array
    {
        return self::normalize(self::MANAGER_DEFAULTS);
    }

    public static function isSensitive(string $permission): bool
    {
        foreach (self::SENSITIVE_PREFIXES as $prefix) {
            if (str_starts_with($permission, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function sectionLabel(string $section): string
    {
        return __('roles.sections.'.self::langKey($section));
    }

    /**
     * Human label of an action inside its section, e.g. «Просмотр», «Массовое изменение цен».
     */
    public static function actionLabel(string $section, string $action): string
    {
        $specific = 'roles.permission_labels.'.self::langKey($section).'.'.$action;
        $label = __($specific);

        return $label !== $specific ? $label : __('roles.actions.'.$action);
    }

    /**
     * Translation key part of a section id (dots would nest the key).
     */
    private static function langKey(string $section): string
    {
        return str_replace('.', '__', $section);
    }

    /**
     * «Раздел: действие» for lists and messages.
     */
    public static function label(string $permission): string
    {
        foreach (self::sections() as $section => $actions) {
            foreach ($actions as $action) {
                if ($section.'.'.$action === $permission) {
                    return self::sectionLabel($section).': '.mb_strtolower(self::actionLabel($section, $action));
                }
            }
        }

        return $permission;
    }
}
