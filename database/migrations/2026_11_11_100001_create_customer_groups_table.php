<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CG-01…CG-10, CG-27: customer groups with the access columns that act in stage 8 (D-085: columns that start to
 * act later — default group for new customers, «order only after login», discounts, order blank — arrive with their
 * stages). The system groups «Гость» and «Розница» are seeded with defaults that keep v1.0 behaviour (CG-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            // CG-04: list of StorefrontSection values.
            $table->json('visible_sections');
            // CG-05: all / all_except / only (varchar, PHP enum).
            $table->string('category_access_mode', 16)->default('all');
            // CG-06: show / login / request.
            $table->string('price_display', 16)->default('show');
            // CG-07: subset of cart, reservation, inquiry; null = all.
            $table->json('allowed_sales_modes')->nullable();
            // CG-08: null = the value from the sales settings.
            $table->decimal('min_order_amount', 12, 2)->nullable();
            // CG-09: stable `code` keys of the sales settings; null = all.
            $table->json('allowed_delivery_codes')->nullable();
            $table->json('allowed_payment_codes')->nullable();
            // CG-27: inherit / exact / yes_no / low.
            $table->string('stock_display', 16)->default('inherit');
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();
        $sections = json_encode(['catalog', 'search', 'featured', 'promotions', 'brands'], JSON_THROW_ON_ERROR);

        foreach ([
            ['guest', 'Гость', 'Посетители без входа в личный кабинет', 0],
            ['retail', 'Розница', 'Зарегистрированные покупатели', 10],
        ] as [$code, $name, $description, $sort]) {
            DB::table('customer_groups')->insert([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'is_system' => true,
                'is_active' => true,
                'sort_order' => $sort,
                'visible_sections' => $sections,
                'category_access_mode' => 'all',
                'price_display' => 'show',
                'allowed_sales_modes' => null,
                'min_order_amount' => null,
                'allowed_delivery_codes' => null,
                'allowed_payment_codes' => null,
                'stock_display' => 'inherit',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_groups');
    }
};
