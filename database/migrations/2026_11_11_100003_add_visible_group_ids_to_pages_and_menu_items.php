<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CG-10: groups a page / menu item is shown to (JSON list of group ids, null = every group).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pages', 'menu_items'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->json('visible_group_ids')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['pages', 'menu_items'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('visible_group_ids');
            });
        }
    }
};
