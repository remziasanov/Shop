<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RB-06: creates the system roles «Администратор» (admin) and «Менеджер» (manager), fills the Manager permissions
 * from the TZ 6.13 / 25.4 matrix, maps `users.role` to `users.role_id` and drops `users.role`.
 *
 * The permission list is copied here on purpose: a migration must keep working when the registry evolves later.
 * On SQLite (tests only) a foreign key cannot be added to an existing table, so it is created on MySQL only.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const MANAGER_PERMISSIONS = [
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

    public function up(): void
    {
        $now = now();

        foreach ([['admin', 'Администратор', 'Полный доступ ко всем разделам'], ['manager', 'Менеджер', 'Работа с каталогом, обращениями и контентом']] as [$code, $name, $description]) {
            if (! DB::table('roles')->where('code', $code)->exists()) {
                DB::table('roles')->insert([
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'is_system' => true,
                    'permissions_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $adminId = (int) DB::table('roles')->where('code', 'admin')->value('id');
        $managerId = (int) DB::table('roles')->where('code', 'manager')->value('id');

        // The Administrator role stores no permissions: it allows everything (RB-02).
        DB::table('role_permissions')->where('role_id', $managerId)->delete();
        DB::table('role_permissions')->insert(array_map(
            fn (string $permission): array => ['role_id' => $managerId, 'permission' => $permission],
            self::MANAGER_PERMISSIONS,
        ));

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id')->nullable()->index();
        });

        // Anything that is not «admin» (including unexpected values) becomes a manager, the safer role.
        DB::table('users')->update(['role_id' => $managerId]);
        DB::table('users')->where('role', 'admin')->update(['role_id' => $adminId]);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 16)->default('manager')->index();
        });

        // Custom roles fall back to «manager» (RB-06).
        $adminId = DB::table('roles')->where('code', 'admin')->value('id');

        if ($adminId !== null) {
            DB::table('users')->where('role_id', $adminId)->update(['role' => 'admin']);
        }

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropForeign(['role_id']);
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role_id');
        });

        $systemIds = DB::table('roles')->where('is_system', true)->pluck('id')->all();

        DB::table('role_permissions')->whereIn('role_id', $systemIds)->delete();
        DB::table('roles')->whereIn('id', $systemIds)->delete();
    }
};
