<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/order_edit.php';

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\EditOrderContents;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\OrderChange;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\OrderItemSearch;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderTimeline;
use App\Settings\NotificationSettings;
use App\Support\Money;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
});

function editPage(Order $order, User $user): Testable
{
    actingAs($user);

    return Livewire::test(EditOrderContents::class, ['record' => $order->getRouteKey()]);
}

function firstRowKey(Testable $page): string
{
    return (string) array_key_first($page->get('data.items'));
}

// ------------------------------------------------------------------ card actions (OE-01, OE-12)

it('offers «Редактировать состав» on the card of an editable order and explains why it is disabled otherwise', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 1]]);
    actingAs(editManager());

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionVisible('edit_contents')
        ->assertActionEnabled('edit_contents');

    $order->forceFill(['status' => OrderStatus::Shipped])->save();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionVisible('edit_contents')
        ->assertActionDisabled('edit_contents');

    expect(OrderEditor::blockReason($order->refresh()))->toContain('Отправлен');
});

it('hides the editor for staff without orders.edit_items and for inquiries', function (): void {
    $order = editableOrder([[sellable(['stock_qty' => 10]), 1]]);
    $inquiry = Order::factory()->inquiry()->create();
    OrderItem::factory()->forProduct(sellable(), 1)->create(['order_id' => $inquiry->id]);

    actingAs(staffWith(['orders.view', 'orders.update']));
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('edit_contents');

    actingAs(editManager());
    Livewire::test(ViewOrder::class, ['record' => $inquiry->getRouteKey()])->assertActionHidden('edit_contents');
});

it('offers «Создать заказ из заявки» only on an open inquiry with the permission', function (): void {
    $inquiry = Order::factory()->inquiry()->create();
    OrderItem::factory()->forProduct(sellable(), 1)->create(['order_id' => $inquiry->id]);
    $order = editableOrder([[sellable(['stock_qty' => 10]), 1]]);

    actingAs(editManager());
    Livewire::test(ViewOrder::class, ['record' => $inquiry->getRouteKey()])->assertActionVisible('create_order');
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('create_order');

    actingAs(staffWith(['orders.view', 'orders.update']));
    Livewire::test(ViewOrder::class, ['record' => $inquiry->getRouteKey()])->assertActionHidden('create_order');

    $inquiry->forceFill(['status' => OrderStatus::Closed])->save();
    actingAs(editManager());
    Livewire::test(ViewOrder::class, ['record' => $inquiry->getRouteKey()])->assertActionHidden('create_order');
});

// ------------------------------------------------------------------ the editor page (OE-02, OE-10)

it('opens the editor with the positions, delivery and contacts of the order and the setting as the notify default', function (): void {
    $product = sellable(['stock_qty' => 10, 'name' => 'Чайник']);
    $order = editableOrder([[$product, 2]], OrderType::Order, ['customer_name' => 'Иван', 'delivery_method' => 'Курьер']);
    $settings = app(NotificationSettings::class);
    $settings->notify_customer_order_changed = false;
    $settings->save();

    $page = editPage($order, editManager());
    $row = $page->get('data.items')[firstRowKey($page)];

    expect($row['qty'])->toBe(2)->and($row['name'])->toBe('Чайник')->and($row['product_id'])->toBe($product->id);
    $page->assertSet('data.customer_name', 'Иван')
        ->assertSet('data.delivery_method', 'Курьер')
        ->assertSet('data.notify', false)
        ->assertSet('version', 1);
});

it('forbids the editor to staff without the permission and sends a non-editable order back to the card', function (): void {
    $order = editableOrder([[sellable(['stock_qty' => 10]), 1]]);

    actingAs(staffWith(['orders.view', 'orders.update']));
    Livewire::test(EditOrderContents::class, ['record' => $order->getRouteKey()])->assertForbidden();

    $order->forceFill(['status' => OrderStatus::Completed])->save();
    editPage($order, editManager())->assertRedirect(OrderResource::getUrl('view', ['record' => $order]));
});

it('previews the change and saves it on confirmation', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    $page = editPage($order, editManager());
    $key = firstRowKey($page);

    $page->set("data.items.{$key}.qty", 5)
        ->call('openPreview')
        ->assertActionMounted('confirmSave');

    expect($page->get('preview')['changed'])->toBeTrue()
        ->and($page->get('preview')['rows'][0]['stock'])->toBe('−3 со склада')
        ->and($product->fresh()->stock_qty)->toBe(8); // the preview writes nothing

    $page->callMountedAction()->assertHasNoActionErrors();

    expect($order->refresh()->items->first()->qty)->toBe(5)
        ->and($product->fresh()->stock_qty)->toBe(5)
        ->and((int) $order->version)->toBe(2)
        ->and(OrderChange::query()->where('order_id', $order->id)->exists())->toBeTrue();
});

it('does not open the confirmation when nothing changed', function (): void {
    $order = editableOrder([[sellable(['stock_qty' => 10]), 2]]);

    editPage($order, editManager())->call('openPreview')->assertActionNotMounted('confirmSave')->assertNotified(__('order_edit.preview.nothing'));
});

it('shows the shortage in the preview and keeps the order when it is saved anyway', function (): void {
    $product = sellable(['stock_qty' => 3, 'name' => 'Чайник']);
    $order = editableOrder([[$product, 2]]);
    $page = editPage($order, editManager());
    $key = firstRowKey($page);

    $page->set("data.items.{$key}.qty", 9)->call('openPreview')->assertActionMounted('confirmSave');

    expect($page->get('preview')['warnings'][0])->toContain('Чайник')->toContain('доступно 1');

    $page->callMountedAction();

    expect($order->refresh()->items->first()->qty)->toBe(2)->and($product->fresh()->stock_qty)->toBe(1);
});

it('reports a conflict when the order changed after the form was opened and keeps the typed data', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    $page = editPage($order, editManager());
    $key = firstRowKey($page);

    app(OrderService::class)->changeStatus($order->refresh(), OrderStatus::Confirmed, User::factory()->admin()->create(['name' => 'Сидоров']));

    $page->set("data.items.{$key}.qty", 4)->call('openPreview');
    $page->callMountedAction();

    expect($order->refresh()->items->first()->qty)->toBe(2)
        ->and($page->get('data.items')[$key]['qty'])->toBe(4);
});

it('ignores a price typed by a manager without orders.edit_prices, even when the state is tampered with', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    $page = editPage($order, editManager());
    $key = firstRowKey($page);

    $page->set("data.items.{$key}.price", '1.00')->set("data.items.{$key}.qty", 3)->set('data.manual_discount', '500')
        ->call('openPreview')->callMountedAction();

    $order->refresh();
    expect($order->items->first()->price)->toBe('1000.00')
        ->and($order->items->first()->qty)->toBe(3)
        ->and($order->manual_discount)->toBe('0.00');
});

it('saves a manual price and discount of an administrator and recalculates by the price list', function (): void {
    $product = sellable(['stock_qty' => 10, 'base_price' => '1000.00']);
    $order = editableOrder([[$product, 2]]);
    $page = editPage($order, editAdmin());
    $key = firstRowKey($page);

    $page->set("data.items.{$key}.price", '900')->set('data.manual_discount', '100')
        ->call('openPreview')->callMountedAction();

    $order->refresh();
    expect($order->items->first()->price)->toBe('900.00')
        ->and($order->items->first()->price_source->value)->toBe('manual')
        ->and($order->total)->toBe('1700.00');

    $page = editPage($order, editAdmin());
    $page->call('recalculatePrices');
    expect($page->get('data.items')[firstRowKey($page)]['price'])->toBe('1000.00')
        ->and($page->get('data.items')[firstRowKey($page)]['recalc'])->toBeTrue();

    $page->call('openPreview')->callMountedAction();

    expect($order->refresh()->items->first()->price)->toBe('1000.00')
        ->and($order->items->first()->price_source->value)->toBe('retail');
});

it('adds a product found by name, article or barcode and merges it into an existing row', function (): void {
    $a = sellable(['stock_qty' => 10, 'name' => 'Чайник синий', 'sku' => 'CH-77']);
    $b = sellable(['stock_qty' => 10, 'name' => 'Кружка', 'sku' => 'KR-5', 'base_price' => '250.00']);
    $order = editableOrder([[$a, 1]]);
    $page = editPage($order, editManager());

    expect(app(OrderItemSearch::class)->options('Кружка'))->toHaveKey($b->id.':0')
        ->and(app(OrderItemSearch::class)->options('CH-77'))->toHaveKey($a->id.':0');

    $page->call('addProduct', $b->id.':0')->call('addProduct', $b->id.':0');
    $rows = array_values($page->get('data.items'));

    expect($rows)->toHaveCount(2)
        ->and($rows[1]['qty'])->toBe(2)
        ->and($rows[1]['price'])->toBe('250.00')
        ->and($rows[1]['item_id'])->toBeNull();

    $page->call('openPreview')->callMountedAction();

    expect($order->refresh()->items)->toHaveCount(2)->and($b->fresh()->stock_qty)->toBe(8);
});

it('searches variants by their article and barcode and lists them with the label', function (): void {
    $color = optionWith('Цвет', ['Красный', 'Синий']);
    $card = variantCard(['name' => 'Футболка'], $color);
    $red = variantOf($card, [$color->values[0]], ['stock_qty' => 5, 'sku' => 'TS-RED', 'barcode' => '4600000000011']);
    variantOf($card, [$color->values[1]], ['stock_qty' => 5, 'sku' => 'TS-BLUE']);

    $byName = app(OrderItemSearch::class)->options('Футболка');
    $byBarcode = app(OrderItemSearch::class)->options('4600000000011');

    expect($byName)->toHaveCount(2)
        ->and($byName[$card->id.':'.$red->id])->toContain('Цвет: Красный')->toContain('TS-RED')
        ->and($byBarcode)->toHaveKey($card->id.':'.$red->id)
        ->and(OrderItemSearch::parse($card->id.':'.$red->id))->toBe([$card->id, $red->id])
        ->and(OrderItemSearch::parse('12:0'))->toBe([12, null])
        ->and(OrderItemSearch::parse('junk'))->toBeNull();
});

// ------------------------------------------------------------------ history tab (OE-07)

it('shows the change log merged with the status history on the card', function (): void {
    $product = sellable(['stock_qty' => 10, 'name' => 'Чайник']);
    $order = editableOrder([[$product, 2]]);
    $admin = User::factory()->admin()->create(['name' => 'Иванов']);
    app(OrderService::class)->changeStatus($order->refresh(), OrderStatus::Confirmed, $admin);
    saveEdit($order, withQty($order, $product, 4), $admin);

    $timeline = app(OrderTimeline::class)->for($order->refresh());

    expect($timeline[0]['type'])->toBe('batch')
        ->and($timeline[0]['user'])->toBe('Иванов')
        ->and(collect($timeline[0]['rows'])->pluck('title')->implode('|'))->toContain('Количество')
        ->and(collect($timeline)->pluck('type')->all())->toContain('status');

    actingAs($admin);
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSee('История изменений')
        ->assertSee('Иванов')
        ->assertSee('Количество');
});

// ------------------------------------------------------------------ inquiry → order (OE-12)

it('creates an order from an inquiry through the editor page', function (): void {
    $product = sellable(['stock_qty' => 10, 'base_price' => '300.00']);
    $inquiry = Order::factory()->inquiry()->create(['customer_email' => 'ivan@example.com']);
    OrderItem::factory()->forProduct($product, 2)->create(['order_id' => $inquiry->id, 'price' => null, 'total' => null]);
    $page = editPage($inquiry, editManager());

    $row = $page->get('data.items')[firstRowKey($page)];
    expect($row['item_id'])->toBeNull()->and((float) $row['price'])->toBe(300.0);

    $page->call('openPreview')->assertActionMounted('confirmSave');
    expect($page->get('preview')['totals'][0]['will'])->toBe(Money::format(60000));

    $page->callMountedAction();

    $order = Order::query()->where('source_order_id', $inquiry->id)->firstOrFail();
    expect($order->type)->toBe(OrderType::Order)
        ->and($order->total)->toBe('600.00')
        ->and($product->fresh()->stock_qty)->toBe(8)
        ->and($inquiry->refresh()->status)->toBe(OrderStatus::Closed);
});

// ------------------------------------------------------------------ default price of a new row (OE-03)

function tierGroup(): CustomerGroup
{
    require_once __DIR__.'/../../Support/group_prices.php';

    return CustomerGroup::factory()->discount(10)->showRetailPrice()->create(['name' => 'Опт']);
}

it('prices an added row untouched by the manager for the final quantity, not as a manual price (OE-03)', function (): void {
    $group = tierGroup();
    $product = sellable(['stock_qty' => 100, 'base_price' => '1000.00']);
    gpTier($product, $group, 10, '700.00');
    $other = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$other, 1]], OrderType::Order, ['customer_group_id' => $group->id, 'customer_group_name' => 'Опт']);
    $page = editPage($order, editAdmin());

    $page->call('addProduct', $product->id.':0');
    $key = (string) array_key_last($page->get('data.items'));
    expect((float) $page->get("data.items.{$key}.price"))->toBe(900.0);

    // The quantity moves the untouched price along (display), the tier applies.
    $page->set("data.items.{$key}.qty", 12);
    expect((float) $page->get("data.items.{$key}.price"))->toBe(700.0);

    $page->call('openPreview')->callMountedAction();

    $line = $order->refresh()->items->firstWhere('product_id', $product->id);
    expect($line->qty)->toBe(12)->and($line->price)->toBe('700.00')->and($line->price_source->value)->toBe('tier');
});

it('resolves the price of an untouched row on the server even when the displayed price is stale (OE-03)', function (): void {
    $group = tierGroup();
    $product = sellable(['stock_qty' => 100, 'base_price' => '1000.00']);
    gpTier($product, $group, 10, '700.00');
    $order = editableOrder([[sellable(['stock_qty' => 10]), 1]], OrderType::Order, ['customer_group_id' => $group->id, 'customer_group_name' => 'Опт']);
    $page = editPage($order, editAdmin());

    $page->call('addProduct', $product->id.':0');
    $key = (string) array_key_last($page->get('data.items'));
    // Qty set without the live hook (as a direct state change): the stale 900 must not become a manual price.
    $page->set("data.items.{$key}.list_price", '900.00')->set("data.items.{$key}.price", '900.00');
    $page->set("data.items.{$key}.qty", 12);
    $page->set("data.items.{$key}.price", '900.00')->set("data.items.{$key}.list_price", '900.00');
    $page->call('openPreview')->callMountedAction();

    $line = $order->refresh()->items->firstWhere('product_id', $product->id);
    expect($line->price)->toBe('700.00')->and($line->price_source->value)->toBe('tier');
});

it('keeps a price the administrator really typed on a new row as manual (OE-03)', function (): void {
    $group = tierGroup();
    $product = sellable(['stock_qty' => 100, 'base_price' => '1000.00']);
    gpTier($product, $group, 10, '700.00');
    $order = editableOrder([[sellable(['stock_qty' => 10]), 1]], OrderType::Order, ['customer_group_id' => $group->id, 'customer_group_name' => 'Опт']);
    $page = editPage($order, editAdmin());

    $page->call('addProduct', $product->id.':0');
    $key = (string) array_key_last($page->get('data.items'));
    $page->set("data.items.{$key}.qty", 12)->set("data.items.{$key}.price", '650')->call('openPreview')->callMountedAction();

    $line = $order->refresh()->items->firstWhere('product_id', $product->id);
    expect($line->price)->toBe('650.00')->and($line->price_source->value)->toBe('manual');
});

it('prices a row of an inquiry for the final quantity when «Создать заказ» is used (OE-03, OE-12)', function (): void {
    $group = tierGroup();
    $product = sellable(['stock_qty' => 100, 'base_price' => '1000.00']);
    gpTier($product, $group, 10, '700.00');
    $inquiry = Order::factory()->inquiry()->create(['customer_group_id' => $group->id, 'customer_group_name' => 'Опт']);
    OrderItem::factory()->forProduct($product, 2)->create(['order_id' => $inquiry->id, 'price' => null, 'total' => null]);
    $page = editPage($inquiry, editAdmin());
    $key = firstRowKey($page);

    $page->set("data.items.{$key}.qty", 12)->call('openPreview')->callMountedAction();

    $line = Order::query()->where('source_order_id', $inquiry->id)->firstOrFail()->items->first();
    expect($line->price)->toBe('700.00')->and($line->price_source->value)->toBe('tier');
});

it('logs the manager comment action as a change row and moves the version (OE-07, OE-08)', function (): void {
    $order = editableOrder([[sellable(['stock_qty' => 10]), 1]], OrderType::Order, ['manager_comment' => 'старый']);
    actingAs($manager = editManager());

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('manager_comment', ['manager_comment' => 'новый'])
        ->assertHasNoActionErrors();

    $change = OrderChange::query()->where('order_id', $order->id)->sole();
    expect($order->refresh()->manager_comment)->toBe('новый')
        ->and((int) $order->version)->toBe(2)
        ->and($order->updated_by_user_id)->toBe($manager->id)
        ->and($change->field)->toBe('manager_comment')
        ->and($change->kind->value)->toBe('field')
        ->and($change->old_value)->toBe('старый')
        ->and($change->new_value)->toBe('новый')
        ->and($change->user_id)->toBe($manager->id)
        ->and($change->batch_uuid)->not->toBeEmpty();
});
