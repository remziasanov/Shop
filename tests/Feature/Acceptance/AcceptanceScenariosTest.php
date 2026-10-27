<?php

declare(strict_types=1);

use App\Console\Commands\RecalcPricesCommand;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PriceChangeSource;
use App\Enums\SalesMode;
use App\Filament\Pages\ManageHomePage;
use App\Filament\Pages\Settings\ManageAppearanceSettings;
use App\Filament\Pages\Settings\ManageGeneralSettings;
use App\Filament\Pages\Settings\ManageNotificationSettings;
use App\Filament\Pages\Settings\ManagePricingSettings;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Users\UserResource;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Livewire\Storefront\CheckoutForm;
use App\Livewire\Storefront\ReservationForm;
use App\Mail\NewRequestShopMail;
use App\Mail\OrderPlacedCustomerMail;
use App\Models\Category;
use App\Models\Import;
use App\Models\Order;
use App\Models\PriceHistory;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Export\CatalogExporter;
use App\Services\Import\HostResolver;
use App\Services\Pricing\PriceRuleData;
use App\Services\Pricing\PriceRuleRecalculator;
use App\Settings\GeneralSettings;
use App\Settings\NotificationSettings;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Support\FakeHostResolver;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/../../Support/home.php';

/**
 * TZ section 14: acceptance scenarios of the MVP that can be automated. The manual ones (Beget hosting,
 * Lighthouse on the production site) are listed in docs/PROGRESS.md, stage 7.
 */
beforeEach(function (): void {
    $this->withoutVite();
    resetFormGuard();
    RateLimiter::clear('login');
});

function acceptanceBoundaryFile(): string
{
    return RecalcPricesCommand::lastRunPath();
}

/**
 * The scheduler stamp of `shop:recalc-prices --boundaries` is a real file; keep the developer's one intact.
 */
function keepBoundaryStampDuring(Closure $callback): void
{
    $path = acceptanceBoundaryFile();
    $backup = is_file($path) ? file_get_contents($path) : null;
    @unlink($path);

    try {
        $callback();
    } finally {
        $backup === null ? @unlink($path) : file_put_contents($path, $backup);
    }
}

// ---------------------------------------------------------------------------------------------
// Наполнение
// ---------------------------------------------------------------------------------------------

it('Наполнение: the demo file with photos gives a 3-level catalog with photos and characteristics; a re-import adds no duplicates', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
    app()->instance(HostResolver::class, new FakeHostResolver);
    Http::fake();

    $run = function (): Import {
        $import = ImportHarness::analyzed(base_path('examples/import-demo.xlsx'));
        ImportHarness::attachZip($import, base_path('examples/import-demo-photos.zip'));

        return ImportHarness::run($import->refresh());
    };

    $first = $run();

    expect($first->status)->toBe(ImportStatus::Completed)
        ->and($first->error_count)->toBe(0)
        ->and(Category::query()->max('depth'))->toBe(3)
        ->and(Product::query()->count())->toBe(50)
        ->and(ProductImage::query()->count())->toBeGreaterThanOrEqual(60)
        ->and(Product::query()->whereHas('images')->count())->toBeGreaterThan(40)
        ->and(Product::query()->get()->filter(fn (Product $p) => $p->specs() !== [])->count())->toBeGreaterThan(40);

    $products = Product::query()->count();
    $categories = Category::query()->count();
    $images = ProductImage::query()->count();

    $second = $run();

    expect($second->status)->toBe(ImportStatus::Completed)
        ->and($second->created_count)->toBe(0)
        ->and(Product::query()->count())->toBe($products)
        ->and(Category::query()->count())->toBe($categories)
        ->and(ProductImage::query()->count())->toBe($images);

    // The imported products are on the storefront.
    $product = Product::query()->active()->whereHas('images')->firstOrFail();
    $this->get('/product/'.$product->slug)->assertOk()->assertSee($product->name);
});

// ---------------------------------------------------------------------------------------------
// Цены
// ---------------------------------------------------------------------------------------------

it('Цены: markup rule and promo give the right prices, old price and percent; the price returns after the promo', function (): void {
    keepBoundaryStampDuring(function (): void {
        $this->travelTo(now()->startOfMinute());

        $electronics = Category::factory()->create(['name' => 'Электроника']);
        $phones = Category::factory()->childOf($electronics)->create(['name' => 'Смартфоны']);
        $smartphone = Product::factory()->inCategory($phones)->create(['name' => 'Смартфон Север', 'base_price' => '10000.00', 'cost_price' => null]);
        $camera = Product::factory()->inCategory($electronics)->create(['name' => 'Камера Юг', 'base_price' => '5000.00', 'cost_price' => null]);
        $chair = Product::factory()->create(['name' => 'Стул Запад', 'base_price' => '3000.00']);

        $recalculator = app(PriceRuleRecalculator::class);
        $markup = PriceRule::factory()->markupPercent(20)->forCategories([$electronics])->create(['name' => 'Электроника +20 %']);
        $recalculator->ruleChanged(null, PriceRuleData::fromModel($markup->load('targets')));

        $promo = PriceRule::factory()->discountPercent(10)->forCategories([$phones])->showAsDiscount()
            ->create(['name' => 'Смартфоны −10 %', 'ends_at' => now()->addDay()]);
        $recalculator->ruleChanged(null, PriceRuleData::fromModel($promo->load('targets')));

        // 10 000 × 1.2 × 0.9 = 10 800; the old price is the price before the discount phase (12 000); −10 %.
        expect($smartphone->fresh()->final_price)->toBe('10800.00')
            ->and($camera->fresh()->final_price)->toBe('6000.00')
            ->and($chair->fresh()->final_price)->toBe('3000.00');

        $this->get('/product/'.$smartphone->slug)->assertOk()
            ->assertSeeText(Money::format(1_080_000))
            ->assertSeeText(Money::format(1_200_000))
            ->assertSeeText('−10%');
        $this->get('/catalog/'.$phones->slug)->assertOk()
            ->assertSeeText(Money::format(1_080_000))
            ->assertSeeText(Money::format(1_200_000));
        $this->get('/product/'.$camera->slug)->assertOk()->assertSeeText(Money::format(600_000))->assertDontSeeText('−10%');

        // The explanation in the admin product card.
        actingAs(User::factory()->admin()->create());
        Livewire::test(EditProduct::class, ['record' => $smartphone->getRouteKey()])
            ->assertSee('Итог: '.Money::format(1_080_000))
            ->assertSee('Электроника +20')
            ->assertSee('Смартфоны −10');

        // After the end of the promo the cron run restores the price (at most 15 minutes later).
        artisanQuietly('shop:recalc-prices', ['--boundaries' => true]);
        $this->travelTo(now()->addDay()->addMinutes(10));
        artisanQuietly('shop:recalc-prices', ['--boundaries' => true]);

        expect($smartphone->fresh()->final_price)->toBe('12000.00')
            ->and($smartphone->fresh()->display_old_price)->toBeNull();

        $this->get('/product/'.$smartphone->slug)->assertOk()->assertSeeText(Money::format(1_200_000))->assertDontSeeText('−10%');
    });
});

function artisanQuietly(string $command, array $parameters = []): void
{
    test()->artisan($command, $parameters)->assertSuccessful();
}

// ---------------------------------------------------------------------------------------------
// Режимы
// ---------------------------------------------------------------------------------------------

it('Режимы: cart by default, reservation in «Мебель», inquiry for a price-on-request product', function (): void {
    sales(['default_mode' => SalesMode::Cart, 'stock_tracking' => true, 'allow_backorder' => false]);

    $furniture = Category::factory()->salesMode(SalesMode::Reservation)->create(['name' => 'Мебель']);
    $other = Category::factory()->create(['name' => 'Посуда']);
    $chair = Product::factory()->inCategory($furniture)->create(['name' => 'Кресло', 'base_price' => '5000.00', 'stock_qty' => 5]);
    $cup = Product::factory()->inCategory($other)->create(['name' => 'Чашка', 'base_price' => '300.00', 'stock_qty' => 5]);
    $quote = Product::factory()->inCategory(Category::factory()->create(['name' => 'Эксклюзив']))->priceOnRequest()->create(['name' => 'Сервиз на заказ', 'stock_qty' => 5]);

    $this->get('/product/'.$chair->slug)->assertOk()
        ->assertSee('data-action="reservation"', false)
        ->assertDontSee('data-action="cart"', false)
        ->assertDontSee('data-action="inquiry"', false);

    $this->get('/product/'.$cup->slug)->assertOk()
        ->assertSee('data-action="cart"', false)
        ->assertDontSee('data-action="reservation"', false);

    $this->get('/product/'.$quote->slug)->assertOk()
        ->assertSee('data-action="inquiry"', false)
        ->assertDontSee('data-action="cart"', false)
        ->assertDontSee('data-action="reservation"', false);

    // Listing cards follow the same rules.
    $this->get('/catalog/'.$furniture->slug)->assertOk()
        ->assertSeeText('Зарезервировать')
        ->assertDontSeeText('В корзину');

    $this->get('/catalog/'.$other->slug)->assertOk()->assertSeeText('В корзину')->assertDontSeeText('Зарезервировать');
    $this->get('/catalog/'.$quote->category->slug)->assertOk()->assertSeeText('Оставить заявку')->assertDontSeeText('В корзину');
});

// ---------------------------------------------------------------------------------------------
// Заказ
// ---------------------------------------------------------------------------------------------

function acceptanceNotifications(): void
{
    $settings = app(NotificationSettings::class);
    $settings->notify_emails = ['shop@example.com'];
    $settings->telegram_enabled = true;
    $settings->telegram_bot_token = '123456:TEST-token_abc';
    $settings->telegram_chat_id = '-1001';

    foreach (['notify_shop_email', 'notify_shop_telegram', 'notify_customer_new', 'notify_customer_status', 'notify_event_order', 'notify_event_reservation', 'notify_event_inquiry'] as $toggle) {
        $settings->{$toggle} = true;
    }

    $settings->save();
}

it('Заказ: two products, checkout with delivery, order in the admin, stock decreased, mail and Telegram sent', function (): void {
    acceptanceNotifications();
    Mail::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    sales(['stock_tracking' => true, 'min_order_amount' => 0]);

    $a = sellable(['name' => 'Чайник', 'base_price' => '1000.00', 'stock_qty' => 10, 'sales_mode' => SalesMode::Cart]);
    $b = sellable(['name' => 'Кружка', 'base_price' => '250.00', 'stock_qty' => 20, 'sales_mode' => SalesMode::Cart]);
    $cart = cartWith($a, $b);
    $cart->items()->where('product_id', $b->id)->update(['qty' => 3]);

    $component = livewireWithCart(CheckoutForm::class, $cart);
    test()->travel(5)->seconds();
    fillCheckout($component, 'kurerskaia-dostavka')->call('submit')->assertHasNoErrors();

    $order = Order::query()->firstOrFail();

    expect($order->type)->toBe(OrderType::Order)
        ->and($order->status)->toBe(OrderStatus::New)
        ->and($order->items)->toHaveCount(2)
        ->and((float) $order->delivery_price)->toBeGreaterThan(0)
        ->and($a->fresh()->stock_qty)->toBe(9)
        ->and($b->fresh()->stock_qty)->toBe(17);

    // The request is in the admin.
    actingAs(User::factory()->manager()->create());
    $this->get(OrderResource::getUrl('index'))->assertOk()->assertSee($order->number);
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertOk()->assertSee('Чайник')->assertSee('Кружка')->assertSee('Иван Тестов');

    Mail::assertSent(OrderPlacedCustomerMail::class, fn ($mail): bool => $mail->hasTo('ivan@example.com'));
    Mail::assertSent(NewRequestShopMail::class, fn ($mail): bool => $mail->hasTo('shop@example.com'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/bot123456:TEST-token_abc/sendMessage')
        && str_contains($request['text'], $order->number)
        && str_contains($request['text'], 'Чайник'));
});

// ---------------------------------------------------------------------------------------------
// Резерв
// ---------------------------------------------------------------------------------------------

it('Резерв: a reservation lowers the available stock; after the term it expires and the stock returns', function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'reservation_hours' => 48]);
    $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));

    $product = sellable(['name' => 'Стол', 'stock_qty' => 5, 'sales_mode' => SalesMode::Both, 'base_price' => '500.00']);

    $component = livewireWithCart(ReservationForm::class, null, ['productId' => $product->id]);
    test()->travel(5)->seconds();

    foreach (['name' => 'Иван Тестов', 'phone' => '8 900 123-45-67', 'email' => '', 'comment' => '', 'consent' => true, 'qty' => 2] as $key => $value) {
        $component->set($key, $value);
    }

    $component->call('submit')->assertHasNoErrors();
    $order = Order::query()->firstOrFail();

    expect($order->type)->toBe(OrderType::Reservation)
        ->and($product->fresh()->reserved_qty)->toBe(2)
        ->and($product->fresh()->availableQty())->toBe(3)
        ->and($product->fresh()->stock_qty)->toBe(5);

    $this->get('/product/'.$product->slug)->assertOk()->assertSeeText('3');

    // Nobody touches the reservation; the cron run after the term expires it.
    $this->travelTo($order->reserved_until->copy()->addMinute());
    artisanQuietly('shop:expire-reservations');

    expect($order->fresh()->status)->toBe(OrderStatus::Expired)
        ->and($product->fresh()->reserved_qty)->toBe(0)
        ->and($product->fresh()->availableQty())->toBe(5);
});

// ---------------------------------------------------------------------------------------------
// Права
// ---------------------------------------------------------------------------------------------

it('Права: a manager handles requests and products but not settings, users or deletion', function (): void {
    $manager = User::factory()->manager()->create();
    actingAs($manager);

    $order = Order::factory()->create();
    $product = Product::factory()->create(['name' => 'Было']);

    // Requests: open, change the status.
    Livewire::test(ListOrders::class)->assertOk()->assertCanSeeTableRecords([$order]);
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('change_status', ['status' => OrderStatus::Confirmed->value])
        ->assertHasNoActionErrors();
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);

    // Products: edit.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => 'Стало'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($product->fresh()->name)->toBe('Стало');

    // No settings, no users.
    foreach ([ManageGeneralSettings::class, ManageAppearanceSettings::class, ManagePricingSettings::class, ManageNotificationSettings::class] as $page) {
        $this->get($page::getUrl())->assertForbidden();
    }
    $this->get(UserResource::getUrl('index'))->assertForbidden();

    // No deletion of requests, no mass price change.
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('delete');
    expect(Gate::forUser($manager)->allows('delete', $order))->toBeFalse();
    Livewire::test(ListProducts::class)->assertActionHidden(TestAction::make('change_price')->table()->bulk());
});

// ---------------------------------------------------------------------------------------------
// Внешний вид
// ---------------------------------------------------------------------------------------------

it('Внешний вид: name, logo, colors and a four-block home page show up on the storefront at once', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    actingAs(User::factory()->admin()->create());

    $category = Category::factory()->create(['name' => 'Витринная категория']);
    $hit = Product::factory()->inCategory($category)->featured()->create(['name' => 'Хит сезона']);

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['shop_name' => 'Лавка Приёмка', 'logo' => UploadedFile::fake()->image('logo.png', 240, 80)])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['primary_color' => '#112233', 'accent_color' => '#ddeeff'])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(ManageHomePage::class)
        ->fillForm(['blocks' => [
            homeBlock('text', ['title' => 'Добро пожаловать', 'content' => '<p>Текст главной</p>']),
            homeBlock('advantages', ['items' => [['icon' => 'star', 'title' => 'Быстрая доставка']]]),
            homeBlock('categories', ['title' => 'Разделы', 'mode' => 'auto', 'max_count' => 4]),
            homeBlock('products', ['title' => 'Хиты', 'source' => 'hits', 'limit' => 4, 'sort' => 'default']),
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $html = $this->get('/')->assertOk()->getContent();

    expect(app(GeneralSettings::class)->refresh()->logo)->not->toBeNull();

    foreach (['Лавка Приёмка', '--shop-primary: #112233;', '--shop-accent: #ddeeff;', 'Добро пожаловать', 'Текст главной', 'Быстрая доставка', 'Разделы', 'Витринная категория', 'Хиты', 'Хит сезона'] as $expected) {
        expect($html)->toContain($expected);
    }

    expect($html)->toContain(basename((string) app(GeneralSettings::class)->logo));
    unset($hit);
});

// ---------------------------------------------------------------------------------------------
// Цикл Excel
// ---------------------------------------------------------------------------------------------

it('Цикл Excel: export, edit prices in the file, import «Только цены и остатки»: prices updated, history written', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);

    $category = Category::factory()->create(['name' => 'Электроника']);
    $a = Product::factory()->inCategory($category)->create(['sku' => 'XL-1', 'name' => 'Первый', 'base_price' => '1000.00', 'stock_qty' => 5]);
    $b = Product::factory()->inCategory($category)->create(['sku' => 'XL-2', 'name' => 'Второй', 'base_price' => '2500.50', 'stock_qty' => 7]);
    $untouched = Product::factory()->inCategory($category)->create(['sku' => 'XL-3', 'name' => 'Третий', 'base_price' => '90.00', 'stock_qty' => 1]);

    $exported = ImportFiles::tempPath('xlsx');
    app(CatalogExporter::class)->export([], $exported);

    // "Edit in Excel": +10 % on the first two products, stock of the second one set to 9.
    $edited = ImportFiles::tempPath('xlsx');
    $writer = SimpleExcelWriter::create($edited);
    $reader = SimpleExcelReader::create($exported);

    foreach ($reader->getRows() as $row) {
        $sku = (string) $row['Артикул'];

        if (in_array($sku, ['XL-1', 'XL-2'], true)) {
            $row['Цена'] = bcmul((string) $row['Цена'], '1.10', 2);
        }

        if ($sku === 'XL-2') {
            $row['Остаток'] = 9;
        }

        $writer->addRow($row);
    }

    $writer->close();

    $user = User::factory()->admin()->create();
    $import = ImportHarness::run(ImportHarness::analyzed($edited, ['mode' => ImportMode::PricesStock, 'user_id' => $user->id]));

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->error_count)->toBe(0)
        ->and($a->fresh()->base_price)->toBe('1100.00')
        ->and($a->fresh()->final_price)->toBe('1100.00')
        ->and($b->fresh()->base_price)->toBe('2750.55')
        ->and($b->fresh()->stock_qty)->toBe(9)
        ->and($untouched->fresh()->base_price)->toBe('90.00');

    $history = PriceHistory::query()->where('field', 'base_price')->orderBy('product_id')->get();

    expect($history)->toHaveCount(2)
        ->and($history->pluck('source')->unique()->all())->toEqual([PriceChangeSource::Import])
        ->and($history->pluck('import_id')->unique()->all())->toBe([$import->id])
        ->and($history->first()->old_value)->toBe('1000.00')
        ->and($history->first()->new_value)->toBe('1100.00');
});
