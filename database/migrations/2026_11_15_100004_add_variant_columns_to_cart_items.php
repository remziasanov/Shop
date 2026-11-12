<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VR-12: a cart row is one product or one variant of it. `variant_key` = COALESCE(variant_id, 0) is the technical
 * column of the unique index (NULLs never collide in MySQL and SQLite), maintained by the CartItem model.
 *
 * Index order matters on MySQL: the old unique(cart_id, product_id) is the index that serves the cart_id foreign key,
 * so the new unique(cart_id, product_id, variant_key) (which also starts with cart_id) is created BEFORE the old one is
 * dropped; down() does the reverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('variant_key')->default(0);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->unique(['cart_id', 'product_id', 'variant_key'], 'cart_items_cart_product_variant_unique');
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'product_id']);
        });
    }

    public function down(): void
    {
        // Rows of variants cannot exist under the old (cart, product) key.
        DB::table('cart_items')->where('variant_key', '!=', 0)->delete();

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->unique(['cart_id', 'product_id']);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique('cart_items_cart_product_variant_unique');
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropForeign(['variant_id']);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropColumn(['variant_id', 'variant_key']);
        });
    }
};
