<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Export\CustomerOrdersExporter;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * CX-02, TZ 18.8: the Excel file of the order history: sheets «Заказы» and «Состав», their columns, formula injection.
 */
beforeEach(function (): void {
    $this->customer = makeCustomer();
    $this->path = tempnam(sys_get_temp_dir(), 'cx').'.xlsx';
});

afterEach(function (): void {
    @unlink($this->path);
});

/**
 * @return list<array<string, mixed>>
 */
function cxSheetRows(string $path, string $sheet): array
{
    return SimpleExcelReader::create($path, 'xlsx')->fromSheetName($sheet)->getRows()->all();
}

function cxExcelParams(array $overrides = []): array
{
    return $overrides + ['from' => now()->subMonth()->toDateString(), 'until' => now()->toDateString(), 'types' => [], 'statuses' => [], 'with_items' => false];
}

it('writes the sheet «Заказы» with the columns of CX-02, one row per order', function (): void {
    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'number' => 'Z-00042',
        'delivery_method' => 'Курьер',
        'delivery_price' => '300.00',
        'items_total' => '1000.00',
        'total' => '1200.00',
        'promo_discount' => '70.00',
        'manual_discount' => '30.00',
        'created_at' => now()->subDays(2)->setTime(10, 30),
    ]);
    OrderItem::factory()->count(3)->create(['order_id' => $order->id]);

    $rows = app(CustomerOrdersExporter::class)->writeXlsx($this->customer, cxExcelParams(), $this->path);

    $sheet = cxSheetRows($this->path, 'Заказы');

    expect($rows)->toBe(1)
        ->and($sheet)->toHaveCount(1)
        ->and(array_keys($sheet[0]))->toBe(['Номер', 'Тип', 'Дата', 'Статус', 'Способ получения', 'Доставка', 'Скидка', 'Итого'])
        ->and($sheet[0]['Номер'])->toBe('Z-00042')
        ->and($sheet[0]['Тип'])->toBe('Заказ')
        ->and($sheet[0]['Способ получения'])->toBe('Курьер')
        ->and((float) $sheet[0]['Доставка'])->toBe(300.0)
        ->and((float) $sheet[0]['Скидка'])->toBe(100.0)
        ->and((float) $sheet[0]['Итого'])->toBe(1200.0)
        ->and($sheet[0]['Дата'])->toBe($order->created_at->format('d.m.Y H:i'));
});

it('has no «Состав» sheet without the items flag', function (): void {
    Order::factory()->create(['customer_id' => $this->customer->id]);

    app(CustomerOrdersExporter::class)->writeXlsx($this->customer, cxExcelParams(), $this->path);

    $names = SimpleExcelReader::create($this->path, 'xlsx')->getSheetNames();

    expect(array_values($names))->toBe(['Заказы']);
});

it('writes the sheet «Состав» with one row per item', function (): void {
    $order = Order::factory()->create(['customer_id' => $this->customer->id, 'number' => 'Z-00001']);
    OrderItem::factory()->create(['order_id' => $order->id, 'sku' => 'A-1', 'name' => 'Футболка', 'variant_label' => 'Цвет: красный, Размер: M', 'qty' => 3, 'price' => '100.50', 'total' => '301.50']);
    OrderItem::factory()->create(['order_id' => $order->id, 'sku' => 'B-2', 'name' => 'Кепка', 'qty' => 1, 'price' => '50.00', 'total' => '50.00']);
    $other = Order::factory()->create(['customer_id' => makeCustomer()->id, 'number' => 'Z-99999']);
    OrderItem::factory()->create(['order_id' => $other->id, 'sku' => 'FOREIGN']);

    $rows = app(CustomerOrdersExporter::class)->writeXlsx($this->customer, cxExcelParams(['with_items' => true]), $this->path);

    $items = cxSheetRows($this->path, 'Состав');

    expect($rows)->toBe(3)
        ->and($items)->toHaveCount(2)
        ->and(array_keys($items[0]))->toBe(['Номер заказа', 'Артикул', 'Название', 'Модификация', 'Кол-во', 'Цена', 'Сумма'])
        ->and($items[0]['Номер заказа'])->toBe('Z-00001')
        ->and($items[0]['Артикул'])->toBe('A-1')
        ->and($items[0]['Модификация'])->toBe('Цвет: красный, Размер: M')
        ->and((int) $items[0]['Кол-во'])->toBe(3)
        ->and((float) $items[0]['Цена'])->toBe(100.5)
        ->and((float) $items[0]['Сумма'])->toBe(301.5)
        ->and(collect($items)->pluck('Артикул')->all())->not->toContain('FOREIGN');
});

it('prefixes values that look like formulas with an apostrophe (formula injection)', function (): void {
    $order = Order::factory()->create(['customer_id' => $this->customer->id, 'number' => 'Z-00007', 'delivery_method' => '=HYPERLINK("http://evil.example")']);
    OrderItem::factory()->create(['order_id' => $order->id, 'sku' => '+SKU', 'name' => '@SUM(A1)', 'variant_label' => '-2+3']);
    OrderItem::factory()->create(['order_id' => $order->id, 'sku' => 'OK-1', 'name' => 'Обычный товар']);

    app(CustomerOrdersExporter::class)->writeXlsx($this->customer, cxExcelParams(['with_items' => true]), $this->path);

    $orders = cxSheetRows($this->path, 'Заказы');
    $items = cxSheetRows($this->path, 'Состав');

    expect($orders[0]['Способ получения'])->toBe("'=HYPERLINK(\"http://evil.example\")")
        ->and($items[0]['Артикул'])->toBe("'+SKU")
        ->and($items[0]['Название'])->toBe("'@SUM(A1)")
        ->and($items[0]['Модификация'])->toBe("'-2+3")
        ->and($items[1]['Артикул'])->toBe('OK-1')
        ->and($items[1]['Название'])->toBe('Обычный товар');
});

it('applies the safe text rule to the edge cases', function (): void {
    expect(CustomerOrdersExporter::safeText('=1+1'))->toBe("'=1+1")
        ->and(CustomerOrdersExporter::safeText('+7 900'))->toBe("'+7 900")
        ->and(CustomerOrdersExporter::safeText('-x'))->toBe("'-x")
        ->and(CustomerOrdersExporter::safeText('@a'))->toBe("'@a")
        ->and(CustomerOrdersExporter::safeText("\t=1"))->toBe("'\t=1")
        ->and(CustomerOrdersExporter::safeText('Z-00001'))->toBe('Z-00001')
        ->and(CustomerOrdersExporter::safeText(''))->toBe('')
        ->and(CustomerOrdersExporter::safeText(null))->toBeNull();
});

it('writes an export of more than one chunk of items completely', function (): void {
    $order = Order::factory()->create(['customer_id' => $this->customer->id]);
    OrderItem::factory()->count(CustomerOrdersExporter::ITEM_CHUNK + 20)->create(['order_id' => $order->id]);

    $rows = app(CustomerOrdersExporter::class)->writeXlsx($this->customer, cxExcelParams(['with_items' => true]), $this->path);

    expect($rows)->toBe(CustomerOrdersExporter::ITEM_CHUNK + 21)
        ->and(cxSheetRows($this->path, 'Состав'))->toHaveCount(CustomerOrdersExporter::ITEM_CHUNK + 20);
});

it('does not export requests of another customer even with the same period', function (): void {
    $foreign = Customer::factory()->create();
    Order::factory()->create(['customer_id' => $foreign->id, 'number' => 'Z-FOREIGN']);

    app(CustomerOrdersExporter::class)->writeXlsx($this->customer, cxExcelParams(), $this->path);

    expect(cxSheetRows($this->path, 'Заказы'))->toBe([]);
});
