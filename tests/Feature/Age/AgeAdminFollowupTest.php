<?php

declare(strict_types=1);

use App\Enums\AgeRestriction;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->admin()->create());
});

it('filters the category list by the own age restriction (AG-12)', function () {
    $plain = Category::factory()->create(['name' => 'Мебель']);
    $sixteen = Category::factory()->create(['name' => 'Энергетики', 'age_restriction' => AgeRestriction::Age16]);
    $eighteen = Category::factory()->create(['name' => 'Табак', 'age_restriction' => AgeRestriction::Age18]);

    $list = Livewire::test(ListCategories::class)->assertCanSeeTableRecords([$plain, $sixteen, $eighteen]);

    $list->filterTable('age_restriction', '18')->assertCanSeeTableRecords([$eighteen])->assertCanNotSeeTableRecords([$plain, $sixteen]);
    $list->filterTable('age_restriction', '16')->assertCanSeeTableRecords([$sixteen])->assertCanNotSeeTableRecords([$plain, $eighteen]);
    $list->filterTable('age_restriction', 'none')->assertCanSeeTableRecords([$plain])->assertCanNotSeeTableRecords([$sixteen, $eighteen]);

    $list->removeTableFilter('age_restriction')->assertCanSeeTableRecords([$plain, $sixteen, $eighteen]);
});

it('shows the confirmed age level in the order card when the order has one (AG-11)', function () {
    $order = Order::factory()->create(['age_confirmed_level' => AgeRestriction::Age18]);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertOk()
        ->assertSee('Возраст подтверждён: 18+');
});

it('hides the confirmed age entry in the order card when no level was confirmed (AG-11)', function () {
    $order = Order::factory()->create(['age_confirmed_level' => null]);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertOk()
        ->assertDontSee('Возраст подтверждён');
});
