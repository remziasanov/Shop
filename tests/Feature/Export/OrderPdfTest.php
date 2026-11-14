<?php

declare(strict_types=1);

use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\CustomerCompany;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Export\CustomerOrdersExporter;
use App\Services\Export\OrderPdfRenderer;
use App\Settings\GeneralSettings;
use App\Settings\LegalSettings;
use FontLib\Font;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * CX-03, CX-10…CX-13 (TZ 18.9): the PDF documents: valid PDF with extractable Russian text, «Бланк» / «Счёт» by the bank
 * requisites, the «₽» sign, escaping, the admin PDF action.
 */
function cxPdfOrder(array $attributes = []): Order
{
    $order = Order::factory()->create($attributes + [
        'number' => 'Z-00123',
        'customer_name' => 'Иван Петров',
        'customer_phone' => '+79001234567',
        'delivery_method' => 'Курьер',
        'delivery_address' => 'Москва, ул. Тестовая, 1',
        'payment_method' => 'Картой',
        'delivery_price' => '300.00',
        'items_total' => '1000.00',
        'total' => '1300.00',
    ]);
    OrderItem::factory()->create(['order_id' => $order->id, 'sku' => 'KRT-1', 'name' => 'Куртка зимняя', 'qty' => 2, 'price' => '500.00', 'total' => '1000.00']);

    return $order->refresh();
}

function cxBankRequisites(): void
{
    setSettings(LegalSettings::class, [
        'seller_details' => "ООО «Ромашка»\nИНН 7707083893, КПП 770701001",
        'bank_name' => 'ПАО Тестбанк',
        'bik' => '044525225',
        'account' => '40702810000000000001',
        'corr_account' => '30101810400000000225',
    ]);
}

// ------------------------------------------------------------------ the font and the «₽» sign (CX-12)

it('has the Cyrillic alphabet and the ruble sign in every font face of the template', function (string $file): void {
    $font = Font::load(base_path('vendor/dompdf/dompdf/lib/fonts/'.$file));
    $font->parse();
    $map = $font->getUnicodeCharMap();

    foreach ([0x20BD, 0x0410, 0x044F, 0x0401, 0x0451, 0x2212, 0x2116, 0x00AB] as $codePoint) {
        expect(isset($map[$codePoint]))->toBeTrue('U+'.dechex($codePoint).' is missing in '.$file);
    }
})->with(['DejaVuSans.ttf', 'DejaVuSans-Bold.ttf']);

it('produces a valid PDF with the Russian text and «₽» in an extractable form (CX-12)', function (): void {
    $pdf = app(OrderPdfRenderer::class)->document(cxPdfOrder());

    $text = pdfText($pdf);

    expect($pdf)->toStartWith('%PDF-')
        ->and(rtrim($pdf))->toEndWith('%%EOF')
        ->and($text)->toContain('Куртка зимняя')
        ->and($text)->toContain('Иван Петров')
        ->and($text)->toContain('1 300 ₽')
        ->and($text)->toContain('Всего прописью')
        ->and($text)->toContain('Одна тысяча триста рублей 00 копеек');
});

it('numbers the pages', function (): void {
    expect(pdfText(app(OrderPdfRenderer::class)->document(cxPdfOrder())))->toContain('Страница 1 из 1');
});

// ------------------------------------------------------------------ «Бланк заказа» / «Счёт на оплату» (CX-10, CX-11)

it('makes a «Бланк заказа» while the bank requisites are empty', function (): void {
    $text = pdfText(app(OrderPdfRenderer::class)->document(cxPdfOrder(['created_at' => '2026-05-04 10:00:00'])));

    expect($text)->toContain('Бланк заказа № Z-00123 от 04.05.2026')
        ->and($text)->not->toContain('Счёт на оплату')
        ->and($text)->not->toContain('БИК');
});

it('makes a «Счёт на оплату» with the seller requisites once they are filled', function (): void {
    cxBankRequisites();

    $text = pdfText(app(OrderPdfRenderer::class)->document(cxPdfOrder(['created_at' => '2026-05-04 10:00:00'])));

    expect($text)->toContain('Счёт на оплату № Z-00123 от 04.05.2026')
        ->and($text)->not->toContain('Бланк заказа')
        ->and($text)->toContain('ПАО Тестбанк')
        ->and($text)->toContain('044525225')
        ->and($text)->toContain('40702810000000000001')
        ->and($text)->toContain('30101810400000000225')
        ->and($text)->toContain('ООО «Ромашка»')
        ->and($text)->toContain('ИНН 7707083893');
});

it('stays a blank when only part of the bank requisites is filled', function (): void {
    setSettings(LegalSettings::class, ['bank_name' => 'ПАО Тестбанк', 'bik' => '044525225', 'account' => null]);

    expect(pdfText(app(OrderPdfRenderer::class)->document(cxPdfOrder())))->toContain('Бланк заказа')->not->toContain('Счёт на оплату');
});

it('names the payer by the company requisites of the customer (CA-10), otherwise by name and phone', function (): void {
    $customer = makeCustomer();
    CustomerCompany::factory()->create(['customer_id' => $customer->id, 'name' => 'ООО «Покупатель»', 'inn' => '7707083893', 'kpp' => '770701001', 'legal_address' => 'Москва, ул. Юридическая, 5']);
    $withCompany = cxPdfOrder(['customer_id' => $customer->id, 'customer_name' => 'Менеджер Закупок']);
    $guest = cxPdfOrder(['number' => 'Z-00124', 'customer_name' => 'Гость Гостев', 'customer_phone' => '+79005550000']);

    $renderer = app(OrderPdfRenderer::class);
    $company = pdfText($renderer->document($withCompany));
    $plain = pdfText($renderer->document($guest));

    expect($company)->toContain('ООО «Покупатель»')->toContain('7707083893')->toContain('Юридическая')
        ->and($plain)->toContain('Гость Гостев')->toContain('+79005550000');
});

it('prints the discount lines, the VAT note and the footer', function (): void {
    setSettings(LegalSettings::class, ['vat_note' => 'НДС не облагается, УСН', 'invoice_footer' => 'Счёт действителен 3 дня']);
    $order = cxPdfOrder(['promo_code' => 'SPRING10', 'promo_discount' => '100.00', 'manual_discount' => '50.00', 'total' => '1150.00']);

    $text = pdfText(app(OrderPdfRenderer::class)->document($order));

    expect($text)->toContain('Промокод SPRING10')
        ->and($text)->toContain('−100 ₽')
        ->and($text)->toContain('−50 ₽')
        ->and($text)->toContain('Одна тысяча сто пятьдесят рублей 00 копеек')
        ->and($text)->toContain('НДС не облагается, УСН')
        ->and($text)->toContain('Счёт действителен 3 дня');
});

it('prints «Без НДС» by default and nothing when the note is empty', function (): void {
    $renderer = app(OrderPdfRenderer::class);

    expect(pdfText($renderer->document(cxPdfOrder())))->toContain('Без НДС');

    setSettings(LegalSettings::class, ['vat_note' => null]);

    expect(pdfText($renderer->document(cxPdfOrder(['number' => 'Z-00130']))))->not->toContain('Без НДС');
});

it('escapes user text instead of interpreting it as markup', function (): void {
    $order = cxPdfOrder(['customer_name' => '<b>Иван</b> & <script>alert(1)</script>', 'delivery_address' => '<img src="http://evil.example/x.png">']);
    OrderItem::factory()->create(['order_id' => $order->id, 'name' => '<i>Товар</i> {{ 7*7 }}']);
    setSettings(GeneralSettings::class, ['shop_name' => 'Магазин <u>Тест</u>']);

    $text = pdfText(app(OrderPdfRenderer::class)->document($order->refresh()));

    expect($text)->toContain('<b>Иван</b>')
        ->and($text)->toContain('<script>alert(1)</script>')
        ->and($text)->toContain('<img src="http://evil.example/x.png">')
        ->and($text)->toContain('<i>Товар</i> {{ 7*7 }}')
        ->and($text)->toContain('Магазин <u>Тест</u>');
});

// ------------------------------------------------------------------ history (CX-03)

it('renders the history with the customer, the period, the table and the total', function (): void {
    $customer = makeCustomer(['name' => 'Анна Кузнецова']);
    Order::factory()->create(['customer_id' => $customer->id, 'number' => 'Z-00001', 'total' => '100.00', 'created_at' => '2026-02-10 12:00:00']);
    Order::factory()->create(['customer_id' => $customer->id, 'number' => 'Z-00002', 'total' => '250.50', 'created_at' => '2026-02-11 12:00:00']);
    $params = ['from' => '2026-01-01', 'until' => '2026-03-31', 'types' => [], 'statuses' => [], 'with_items' => false];

    $pdf = app(OrderPdfRenderer::class)->history($customer, $params, false, app(CustomerOrdersExporter::class)->chunks($customer, $params, false));
    $text = pdfText($pdf);

    expect($pdf)->toStartWith('%PDF-')
        ->and($text)->toContain('История заказов')
        ->and($text)->toContain('Анна Кузнецова')
        ->and($text)->toContain('01.01.2026 — 31.03.2026')
        ->and($text)->toContain('Z-00001')->toContain('Z-00002')
        ->and($text)->toContain('Итого по периоду (обращений: 2)')
        ->and($text)->toContain('350,50 ₽');
});

it('renders a table of items under every order when the items flag is set', function (): void {
    $customer = makeCustomer();
    $order = Order::factory()->create(['customer_id' => $customer->id, 'number' => 'Z-00009', 'total' => '300.00', 'created_at' => '2026-02-10 12:00:00']);
    OrderItem::factory()->create(['order_id' => $order->id, 'sku' => 'SKU-77', 'name' => 'Шапка вязаная', 'qty' => 3, 'price' => '100.00', 'total' => '300.00']);
    $params = ['from' => '2026-01-01', 'until' => '2026-03-31', 'types' => [], 'statuses' => [], 'with_items' => true];

    $text = pdfText(app(OrderPdfRenderer::class)->history($customer, $params, true, app(CustomerOrdersExporter::class)->chunks($customer, $params, true)));

    expect($text)->toContain('Z-00009')->toContain('SKU-77')->toContain('Шапка вязаная');
});

it('embeds the logo as a data URI without touching files or the network', function (): void {
    Storage::fake('public');
    $image = imagecreatetruecolor(400, 200);
    ob_start();
    imagepng($image);
    Storage::disk('public')->put('settings/logo.png', (string) ob_get_clean());
    setSettings(GeneralSettings::class, ['logo' => 'settings/logo.png']);

    $pdf = app(OrderPdfRenderer::class)->document(cxPdfOrder());

    expect($pdf)->toStartWith('%PDF-')->and($pdf)->toContain('/Subtype /Image');
});

it('does not fetch remote resources or run PHP (dompdf options)', function (): void {
    $options = config('dompdf.options');

    expect($options['enable_remote'])->toBeFalse()
        ->and($options['enable_php'])->toBeFalse()
        ->and($options['enable_javascript'])->toBeFalse()
        ->and($options['default_font'])->toBe('DejaVu Sans')
        ->and($options['font_cache'])->toBe(storage_path('fonts'))
        ->and($options['chroot'])->toBe([realpath(resource_path('views/pdf'))]);
});

// ------------------------------------------------------------------ admin (CX-13)

it('serves the PDF document of an order to staff (CX-13)', function (string $role): void {
    actingAs(User::factory()->{$role}()->create());
    $order = cxPdfOrder();

    $response = $this->get(route('admin.orders.pdf', $order))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->getContent())->toStartWith('%PDF-')
        ->and(pdfText((string) $response->getContent()))->toContain('Бланк заказа № Z-00123');
})->with(['admin', 'manager']);

it('shows the PDF document as an invoice in the admin once the bank requisites are filled', function (): void {
    cxBankRequisites();
    actingAs(User::factory()->admin()->create());

    expect(pdfText((string) $this->get(route('admin.orders.pdf', cxPdfOrder()))->getContent()))->toContain('Счёт на оплату № Z-00123');
});

it('closes the admin PDF to guests', function (): void {
    $this->get(route('admin.orders.pdf', cxPdfOrder()))->assertRedirect();
});

it('has the «Печать / скачать PDF» action next to the print form on the order card (CX-13)', function (): void {
    actingAs(User::factory()->admin()->create());
    $order = cxPdfOrder();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionExists('print')
        ->assertActionExists('print_pdf')
        ->assertActionHasLabel('print_pdf', 'Печать / скачать PDF')
        ->assertActionHasUrl('print_pdf', route('admin.orders.pdf', $order));
});
