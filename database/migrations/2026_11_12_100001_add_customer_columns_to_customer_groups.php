<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CG-11, CG-12, CG-22, D-085, D-088: the columns of customer groups that start to act with customer accounts
 * (default group of new customers, «order only after login», default group of a wholesale approval) and the third
 * seeded group «Опт» (code `opt`; the discount fields do not exist before stage 12). «Розница» becomes the default
 * group for new customers. Idempotent: an existing `opt` group only gets the flags.
 */
return new class extends Migration
{
    /** Description of the group created by this migration; `down()` removes only a group that still carries it. */
    private const CREATED_DESCRIPTION = 'Оптовые покупатели (доступ по заявке)';

    public function up(): void
    {
        Schema::table('customer_groups', function (Blueprint $table): void {
            $table->boolean('is_default_for_new')->default(false);
            $table->boolean('require_login_to_order')->default(false);
            $table->boolean('is_wholesale_default')->default(false);
        });

        DB::table('customer_groups')->where('code', 'retail')->update(['is_default_for_new' => true]);

        $opt = DB::table('customer_groups')->where('code', 'opt')->first();

        if ($opt !== null) {
            DB::table('customer_groups')->where('id', $opt->id)->update([
                'require_login_to_order' => true,
                'is_wholesale_default' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        $now = now();

        DB::table('customer_groups')->insert([
            'code' => 'opt',
            'name' => 'Опт',
            'description' => self::CREATED_DESCRIPTION,
            'is_system' => false,
            'is_active' => true,
            'sort_order' => 20,
            'visible_sections' => json_encode(['catalog', 'search', 'featured', 'promotions', 'brands'], JSON_THROW_ON_ERROR),
            'category_access_mode' => 'all',
            'price_display' => 'show',
            'allowed_sales_modes' => null,
            'min_order_amount' => null,
            'allowed_delivery_codes' => null,
            'allowed_payment_codes' => null,
            'stock_display' => 'inherit',
            'is_default_for_new' => false,
            'require_login_to_order' => true,
            'is_wholesale_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // The customers table is already dropped by a later migration, so nothing references the group. A group that
        // existed before `up()` (it only got the flags then) has another description and is left alone.
        $optId = DB::table('customer_groups')
            ->where('code', 'opt')
            ->where('description', self::CREATED_DESCRIPTION)
            ->value('id');

        if ($optId !== null) {
            DB::table('customer_group_categories')->where('customer_group_id', $optId)->delete();
            DB::table('customer_groups')->where('id', $optId)->delete();
        }

        Schema::table('customer_groups', function (Blueprint $table): void {
            $table->dropColumn(['is_default_for_new', 'require_login_to_order', 'is_wholesale_default']);
        });
    }
};
