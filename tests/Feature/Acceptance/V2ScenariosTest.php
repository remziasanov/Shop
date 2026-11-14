<?php

declare(strict_types=1);

use App\Enums\AgeGateMode;
use App\Enums\AgeRestriction;
use App\Enums\AttributeType;
use App\Enums\AuditAction;
use App\Enums\BulkPriceOperation;
use App\Enums\CategoryAccessMode;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Enums\FilterSource;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Enums\OptionDisplayType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PriceDisplayMode;
use App\Enums\PriceSource;
use App\Enums\SalesMode;
use App\Enums\SystemRole;
use App\Enums\VerificationPurpose;
use App\Enums\WholesaleApplicationStatus;
use App\Exceptions\CartChangedException;
use App\Exceptions\ConcurrentEditException;
use App\Exceptions\SalesException;
use App\Exceptions\StaffAccessException;
use App\Filament\Pages\Settings\ManageGeneralSettings;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WholesaleApplications\Pages\ViewWholesaleApplication;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Livewire\Storefront\CartPage;
use App\Livewire\Storefront\CheckoutForm;
use App\Mail\CustomerCodeMail;
use App\Mail\OrderItemsChangedCustomerMail;
use App\Mail\WholesaleApplicationShopMail;
use App\Mail\WholesaleDecisionMail;
use App\Models\AdminAuditEntry;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerCompany;
use App\Models\CustomerGroup;
use App\Models\CustomerNotification;
use App\Models\Export;
use App\Models\Import;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderChange;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Models\User;
use App\Models\WholesaleApplication;
use App\Services\Cart\CartService;
use App\Services\Catalog\CatalogVersion;
use App\Services\Export\CatalogExporter;
use App\Services\Import\ImportReader;
use App\Services\Import\ImportRunner;
use App\Services\Orders\CheckoutData;
use App\Services\Orders\EditData;
use App\Services\Orders\EditItem;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\OrderService;
use App\Services\Pricing\BulkPriceChanger;
use App\Services\Promo\PromoBatchService;
use App\Services\Promo\PromoLinkStats;
use App\Services\Sales\CheckoutMethods;
use App\Services\Seo\SitemapGenerator;
use App\Services\Users\RoleService;
use App\Services\Users\StaffAccountService;
use App\Settings\CatalogSettings;
use App\Settings\CustomerSettings;
use App\Settings\NotificationSettings;
use App\Settings\TelegramSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;
use Tests\TestCase;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/../../Support/home.php';
require_once __DIR__.'/../../Support/variant_storefront.php';
require_once __DIR__.'/../../Support/order_edit.php';
require_once __DIR__.'/../../Support/reservation_cart.php';

/**
 * TZ section 14, scenarios 11-21 (version 2.0). What cannot be automated (real mailboxes, Telegram, the photo of a
 * T-shirt in a browser, the Beget server) is the manual checklist of docs/PROGRESS.md, stage 15.
 */
beforeEach(function (): void {
    $this->withoutVite();
    resetFormGuard();
    RateLimiter::clear('login');
    Cache::flush();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0, 'reservation_hours' => 48]);
});

function v2Opt(): CustomerGroup
{
    return CustomerGroup::query()->where('code', 'opt')->firstOrFail();
}

/**
 * Starts the next storefront request as the signed-in customer: the session of the guard and a fresh viewer.
 */
function v2ActAs(TestCase $test, Customer $customer): void
{
    promoSignIn($test, $customer);
}

/**
 * Wholesale prices of the item for the group: exact price (min_qty 1) and tiers.
 *
 * @param  array<int, string>  $tiers  min_qty => price
 */
function v2Prices(Product $product, CustomerGroup $group, array $tiers): void
{
    foreach ($tiers as $minQty => $price) {
        gpTier($product, $group, $minQty, $price);
    }

    CatalogVersion::bump();
}

// ---------------------------------------------------------------------------------------------
// Scenario 11. Путь оптового покупателя
// ---------------------------------------------------------------------------------------------

it('11. wholesale path: registration, requisites, application, approval, group prices, tiers, limits, snapshot', function (): void {
    Mail::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    $settings = app(NotificationSettings::class);
    $settings->notify_emails = ['shop@example.com'];
    $telegram = app(TelegramSettings::class);
    $telegram->enabled = true;
    $telegram->bot_token = '123456:SECRET-token_abc';
    $telegram->work_chat_id = '-1001';
    $telegram->save();
    $settings->save();

    // The shop: a hidden-from-retail category, wholesale prices, minimum sum and the allowed methods of «Опт».
    $sets = Category::factory()->create(['name' => 'Оптовые наборы', 'slug' => 'optovye-nabory']);
    $general = Category::factory()->create(['name' => 'Посуда', 'slug' => 'posuda']);
    $mug = groupProduct($general, 'Кружка', ['base_price' => '1200.00', 'stock_qty' => 1000, 'sales_mode' => SalesMode::Cart]);
    $box = groupProduct($sets, 'Набор для чая', ['base_price' => '1500.00', 'stock_qty' => 1000, 'sales_mode' => SalesMode::Cart]);
    $opt = v2Opt();
    v2Prices($mug, $opt, [1 => '1000.00', 10 => '900.00']);
    v2Prices($box, $opt, [1 => '1000.00']);
    $opt->update(['min_order_amount' => '18000.00', 'allowed_delivery_codes' => ['kurerskaia-dostavka'], 'allowed_payment_codes' => ['kartoi-pri-polucenii']]);
    configureGuest(['price_display' => PriceDisplayMode::Login], CategoryAccessMode::AllExcept, [$sets]);

    // The guest: prices are hidden, the wholesale category does not exist.
    $this->get('/product/'.$mug->slug)->assertOk()->assertSee('Войдите, чтобы увидеть цену')->assertDontSeeText('1200');
    $this->get('/catalog/'.$sets->slug)->assertNotFound();

    // Registration with the e-mail code (CA-02..04).
    $this->post(route('customer.register.store'), registrationBody(['email' => 'buyer@firm.example']))->assertRedirect(route('customer.confirm'));
    $this->post(route('customer.confirm.store'), ['code' => lastSentCode(VerificationPurpose::Register)])->assertRedirect(route('account'));
    $customer = Customer::query()->where('email', 'buyer@firm.example')->firstOrFail();
    expect($customer->email_verified_at)->not->toBeNull()->and($customer->customer_group_id)->toBe(CustomerGroup::retail()->id);

    // Company requisites (the INN passes the check digit test) and the application.
    $this->post(route('customer.company.save'), ['name' => 'ООО «Ромашка»', 'inn' => '7707083893', 'kpp' => '770701001', 'ogrn' => '1027700132195', 'legal_address' => 'г. Москва, ул. Примерная, 1'])
        ->assertRedirect(route('customer.company'));
    expect(CustomerCompany::query()->where('customer_id', $customer->id)->value('inn'))->toBe('7707083893');

    $this->post(route('customer.company.save'), ['name' => 'ООО «Плохое»', 'inn' => '7707083894'])->assertSessionHasErrors('inn');

    $this->post(route('customer.wholesale.store'), [
        'company_name' => 'ООО «Ромашка»', 'inn' => '7707083893', 'kpp' => '770701001', 'contact_name' => 'Иван Тестов', 'phone' => '8 (900) 123-45-67',
        'comment' => 'Берём оптом', 'consent' => '1', 'website' => '', 'form_token' => formToken(),
    ])->assertRedirect(route('customer.wholesale'));

    $application = WholesaleApplication::query()->sole();
    expect($application->status)->toBe(WholesaleApplicationStatus::New);
    Mail::assertSent(WholesaleApplicationShopMail::class, fn (WholesaleApplicationShopMail $mail): bool => $mail->hasTo('shop@example.com'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sendMessage') && str_contains((string) $request['text'], 'ООО «Ромашка»'));

    // The manager approves with the group «Опт»: letter and a notice in the cabinet.
    $manager = User::factory()->manager()->create();
    actingAs($manager);
    Livewire::test(ViewWholesaleApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('approve', ['group_id' => $opt->id])
        ->assertNotified(__('wholesale.actions.approved'));

    expect($customer->refresh()->customer_group_id)->toBe($opt->id);
    Mail::assertQueued(WholesaleDecisionMail::class, fn (WholesaleDecisionMail $mail): bool => $mail->hasTo('buyer@firm.example') && $mail->approved);
    expect(CustomerNotification::query()->where('customer_id', $customer->id)->count())->toBe(1);

    // Wholesale prices in the list and on the card, and the category hidden from retail.
    auth()->guard('web')->logout();
    nextRequest();
    signInCustomer($this, $customer);
    $this->get(route('customer.notifications'))->assertOk()->assertSee('Опт');

    nextRequest();
    $this->get('/catalog/'.$general->slug)->assertOk()->assertSeeText("1\u{202F}000\u{00A0}₽");
    nextRequest();
    $this->get('/product/'.$mug->slug)->assertOk()->assertSeeText("1\u{202F}000\u{00A0}₽")->assertDontSee('Войдите, чтобы увидеть цену');
    nextRequest();
    $this->get('/catalog/'.$sets->slug)->assertOk()->assertSee('Набор для чая');

    // The cart: 9 pcs cost 1 000, the hint offers the tier, 10 pcs cost 900 and the hint is gone.
    v2ActAs($this, $customer);
    $carts = app(CartService::class);
    $cart = $carts->getOrCreate(request());
    $carts->add($cart, $mug, 9);
    $line = $carts->summary($cart)->lines[0];
    expect($line->currentPrice)->toBe(100_000)->and($line->tierHint)->not->toBeNull()->and($line->tierHint->missingQty)->toBe(1);

    livewireWithCart(CartPage::class, $cart)->assertSeeHtml('data-tier-hint')->assertSee('Ещё 1 шт — и цена станет')->assertSeeText("900\u{00A0}₽");

    $carts->update($cart, $mug->id, 10);
    $line = $carts->summary($cart)->lines[0];
    expect($line->currentPrice)->toBe(90_000)->and($line->priceSource)->toBe(PriceSource::Tier)->and($line->tierHint)->toBeNull();
    livewireWithCart(CartPage::class, $cart)->assertDontSeeHtml('data-tier-hint');

    // The group minimum (18 000): 15 000 is refused, 20 000 passes.
    $carts->add($cart, $box, 6);
    $summary = $carts->summary($cart);
    expect($summary->itemsTotal)->toBe(1_500_000)->and($summary->minOrderAmount)->toBe(1_800_000)->and($summary->canCheckout)->toBeFalse();

    $data = fn (string $delivery, string $payment) => new CheckoutData('Иван Тестов', '8 (900) 123-45-67', 'buyer@firm.example', $delivery, 'г. Москва, ул. Примерная, 1', $payment, null, true, '127.0.0.1', 'Pest');
    expect(fn () => app(OrderService::class)->createFromCart($cart, $data('kurerskaia-dostavka', 'kartoi-pri-polucenii')))->toThrow(SalesException::class, 'Минимальная сумма');

    $carts->update($cart, $box->id, 11);
    expect($carts->summary($cart)->itemsTotal)->toBe(2_000_000)->and($carts->summary($cart)->canCheckout)->toBeTrue();

    // Only the delivery and payment methods of the group are offered and accepted.
    expect(array_map(fn ($o) => $o->key, app(CheckoutMethods::class)->deliveries()))->toBe(['kurerskaia-dostavka'])
        ->and(array_map(fn ($o) => $o->key, app(CheckoutMethods::class)->payments()))->toBe(['kartoi-pri-polucenii']);
    expect(fn () => app(OrderService::class)->createFromCart($cart, $data('samovyvoz', 'kartoi-pri-polucenii')))->toThrow(SalesException::class);

    $order = app(OrderService::class)->createFromCart($cart, $data('kurerskaia-dostavka', 'kartoi-pri-polucenii'));

    expect($order->customer_id)->toBe($customer->id)
        ->and($order->customer_group_id)->toBe($opt->id)
        ->and($order->customer_group_name)->toBe('Опт')
        ->and($order->items_total)->toBe('20000.00');
    $tier = $order->items->firstWhere('product_id', $mug->id);
    expect($tier->price)->toBe('900.00')->and($tier->price_source)->toBe(PriceSource::Tier)->and($tier->tier_min_qty)->toBe(10)->and($tier->retail_price)->toBe('1200.00');

    // The order card of the manager: group, price source and the snapshot.
    actingAs(User::factory()->admin()->create());
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSee('Группа покупателей')
        ->assertSee('Опт')
        ->assertSee('Ступень от 10 шт');

    // CX: the customer exports the history of the quarter (PDF and Excel) and downloads «Счёт / бланк заказа»;
    // an export file of another customer is not reachable.
    Storage::fake('local');
    auth()->guard('web')->logout();
    nextRequest();
    signInCustomer($this, $customer);
    $quarter = ['from' => now()->subMonths(3)->toDateString(), 'until' => now()->toDateString()];
    $body = static function (TestResponse $response): string {
        $base = $response->baseResponse;

        return $base instanceof BinaryFileResponse ? (string) file_get_contents($base->getFile()->getPathname()) : (string) ($response->streamedContent() ?: $response->getContent());
    };

    $pdf = $this->post(route('customer.exports.store'), $quarter + ['format' => 'pdf', 'with_items' => '1'])->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(pdfText($body($pdf)))->toContain($order->number)->toContain('Кружка');

    nextRequest();
    $xlsx = $this->post(route('customer.exports.store'), $quarter + ['format' => 'xlsx', 'with_items' => '1'])->assertOk();
    expect($xlsx->headers->get('Content-Type'))->toContain('spreadsheetml');

    nextRequest();
    $blank = $this->get(route('customer.orders.blank', $order->uuid))->assertOk();
    expect($blank->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(pdfText($body($blank)))->toContain($order->number)->toContain('ООО «Ромашка»');

    $foreign = Export::factory()->create([
        'customer_id' => Customer::factory()->create()->id, 'user_id' => null, 'uuid' => (string) Str::uuid(),
        'type' => ExportType::CustomerOrdersPdf, 'status' => ExportStatus::Completed, 'expires_at' => now()->addDay(),
    ]);
    nextRequest();
    $this->get(route('customer.exports.download', $foreign->uuid))->assertNotFound();
});

// ---------------------------------------------------------------------------------------------
// Scenario 12. Скрытие для гостя и SEO
// ---------------------------------------------------------------------------------------------

it('12. a category hidden from «Гость»: 404, no menu, search, suggestions or sitemap; the wholesaler gets noindex and private', function (): void {
    config(['app.url' => 'https://shop.example']);
    $open = Category::factory()->create(['name' => 'Посуда', 'slug' => 'posuda']);
    $sets = Category::factory()->create(['name' => 'Оптовые наборы', 'slug' => 'optovye-nabory']);
    groupProduct($open, 'Открытый чайник', ['slug' => 'open-teapot']);
    $hidden = groupProduct($sets, 'Оптовый чайник', ['slug' => 'wholesale-teapot']);
    MenuItem::factory()->forCategory($open)->create(['label' => 'Меню посуда']);
    MenuItem::factory()->forCategory($sets)->create(['label' => 'Меню оптовые наборы']);
    configureGuest([], CategoryAccessMode::AllExcept, [$sets]);

    $this->get('/catalog/'.$sets->slug)->assertNotFound();
    $this->get('/product/'.$hidden->slug)->assertNotFound();
    $this->get('/')->assertOk()->assertSee('Меню посуда')->assertDontSee('Меню оптовые наборы');
    $this->get('/catalog')->assertOk()->assertDontSee('Оптовые наборы')->assertDontSee('Оптовый чайник');
    $this->get('/search?q='.rawurlencode('чайник'))->assertOk()->assertSee('Открытый чайник')->assertDontSee('Оптовый чайник');
    $this->getJson('/search/suggest?q='.rawurlencode('чайник'))->assertOk()->assertJsonFragment(['name' => 'Открытый чайник'])->assertJsonMissing(['name' => 'Оптовый чайник']);

    $dir = storage_path('framework/testing/sitemap-'.uniqid());
    File::ensureDirectoryExists($dir);
    config(['shop.sitemap_dir' => $dir]);
    app(SitemapGenerator::class)->generate();
    $xml = (string) file_get_contents($dir.'/sitemap.xml');
    File::deleteDirectory($dir);
    expect($xml)->toContain('posuda')->not->toContain('optovye-nabory')->not->toContain('wholesale-teapot');

    // A wholesaler (a group that sees every category) opens it, the page is private and not indexable.
    $customer = makeCustomer(['customer_group_id' => v2Opt()->id]);
    signInCustomer($this, $customer);
    nextRequest();

    $page = $this->get('/catalog/'.$sets->slug)->assertOk()->assertSee('Оптовый чайник');
    expect($page->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and((string) $page->headers->get('Cache-Control'))->toContain('private');
    $page->assertSee('<meta name="robots" content="noindex,nofollow">', false);

    nextRequest();
    $product = $this->get('/product/'.$hidden->slug)->assertOk();
    expect((string) $product->headers->get('Cache-Control'))->toContain('private');

    // The page of a category open to everybody stays public for the guest.
    $this->flushSession();
    nextRequest();
    expect($this->get('/catalog/'.$open->slug)->assertOk()->headers->get('X-Robots-Tag'))->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// Scenario 13. Промо-ссылка
// ---------------------------------------------------------------------------------------------

it('13. promo link: 302 with UTM, click statistics, code applied in the cart, attributed order, expired link, no double discount', function (): void {
    $browser = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
    $jacket = sellable(['name' => 'Куртка', 'slug' => 'kurtka', 'base_price' => '1000.00', 'stock_qty' => 20]);
    $sale = sellable(['name' => 'Шапка по акции', 'slug' => 'shapka', 'base_price' => '1000.00', 'compare_at_price' => '1250.00', 'stock_qty' => 20]);
    $code = PromoCode::factory()->percent(10)->create(['code' => 'SPRING10']);
    $link = PromoLink::factory()->toProduct($jacket)->create(['slug' => 'kurtka', 'promo_code_id' => $code->id, 'utm_source' => 'tg', 'utm_campaign' => 'spring']);

    $response = $this->withHeader('User-Agent', $browser)->get('/go/kurtka')
        ->assertStatus(302)->assertRedirect(url('/product/kurtka?utm_source=tg&utm_campaign=spring'));
    $cookies = ['shop_pl' => $response->getCookie('shop_pl')?->getValue(), 'shop_promo' => $response->getCookie('shop_promo')?->getValue()];
    expect($cookies)->toBe(['shop_pl' => (string) $link->id, 'shop_promo' => 'SPRING10']);

    $this->followingRedirects()->withHeader('User-Agent', $browser)->get('/go/kurtka')->assertOk()->assertSee('Куртка');
    expect(app(PromoLinkStats::class)->summary($link)['clicks'])->toBe(2);

    // The cart: the code of the link is already applied, with a discount on the line. The item on promotion gets no second discount.
    Livewire::withCookie('shop_pl', $cookies['shop_pl'])->withCookie('shop_promo', 'SPRING10');
    $cart = promoCart($jacket, 2);
    CartItem::factory()->forProduct($sale, 1)->create(['cart_id' => $cart->id]);

    livewireWithCart(CartPage::class, $cart)
        ->assertSee('Промокод SPRING10 применён')
        ->assertSeeHtml('data-line-old')
        ->assertSee('Скидка по промокоду SPRING10');
    expect($cart->refresh()->promo_code)->toBe('SPRING10');

    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();
    fillCheckout($component)->call('submit')->assertHasNoErrors();

    $order = Order::query()->with('items')->firstOrFail();
    $discounts = $order->items->mapWithKeys(fn (OrderItem $item): array => [$item->name => $item->promo_discount])->all();
    expect($order->promo_link_id)->toBe($link->id)
        ->and($order->promo_code)->toBe('SPRING10')
        ->and($discounts)->toBe(['Куртка' => '200.00', 'Шапка по акции' => '0.00'])
        ->and($order->total)->toBe('2800.00');

    $summary = app(PromoLinkStats::class)->summary($link);
    expect($summary['orders'])->toBe(1)->and($summary['revenue'])->toBe(280_000)->and($summary['clicks'])->toBe(2);

    // An expired link leads to the catalog with the notice.
    $link->update(['ends_at' => now()->subDay()]);
    $this->withHeader('User-Agent', $browser)->get('/go/kurtka')->assertRedirect(route('catalog.index'))->assertSessionHas('promo_link_notice', 'Ссылка устарела');
    $this->followingRedirects()->withHeader('User-Agent', $browser)->get('/go/kurtka')->assertOk()->assertSee('Ссылка устарела');
});

// ---------------------------------------------------------------------------------------------
// Scenario 14. Одноразовые коды
// ---------------------------------------------------------------------------------------------

it('14. a batch of 100 one-time codes: Excel export, one use per code, the second order refused, cancel frees the code', function (): void {
    Storage::fake('local');
    $product = sellable(['name' => 'Лампа', 'base_price' => '1000.00', 'stock_qty' => 20]);
    $batch = app(PromoBatchService::class)->create('Подарки', 'GIFT-', 100, 8, ['type' => 'percent', 'value' => '15.00']);

    expect(PromoCode::query()->where('batch_id', $batch->id)->count())->toBe(100)
        ->and(PromoCode::query()->where('batch_id', $batch->id)->where('code', 'not like', 'GIFT-%')->count())->toBe(0);

    $export = app(PromoBatchService::class)->requestExport($batch, User::factory()->admin()->create());
    $rows = array_values(iterator_to_array((new ImportReader)->open(Storage::disk('local')->path((string) $export->file_path), 'Коды партии')));
    expect($export->type)->toBe(ExportType::PromoBatch)->and($export->status)->toBe(ExportStatus::Completed)->and($rows)->toHaveCount(101)
        ->and($rows[1][0])->toStartWith('GIFT-')->and($rows[1][1])->toBe('Не использован');

    $gift = (string) PromoCode::query()->where('batch_id', $batch->id)->orderBy('id')->value('code');

    $first = promoCheckout(promoCart($product, 1, $gift), email: 'a@example.com', phone: '+7 900 111-11-11');
    expect($first->promo_code)->toBe($gift)->and($first->total)->toBe('850.00');

    try {
        promoCheckout(promoCart($product, 1, $gift), email: 'b@example.com', phone: '+7 900 222-22-22');
        $this->fail('The code is one-time.');
    } catch (CartChangedException $e) {
        expect($e->reasons)->toBe(['Промокод исчерпан']);
    }

    app(OrderService::class)->changeStatus($first, OrderStatus::Cancelled);

    $again = promoCheckout(promoCart($product, 1, $gift), email: 'b@example.com', phone: '+7 900 222-22-22');
    expect($again->promo_code)->toBe($gift);
});

// ---------------------------------------------------------------------------------------------
// Scenario 15. Модификации
// ---------------------------------------------------------------------------------------------

it('15. variants: swatches and buttons, shaded combination, «от X ₽», cart label, per-variant stock, snapshot, import round trip', function (): void {
    $color = optionWith('Цвет', ['Красный', 'Синий'], true);
    $color->values[0]->update(['hex' => '#ff0000']);
    $color->values[1]->update(['hex' => '#0000ff']);
    $size = optionWith('Размер', ['S', 'M', 'L'], false);
    $size->update(['display_type' => OptionDisplayType::Button]);
    $card = variantCard(['name' => 'Футболка', 'sku' => 'TEE'], $color, $size);
    $variants = [];

    foreach ($color->values as $c => $colorValue) {
        foreach ($size->values as $s => $sizeValue) {
            $key = $colorValue->value.'|'.$sizeValue->value;
            $variants[$key] = variantOf($card, [$colorValue, $sizeValue], [
                'sku' => 'TEE-'.($c === 0 ? 'R' : 'B').'-'.$sizeValue->value,
                'base_price' => (string) (1000 + 100 * $s).'.00',
                'stock_qty' => $key === 'Синий|L' ? 0 : 5,
                'sort_order' => $c * 3 + $s,
            ]);
        }
    }

    expect(ProductVariant::query()->where('product_id', $card->id)->count())->toBe(6);

    // The listing shows the lowest price.
    $this->get(route('catalog.category', $card->category->slug))->assertOk()->assertSeeText("от 1\u{202F}000\u{00A0}₽");

    // The card: the colour is a swatch, the size a button, the sold-out combination is shaded.
    $html = $this->get(route('product.show', $card->slug))->assertOk()->getContent();
    $data = gpVariantData($html);
    expect(array_column($data['axes'], 'display'))->toBe(['color', 'button'])
        ->and($data['variants'])->toHaveCount(6);
    $sold = collect($data['variants'])->firstWhere('sku', 'TEE-B-L');
    expect($sold['c'] & 8)->toBe(8)->and($sold['c'] & 1)->toBe(0);

    // «Красный, M» goes to the cart; the order decreases the stock of this variant only and rolls up the card.
    $redM = $variants['Красный|M'];
    $carts = app(CartService::class);
    $cart = Cart::factory()->create();
    $carts->add($cart, $card, 1, $redM->id);
    expect($carts->summary($cart)->lines[0]->variantLabel)->toBe('Цвет: Красный, Размер: M');

    $before = ProductVariant::query()->pluck('stock_qty', 'id')->all();
    $order = app(OrderService::class)->createFromCart($cart, checkoutData());

    $after = ProductVariant::query()->pluck('stock_qty', 'id')->all();
    foreach ($after as $id => $stock) {
        expect($stock)->toBe($id === $redM->id ? 4 : $before[$id]);
    }

    expect($card->refresh()->stock_qty)->toBe(array_sum($after));
    $item = $order->items->sole();
    expect($item->variant_label)->toBe('Цвет: Красный, Размер: M')->and($item->sku)->toBe('TEE-R-M')->and($item->variant_id)->toBe($redM->id);

    // Import of a file with «Артикул карточки» and option columns recreates the modifications; export -> import changes nothing.
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
    $rows = [['Артикул', 'Артикул карточки', 'Название', 'Категория', 'Цена', 'Остаток', 'Опция: Цвет', 'Опция: Размер'], ['SHIRT', '', 'Рубашка', 'Одежда', '2000', '', '', '']];
    foreach (['R' => 'Красный', 'B' => 'Синий'] as $colorCode => $colorName) {
        foreach (['S', 'M', 'L'] as $sizeName) {
            $rows[] = ['SHIRT-'.$colorCode.$sizeName, 'SHIRT', '', '', '2000', '3', $colorName, $sizeName];
        }
    }

    $import = ImportHarness::run(ImportHarness::analyzed(ImportFiles::csv($rows)));
    $shirt = Product::query()->where('sku', 'SHIRT')->firstOrFail();
    expect($import->status)->toBe(ImportStatus::Completed)->and($import->error_count)->toBe(0)
        ->and(ProductVariant::query()->where('product_id', $shirt->id)->count())->toBe(6)
        ->and($shirt->has_variants)->toBeTrue();

    $snapshot = fn (): array => ProductVariant::query()->orderBy('id')->get(['id', 'sku', 'base_price', 'stock_qty', 'is_active'])->toArray();
    $before = $snapshot();
    $path = ImportFiles::tempPath('xlsx');
    app(CatalogExporter::class)->export([], $path);
    $round = ImportHarness::run(ImportHarness::analyzed($path));

    expect($round->status)->toBe(ImportStatus::Completed)->and($round->error_count)->toBe(0)
        ->and($round->created_count)->toBe(0)->and($round->updated_count)->toBe(0)
        ->and($snapshot())->toEqual($before);
});

// ---------------------------------------------------------------------------------------------
// Scenario 16. Фильтры
// ---------------------------------------------------------------------------------------------

it('16. filters: union within a filter, intersection across, counters, reproducible link, indexing rules, ≤ 30 queries', function (): void {
    attrMigrated();
    setSettings(CatalogSettings::class, ['seo_single_value_min_products' => 1]);
    config(['app.url' => 'https://shop.example']);
    $category = Category::factory()->create(['slug' => 'odezhda', 'name' => 'Одежда']);
    $material = facetAttr('material', AttributeType::List, ['name' => 'Материал', 'index_single_value' => true]);
    $cotton = facetValue($material, 'cotton', 'Хлопок', 1);
    $linen = facetValue($material, 'linen', 'Лён', 2);
    $wool = facetValue($material, 'wool', 'Шерсть', 3);
    $power = facetAttr('power', AttributeType::Number, ['name' => 'Мощность']);
    facetSet($category, [$material, $power]);

    $shirt = vsShirt(['category_id' => $category->id, 'name' => 'Футболка']);
    v2OptionSet($category, [$shirt['color'], $shirt['size']]);
    $shirtCard = $shirt['card'];
    foreach ([[$material, $cotton]] as [$attribute, $value]) {
        ProductAttributeValue::query()->create(['product_id' => $shirtCard->id, 'attribute_id' => $attribute->id, 'attribute_value_id' => $value->id]);
    }

    $a = facetProduct($category, [[$material, $cotton], [$power, '100']], ['name' => 'Пледик']);
    $b = facetProduct($category, [[$material, $linen], [$power, '200']], ['name' => 'Лён-блуза']);
    $c = facetProduct($category, [[$material, $wool], [$power, '300']], ['name' => 'Шерсть-свитер']);

    $url = route('catalog.category', 'odezhda');
    $names = fn (array $query): array => collect(test()->followingRedirects()->get($url.'?'.http_build_query($query))->assertOk()->viewData('cards'))->map(fn ($v) => $v->product->name)->sort()->values()->all();

    expect($names([]))->toHaveCount(4)
        ->and($names(['a' => ['material' => ['cotton', 'linen']]]))->toBe(['Лён-блуза', 'Пледик', 'Футболка']);

    $red = $shirt['color']->values[0]->slug;
    $selection = ['a' => ['material' => ['cotton', 'linen']], 'o' => [$shirt['color']->slug => [$red]]];
    expect($names($selection))->toBe(['Футболка']);

    // Counters of the other values follow the selection (the facet of the colour after material = cotton + linen).
    $response = $this->get($url.'?'.http_build_query(['a' => ['material' => ['cotton', 'linen']]]))->assertOk();
    $facets = collect($response->viewData('facets'))->keyBy('slug');
    $colorCounts = collect($facets[$shirt['color']->slug]->values)->mapWithKeys(fn ($v) => [$v->label => $v->count])->all();
    expect($colorCounts['Красный'])->toBe(1);

    // The link reproduces the selection; another spelling of the same selection is the same page.
    $link = $url.'?'.http_build_query($selection);
    expect(collect($this->followingRedirects()->get($link)->viewData('cards'))->map(fn ($v) => $v->product->name)->all())->toBe(['Футболка']);

    // Indexing: one value of a flagged characteristic only; every other combination is noindex,follow.
    $single = $this->followingRedirects()->get($url.'?'.http_build_query(['a' => ['material' => ['cotton']]]))->getContent();
    expect($single)->not->toContain('name="robots"');
    $material->update(['index_single_value' => false]);
    expect($this->followingRedirects()->get($url.'?'.http_build_query(['a' => ['material' => ['cotton']]]))->getContent())->toContain('<meta name="robots" content="noindex,follow">');
    expect($this->followingRedirects()->get($link)->getContent())->toContain('<meta name="robots" content="noindex,follow">');

    // The query budget.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get($link)->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($count)->toBeLessThanOrEqual(30);
});

function v2OptionSet(Category $category, array $types): void
{
    foreach (array_values($types) as $position => $type) {
        CategoryFilter::factory()->create([
            'category_id' => $category->id,
            'source' => FilterSource::Option,
            'attribute_id' => null,
            'option_type_id' => $type->id,
            'sort_order' => ($position + 1) * 10,
        ]);
    }

    CatalogVersion::bump();
}

// ---------------------------------------------------------------------------------------------
// Scenario 17. Редактирование заказа
// ---------------------------------------------------------------------------------------------

it('17. order editing: preview, stock, change log, customer letter, idempotent save, concurrent status change, prices, inquiry to order', function (): void {
    Mail::fake();
    $a = sellable(['name' => 'Чайник', 'sku' => 'CH-1', 'stock_qty' => 10, 'base_price' => '1000.00']);
    $b = sellable(['name' => 'Кружка', 'sku' => 'KR-1', 'stock_qty' => 10, 'base_price' => '500.00']);
    $c = sellable(['name' => 'Ложка', 'sku' => 'LO-1', 'stock_qty' => 10, 'base_price' => '100.00']);
    $order = editableOrder([[$a, 2], [$b, 3]], OrderType::Order, ['customer_email' => 'ivan@example.com', 'number' => 'Z-00042']);
    expect([$a->fresh()->stock_qty, $b->fresh()->stock_qty])->toBe([8, 7]);

    $manager = editManager();
    $form = addItem(withQty($order, $b, 5), $c, 1);
    $form = $form->with(['items' => array_values(array_filter($form->items, fn ($item): bool => $item->productId !== $a->id)), 'notifyCustomer' => true]);

    // The preview shows «было / станет» and the stock impact and writes nothing.
    $plan = app(OrderEditor::class)->preview($order->id, $form, $manager);
    expect($plan->oldTotal)->toBe(350_000)->and($plan->newTotal)->toBe(260_000)
        ->and(count($plan->lines))->toBeGreaterThanOrEqual(2);
    expect($a->fresh()->stock_qty)->toBe(8)->and(OrderChange::query()->count())->toBe(0);

    // Saving: returned and deducted stock, log rows with the user, the letter «Состав заказа изменён».
    $version = (int) $order->refresh()->version;
    app(OrderEditor::class)->apply($order->id, $version, $form, $manager);

    expect([$a->fresh()->stock_qty, $b->fresh()->stock_qty, $c->fresh()->stock_qty])->toBe([10, 5, 9])
        ->and($order->refresh()->total)->toBe('2600.00')
        ->and($order->items)->toHaveCount(2);
    $rows = OrderChange::query()->where('order_id', $order->id)->get();
    expect($rows->isNotEmpty())->toBeTrue()->and($rows->pluck('user_id')->unique()->all())->toBe([$manager->id])
        ->and($rows->every(fn (OrderChange $row): bool => $row->created_at !== null))->toBeTrue()
        ->and($rows->pluck('kind')->map(fn ($kind) => $kind->value)->unique()->values()->all())->toContain('item_removed', 'item_added', 'item_qty');
    Mail::assertSent(OrderItemsChangedCustomerMail::class, fn ($mail): bool => $mail->hasTo('ivan@example.com'));

    // Saving the same form again (a fresh version) changes nothing.
    $changes = OrderChange::query()->count();
    app(OrderEditor::class)->apply($order->id, (int) $order->refresh()->version, v2SameForm($order), $manager);
    expect([$a->fresh()->stock_qty, $b->fresh()->stock_qty, $c->fresh()->stock_qty])->toBe([10, 5, 9])->and(OrderChange::query()->count())->toBe($changes);

    // Another tab changed the status meanwhile: the save is refused.
    $stale = (int) $order->refresh()->version;
    app(OrderService::class)->changeStatus($order->refresh(), OrderStatus::Confirmed, editAdmin());
    expect(fn () => app(OrderEditor::class)->apply($order->id, $stale, withQty($order, $b, 6), $manager))->toThrow(ConcurrentEditException::class);
    expect($b->fresh()->stock_qty)->toBe(5);

    // A manager cannot change a price or the discount (orders.edit_prices).
    $item = $order->refresh()->items->first();
    expect(fn () => saveEdit($order, editData($order, ['items' => [new EditItem($item->id, null, null, $item->qty, 1)]]), $manager))->toThrow(AuthorizationException::class)
        ->and(fn () => saveEdit($order, editData($order, ['manualDiscount' => 10_000]), $manager))->toThrow(AuthorizationException::class);

    // An inquiry becomes an order with a button: the stock is deducted and the inquiry is closed.
    $inquiry = Order::factory()->inquiry()->create(['customer_email' => 'ivan@example.com']);
    OrderItem::factory()->forProduct($c, 2)->create(['order_id' => $inquiry->id, 'price' => null, 'total' => null]);
    $inquiry->refresh();
    $form = new EditData([new EditItem(null, $c->id, null, 2)], $inquiry->customer_name, $inquiry->customer_phone, $inquiry->customer_email, 'Самовывоз', null, 0, 0, null, null, false);
    $created = app(OrderService::class)->createFromInquiry($inquiry, (int) $inquiry->version, $form, $manager);

    expect($created->type)->toBe(OrderType::Order)->and($c->fresh()->stock_qty)->toBe(7)
        ->and($inquiry->refresh()->status)->toBe(OrderStatus::Closed);
});

function v2SameForm(Order $order): EditData
{
    return editData($order->refresh()->load('items'));
}

// ---------------------------------------------------------------------------------------------
// Scenario 18. Резерв нескольких товаров
// ---------------------------------------------------------------------------------------------

it('18. reservation of several products: two linked requests, one term, stock returned on expiry, all-or-nothing', function (): void {
    sales(['reservation_via_cart' => true, 'reservation_max_items' => 20]);
    $chairs = [
        mrReservable(['name' => 'Кресло', 'stock_qty' => 5, 'base_price' => '3000.00']),
        mrReservable(['name' => 'Стол', 'stock_qty' => 4, 'base_price' => '5000.00']),
        mrReservable(['name' => 'Шкаф', 'stock_qty' => 3, 'base_price' => '9000.00']),
    ];
    $lamp = sellable(['name' => 'Лампа', 'sales_mode' => SalesMode::Cart, 'stock_qty' => 9, 'base_price' => '800.00']);
    $cart = mrCart([[$chairs[0], 2], [$chairs[1], 1], [$chairs[2], 1], [$lamp, 1]]);

    // The checkout shows both blocks and the explanation of two requests.
    livewireWithCart(CheckoutForm::class, $cart)
        ->assertSeeHtml('data-checkout-block="order"')
        ->assertSeeHtml('data-checkout-block="reservation"')
        ->assertSeeHtml('data-mixed-note')
        ->assertSee('Вы получите два номера');

    $result = app(OrderService::class)->checkoutFromCart($cart, mrMixedData());

    expect($result->all())->toHaveCount(2)
        ->and($result->reservation->items)->toHaveCount(3)
        ->and($result->order->items)->toHaveCount(1)
        ->and($result->order->fresh()->related_order_id)->toBe($result->reservation->id)
        ->and($result->reservation->fresh()->related_order_id)->toBe($result->order->id)
        ->and($result->reservation->reserved_until)->not->toBeNull()
        ->and([$chairs[0]->fresh()->reserved_qty, $chairs[1]->fresh()->reserved_qty, $chairs[2]->fresh()->reserved_qty])->toBe([2, 1, 1])
        ->and($lamp->fresh()->stock_qty)->toBe(8);

    // The term passes: every position is released.
    Carbon::setTestNow(now()->addHours(49));
    expect(app(OrderService::class)->expireReservations())->toBe(1);
    expect($result->reservation->refresh()->status)->toBe(OrderStatus::Expired)
        ->and(array_map(fn (Product $p): int => $p->fresh()->reserved_qty, $chairs))->toBe([0, 0, 0]);
    Carbon::setTestNow();

    // One position is short: nothing is created and nothing is held.
    $short = mrCart([[$chairs[0], 1], [$chairs[1], 3]]);
    $chairs[1]->forceFill(['reserved_qty' => 3])->save();
    $orders = Order::query()->count();

    expect(fn () => app(OrderService::class)->checkoutFromCart($short, mrReserveData()))->toThrow(CartChangedException::class);
    expect(Order::query()->count())->toBe($orders)->and($chairs[0]->fresh()->reserved_qty)->toBe(0)->and($chairs[1]->fresh()->reserved_qty)->toBe(3);
});

// ---------------------------------------------------------------------------------------------
// Scenario 19. Возрастное ограничение
// ---------------------------------------------------------------------------------------------

it('19. age gate: site mode overlay, answers, restricted mode blur, search robots, checkbox at checkout', function (): void {
    ageGate(['mode' => AgeGateMode::Site, 'site_min_age' => 18, 'remember_days' => 7]);
    $tobacco = ageCategory(AgeRestriction::Age18, name: 'Табак');
    $plain = ageCategory(null, name: 'Мебель');
    $cigar = ageProduct($tobacco, ['name' => 'Сигары', 'slug' => 'cigars', 'is_featured' => true, 'stock_qty' => 5, 'sales_mode' => SalesMode::Both]);
    $chair = ageProduct($plain, ['name' => 'Стул', 'slug' => 'stul']);

    // Mode «for the whole site»: the overlay on the first visit; «Да» hides it for N days, «Нет» goes home with a notice.
    $this->get(route('home'))->assertOk()->assertSee('data-age-need="18"', false)->assertSee('role="dialog"', false);
    $yes = $this->post(route('age-gate.confirm'), ['level' => '18', 'return' => '/catalog'])->assertRedirect('/catalog');
    $cookie = $yes->getCookie('shop_age', false);
    expect($cookie->getValue())->toBe('18')->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(6)->getTimestamp());
    $this->withUnencryptedCookie('shop_age', '18')->get(route('home'))->assertDontSee('data-age-need', false);
    $this->defaultCookies = $this->unencryptedCookies = [];
    $this->followingRedirects()->get(route('age-gate.decline', ['level' => '18']))->assertOk()->assertSee('Мне уже есть 18');

    // Mode «restricted only»: no overlay at home, blurred 18+ cards in lists, the overlay on the page, a robot sees everything.
    ageGate(['mode' => AgeGateMode::Restricted]);
    $this->defaultCookies = $this->unencryptedCookies = [];
    $this->get(route('home'))->assertOk()->assertDontSee('data-age-need', false);
    $list = $this->get(route('catalog.index'))->assertOk()->getContent();
    expect($list)->toContain('data-age="18"')->and(preg_match_all('/<article[^>]*data-age=/', $list))->toBe(1);
    $this->get(route('product.show', 'cigars'))->assertOk()->assertSee('data-age-need="18"', false);
    $this->get(route('product.show', 'stul'))->assertOk()->assertDontSee('data-age-need', false);
    $this->get(route('catalog.category', $tobacco->slug))->assertOk()->assertSee('data-age-need="18"', false);
    $this->withUnencryptedCookie('shop_age', '18')->get(route('product.show', 'cigars'))->assertOk()->assertDontSee('data-age-need', false);
    $this->defaultCookies = $this->unencryptedCookies = [];

    $bot = ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
    $robot = $this->withHeaders($bot)->get(route('product.show', 'cigars'))->assertOk()->assertSee('Сигары');
    expect($robot->getContent())->not->toContain('noindex')->and($robot->headers->get('X-Robots-Tag'))->toBeNull();

    // At checkout the age checkbox is required for a restricted product and the order stores the level.
    $cart = cartWith($cigar);
    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();
    fillCheckout($component)->call('submit')->assertHasErrors(['ageConfirmed' => 'accepted']);
    expect(Order::query()->count())->toBe(0);
    $component->set('ageConfirmed', true)->call('submit')->assertHasNoErrors();
    expect(Order::query()->firstOrFail()->age_confirmed_level)->toBe(AgeRestriction::Age18);

    // Without restricted products the gate shows nothing.
    $cigar->update(['is_active' => false]);
    ageGate(['mode' => AgeGateMode::Site]);
    $this->get(route('home'))->assertOk()->assertDontSee('data-age-need', false);
});

// ---------------------------------------------------------------------------------------------
// Scenario 20. Кабинет и персональные данные
// ---------------------------------------------------------------------------------------------

it('20. cabinet and personal data: guest order linking, reorder, e-mail change with a code, 5 wrong codes, deletion', function (): void {
    Mail::fake();
    $product = sellable(['name' => 'Кружка', 'stock_qty' => 20, 'base_price' => '500.00']);

    // A guest order with the e-mail; the same e-mail is registered and confirmed: the order is in the history.
    $guestOrder = app(OrderService::class)->createFromCart(cartWith($product), checkoutData());
    expect($guestOrder->customer_id)->toBeNull();

    $this->post(route('customer.register.store'), registrationBody(['email' => 'ivan@example.com']))->assertRedirect(route('customer.confirm'));
    expect($guestOrder->fresh()->customer_id)->toBeNull();
    $this->post(route('customer.confirm.store'), ['code' => lastSentCode(VerificationPurpose::Register)])->assertRedirect(route('account'));
    $customer = Customer::query()->where('email', 'ivan@example.com')->firstOrFail();
    expect($guestOrder->fresh()->customer_id)->toBe($customer->id);
    $this->get(route('customer.orders'))->assertOk()->assertSee($guestOrder->number);

    // Repeat: the available items go to the cart at the current price.
    $product->update(['base_price' => '700.00']);
    $this->post(route('customer.orders.repeat', $guestOrder->uuid))->assertRedirect(route('cart'));
    $row = CartItem::query()->firstOrFail();
    expect($row->product_id)->toBe($product->id)->and($row->price_at_add)->toBe('700.00');

    // The e-mail change goes through a code sent to the NEW address; 5 wrong codes kill the code.
    $this->post(route('customer.email.request'), ['email' => 'new.ivan@example.com'])->assertRedirect(route('customer.email.confirm'));
    Mail::assertQueued(CustomerCodeMail::class, fn ($mail): bool => $mail->hasTo('new.ivan@example.com'));
    $code = lastSentCode(VerificationPurpose::ChangeEmail);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $_) {
        $this->post(route('customer.email.confirm.store'), ['code' => $wrong])->assertSessionHasErrors('code');
    }

    $this->post(route('customer.email.confirm.store'), ['code' => $code])->assertSessionHasErrors('code');
    expect($customer->fresh()->email)->toBe('ivan@example.com');

    // A new code works.
    $this->travel(2)->minutes();
    $this->post(route('customer.email.confirm.resend'))->assertRedirect();
    $this->post(route('customer.email.confirm.store'), ['code' => lastSentCode(VerificationPurpose::ChangeEmail)])->assertRedirect(route('customer.profile'));
    expect($customer->fresh()->email)->toBe('new.ivan@example.com');

    // Deleting the account: a code, then anonymization after the cancel period; the orders stay without personal data.
    $this->post(route('customer.security.delete.request'))->assertRedirect(route('customer.security.delete'));
    $this->post(route('customer.security.delete.confirm'), ['code' => lastSentCode(VerificationPurpose::DeleteAccount)])->assertRedirect(route('customer.login'));
    expect($customer->fresh()->deletion_requested_at)->not->toBeNull()->and($customer->fresh()->anonymized_at)->toBeNull();

    $this->travel((int) app(CustomerSettings::class)->deletion_grace_days + 1)->days();
    $this->artisan('shop:anonymize-customers')->assertSuccessful();

    $fresh = $customer->fresh();
    expect($fresh->anonymized_at)->not->toBeNull()
        ->and($fresh->name)->toBe('Удалённый покупатель')
        ->and($fresh->email)->not->toContain('ivan')
        ->and($fresh->phone)->toBeNull()
        ->and(CustomerAddress::query()->where('customer_id', $customer->id)->count())->toBe(0);
    $kept = $guestOrder->fresh();
    expect($kept->customer_name)->toBe('Удалённый покупатель')->and($kept->customer_email)->toBeNull()->and($kept->customer_phone)->toBe('')
        ->and($kept->total)->toBe('500.00')->and($kept->items()->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Scenario 21. Роли сотрудников
// ---------------------------------------------------------------------------------------------

it('21. staff roles: a Content manager role, hidden menu, 403s, no bulk prices or full sync, last admin protection, audit log', function (): void {
    $admin = User::factory()->admin()->create();
    $roles = app(RoleService::class);

    $role = $roles->create(['code' => 'content', 'name' => 'Контент-менеджер'], [
        'dashboard.view', 'products.view', 'products.create', 'products.update', 'products.delete',
        'categories.view', 'pages.view', 'pages.create', 'pages.update', 'pages.delete',
    ], $admin);
    $editor = app(StaffAccountService::class)->create([
        'name' => 'Мария', 'email' => 'maria@shop.example', 'password' => 'long-enough-pass', 'role_id' => $role->id, 'is_active' => true,
    ], $admin);

    actingAs($editor);
    $this->get('/admin')->assertOk()
        ->assertDontSee(__('admin_orders.plural'))
        ->assertDontSee(__('shop.navigation.settings'));
    foreach ([OrderResource::getUrl('index'), ImportResource::getUrl('index'), UserResource::getUrl('index'), ManageGeneralSettings::getUrl()] as $url) {
        $this->get($url)->assertForbidden();
    }

    // The mass price change and the full synchronization are refused even when called directly.
    $product = Product::factory()->create(['base_price' => '100.00']);
    expect(fn () => app(BulkPriceChanger::class)->dispatch([$product->id], BulkPriceOperation::IncreasePercent, '10', $editor))
        ->toThrow(AuthorizationException::class);
    $import = Import::factory()->status(ImportStatus::Analyzed)->mode(ImportMode::FullSync)->create();
    expect(fn () => app(ImportRunner::class)->start($import, $editor))->toThrow(AuthorizationException::class);
    expect($product->fresh()->base_price)->toBe('100.00');

    // The administrator cannot take their own rights away or deactivate the last administrator.
    $staff = app(StaffAccountService::class);
    expect(fn () => $staff->update($admin, ['role_id' => roleId(SystemRole::Manager)], $admin))->toThrow(ValidationException::class)
        ->and(fn () => $staff->update($admin, ['is_active' => false], $admin))->toThrow(ValidationException::class)
        ->and(fn () => $staff->delete($admin, $admin))->toThrow(StaffAccessException::class)
        ->and($admin->fresh()->is_active)->toBeTrue();

    // The actions are in the journal, without personal data.
    $actions = AdminAuditEntry::query()->pluck('action')->map(fn ($action) => $action instanceof AuditAction ? $action->value : (string) $action)->all();
    expect($actions)->toContain(AuditAction::RoleCreated->value, AuditAction::UserCreated->value)
        ->and(json_encode(AdminAuditEntry::query()->get()->toArray()))->not->toContain('maria@shop.example');
});
