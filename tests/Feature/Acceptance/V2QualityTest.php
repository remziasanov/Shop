<?php

declare(strict_types=1);

use App\Enums\CategoryAccessMode;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PriceDisplayMode;
use App\Enums\PriceSource;
use App\Enums\SalesMode;
use App\Enums\StorefrontSection;
use App\Exceptions\SalesException;
use App\Jobs\SendMailNotification;
use App\Jobs\SendWholesaleApplicationNotification;
use App\Livewire\Storefront\CheckoutForm;
use App\Livewire\Storefront\ReservationForm;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WholesaleApplication;
use App\Services\Cart\CartService;
use App\Services\Customers\WholesaleApplicationService;
use App\Services\Promo\PromoAttemptGuard;
use App\Services\Sales\CheckoutMethods;
use App\Services\Seo\SitemapGenerator;
use App\Support\PiiMask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Quality requirements of version 2.0 (TZ 26, NF-50…NF-57) that can be checked by a test. The hosting, Lighthouse and
 * the weight of the bundle on the production server are in the manual checklist of docs/PROGRESS.md, stage 15.
 */
beforeEach(function (): void {
    $this->withoutVite();
    resetFormGuard();
});

/**
 * Runs a GET request and returns [number of SQL queries, number of facet queries].
 *
 * @return array{0: int, 1: int}
 */
function v2Profile(string $url): array
{
    // A raw spelling of a filter is redirected to the normalized URL (FL-07): the page is the one it leads to.
    $first = test()->get($url);

    if ($first->isRedirection()) {
        $url = (string) $first->headers->get('Location');
    }

    $queries = 0;
    $facets = 0;

    DB::listen(function ($query) use (&$queries, &$facets): void {
        $queries++;
        $sql = strtolower($query->sql);

        if ((str_contains($sql, 'product_attribute_values') || str_contains($sql, 'product_variant_values')) && str_contains($sql, 'group by')) {
            $facets++;
        }
    });

    test()->get($url)->assertOk();

    return [$queries, $facets];
}

// ---------------------------------------------------------------------------------------------
// NF-50. Бюджет запросов
// ---------------------------------------------------------------------------------------------

it('keeps every storefront page within 30 SQL queries and 10 facet queries, for a guest and for a wholesaler (NF-50)', function (): void {
    $this->artisan('shop:demo', ['--no-images' => true])->assertSuccessful();

    $card = Product::query()->where('sku', 'DEMO-TEE')->firstOrFail();
    $plain = Product::query()->active()->where('has_variants', false)->where('sku', 'like', 'DEMO-0%')->firstOrFail();

    $urls = [
        'home' => '/',
        'catalog' => '/catalog',
        'category with facets' => '/catalog/demo-elektronika',
        'category with filters applied' => '/catalog/demo-elektronika?'.http_build_query(['a' => ['material' => ['plastik'], 'power' => ['min' => '10', 'max' => '400']], 'sort' => 'price_asc']),
        'category with option filters' => '/catalog/demo-odezhda',
        'category page 2' => '/catalog/demo-elektronika?page=2',
        'product with variants' => '/product/'.$card->slug,
        'plain product' => '/product/'.$plain->slug,
        'search' => '/search?q='.rawurlencode('смартфон'),
        'cart' => '/cart',
    ];

    $opt = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
    $wholesaler = makeCustomer(['customer_group_id' => $opt->id]);

    foreach (['guest' => null, 'wholesaler' => $wholesaler] as $viewer => $customer) {
        foreach ($urls as $name => $url) {
            foreach (['cold', 'warm'] as $pass) {
                if ($pass === 'cold') {
                    Cache::flush();
                }

                $this->flushSession();
                nextRequest();

                if ($customer !== null) {
                    signInCustomer($this, $customer);
                }

                [$queries, $facets] = v2Profile($url);

                expect($queries)->toBeLessThanOrEqual(30, "{$viewer}: {$name} ({$pass}) ran {$queries} queries")
                    ->and($facets)->toBeLessThanOrEqual(10, "{$viewer}: {$name} ({$pass}) ran {$facets} facet queries");
            }
        }
    }
});

// ---------------------------------------------------------------------------------------------
// NF-51. Переносимые миграции
// ---------------------------------------------------------------------------------------------

it('keeps the migrations portable between SQLite and MySQL 5.7/8.0: no CTE, no window functions, reversible (NF-51)', function (): void {
    $forbidden = [
        '/\bWITH\s+RECURSIVE\b/i' => 'recursive CTE',
        '/\bWITH\s+[`"\w]+\s+AS\s*\(/i' => 'CTE',
        '/\bOVER\s*\(/i' => 'window function',
        '/\b(ROW_NUMBER|RANK|DENSE_RANK|NTILE|LEAD|LAG|FIRST_VALUE|LAST_VALUE)\s*\(/i' => 'window function',
        '/\bJSON_TABLE\s*\(/i' => 'JSON_TABLE (MySQL 8 only)',
        '/\bGENERATED\s+ALWAYS\b/i' => 'generated column in raw SQL',
        '/->fullText\(/' => 'fulltext index (not portable)',
    ];

    $offenders = [];
    $files = [...glob(database_path('migrations/*.php')) ?: [], ...glob(database_path('settings/*.php')) ?: []];
    // The settings migrations of the MVP (stages 1-7) are up-only (spatie convention); every other migration has a down().
    $upOnly = fn (string $file): bool => str_contains($file, '/settings/') && basename($file) < '2026_11_07';

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $code = (string) file_get_contents($file);

        foreach ($forbidden as $pattern => $what) {
            if (preg_match($pattern, $code) === 1) {
                $offenders[] = basename($file).': '.$what;
            }
        }

        // The migration has to be reversible (a `down()` that does something is checked by the migrate:rollback run in CI).
        if (! str_contains($code, 'function down') && ! $upOnly($file)) {
            $offenders[] = basename($file).': no down()';
        }
    }

    expect($offenders)->toBe([]);
});

it('does not use window functions or CTE anywhere in the application queries (NF-51, MySQL 5.7)', function (): void {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());

        if (preg_match('/\bWITH\s+RECURSIVE\b|\bOVER\s*\(\s*(PARTITION|ORDER)\b/i', $code) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// NF-52, NF-15. Персональные данные в логах
// ---------------------------------------------------------------------------------------------

it('masks e-mail addresses and phones in the logs of failed notifications (NF-52, NF-15)', function (): void {
    Log::spy();
    $error = new RuntimeException('Expected response code 250 but got 550 for <secret.person@example.ru>');

    (new SendMailNotification(1, SendMailNotification::CUSTOMER_PLACED, 'secret.person@example.ru'))->failed($error);
    (new SendWholesaleApplicationNotification(1, SendWholesaleApplicationNotification::MAIL, 'secret.person@example.ru'))->failed($error);

    Log::shouldHaveReceived('warning')->twice();
    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        $dump = json_encode($context, JSON_UNESCAPED_UNICODE);

        return ! str_contains((string) $dump, 'secret.person@example.ru') && str_contains((string) $dump, 's***@example.ru');
    })->twice();

    expect(PiiMask::phone('+7 (900) 123-45-67'))->not->toContain('1234')->and(PiiMask::phone('+79001234567'))->toEndWith('4567');
});

it('never writes e-mail, phone or name fields into a log call without masking (NF-52)', function (): void {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());

        if (preg_match_all('/Log::(?:info|warning|error|debug|notice|critical)\((.*?)\);/s', $code, $calls) < 1) {
            continue;
        }

        foreach ($calls[1] as $call) {
            if (preg_match_all('/[\'"](email|phone|customer_email|customer_phone|customer_name|address|name)[\'"]\s*=>\s*([^,\n\]]+)/', $call, $fields, PREG_SET_ORDER) > 0) {
                foreach ($fields as $field) {
                    if (! str_contains($field[2], 'PiiMask')) {
                        $offenders[] = basename($file->getPathname()).': '.trim($field[0]);
                    }
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps personal data out of the sitemap and out of the cached catalog fragments (NF-52)', function (): void {
    $customer = makeCustomer(['name' => 'Тайный Покупатель', 'email' => 'tajnyj@example.com', 'phone' => '+79005550101']);
    Order::factory()->create(['customer_id' => $customer->id, 'customer_name' => 'Тайный Покупатель', 'customer_email' => 'tajnyj@example.com']);
    sellable(['name' => 'Лампа']);

    $dir = storage_path('framework/testing/sitemap-'.uniqid());
    File::ensureDirectoryExists($dir);
    app(SitemapGenerator::class)->generate($dir);
    $xml = (string) file_get_contents($dir.'/sitemap.xml');
    File::deleteDirectory($dir);

    signInCustomer($this, $customer);
    $this->get('/catalog')->assertOk();

    expect($xml)->not->toContain('tajnyj')->not->toContain('Тайный');

    // A guest request after a signed-in one gets no trace of the customer in the page.
    $this->flushSession();
    nextRequest();
    $this->get('/catalog')->assertOk()->assertDontSee('Тайный')->assertDontSee('tajnyj@example.com');
});

// ---------------------------------------------------------------------------------------------
// NF-53. Ограничения частоты
// ---------------------------------------------------------------------------------------------

describe('rate limits (NF-53)', function (): void {
    beforeEach(function (): void {
        Mail::fake();
        Cache::flush();
    });

    it('limits sign-in attempts and locks the pair e-mail + IP', function (): void {
        makeCustomer(['email' => 'limit.login@example.com']);

        foreach (range(1, 4) as $_) {
            $this->post(route('customer.login.store'), ['email' => 'limit.login@example.com', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        }

        $this->post(route('customer.login.store'), ['email' => 'limit.login@example.com', 'password' => 'wrong-password'])->assertStatus(429);
    });

    it('limits registrations per IP', function (): void {
        foreach (range(1, 5) as $i) {
            $this->post(route('customer.register.store'), registrationBody(['email' => "reg{$i}@example.com"]))->assertRedirect();
        }

        $this->post(route('customer.register.store'), registrationBody(['email' => 'reg6@example.com']))->assertStatus(429);
    });

    it('limits e-mail codes per address and per hour', function (): void {
        makeCustomer(['email' => 'limit.code@example.com']);

        foreach (range(1, 5) as $_) {
            $this->post(route('customer.login.send-code'), ['email' => 'limit.code@example.com'])->assertRedirect();
            $this->travel(61)->seconds();
        }

        $this->post(route('customer.login.send-code'), ['email' => 'limit.code@example.com'])->assertStatus(429);
    });

    it('limits promo code attempts per minute', function (): void {
        $guard = app(PromoAttemptGuard::class);

        foreach (range(1, PromoAttemptGuard::PER_MINUTE) as $_) {
            expect($guard->retryAfter('203.0.113.9', 'session-x'))->toBe(0);
            $guard->hit('203.0.113.9', 'session-x');
        }

        expect($guard->retryAfter('203.0.113.9', 'session-x'))->toBeGreaterThan(0);
    });

    it('limits wholesale applications of one customer', function (): void {
        $customer = makeCustomer();
        WholesaleApplication::factory()->count(3)->rejected()->forCustomer($customer)->create();
        signInCustomer($this, $customer);

        $this->post(route('customer.wholesale.store'), [
            'company_name' => 'ООО «Ромашка»', 'inn' => '7707083893', 'contact_name' => 'Пётр', 'phone' => '8 (900) 123-45-67',
            'consent' => '1', 'website' => '', 'form_token' => formToken(),
        ])->assertSessionHasErrors('form');

        expect(WholesaleApplication::query()->count())->toBe(3)
            ->and(app(WholesaleApplicationService::class)->applyBlockReason($customer))->not->toBeNull();
    });

    it('throttles the public endpoints that are cheap to abuse', function (): void {
        $routes = app('router')->getRoutes();

        foreach (['search.suggest' => 'throttle:60,1', 'cart.add' => 'throttle:60,1', 'promo-link' => 'throttle:120,1', 'age-gate.confirm' => 'throttle:60,1', 'customer.price-list' => 'throttle:10,60', 'price-list.guest' => 'throttle:10,60'] as $name => $middleware) {
            expect($routes->getByName($name)?->gatherMiddleware())->toContain($middleware);
        }
    });
});

// ---------------------------------------------------------------------------------------------
// NF-57. Тексты новых экранов через lang/ru
// ---------------------------------------------------------------------------------------------

it('has no hard-coded Russian text in the storefront and cabinet views outside __() (NF-57)', function (): void {
    $offenders = [];

    foreach ([resource_path('views/storefront'), resource_path('views/components'), resource_path('views/livewire/storefront')] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // Blade comments are documentation for developers, not screen text.
            $source = preg_replace('/\{\{--.*?--\}\}/su', '', (string) file_get_contents($file->getPathname())) ?? '';

            if (preg_match('/[\x{0400}-\x{04FF}]/u', $source, $match) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('has no hard-coded Russian string literals in the storefront Livewire components and controllers (NF-57)', function (): void {
    $offenders = [];

    foreach ([app_path('Livewire/Storefront'), app_path('Http/Controllers/Storefront')] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                // Comments are skipped by looking at string tokens only.
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match('/[\x{0400}-\x{04FF}]/u', $token[1]) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).': '.trim($token[1]);
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// NF-56. Обратная совместимость
// ---------------------------------------------------------------------------------------------

it('behaves like version 1.0 with the default settings: the same prices, stock and modes (NF-56)', function (): void {
    // The seeded groups and settings do not change anything for a visitor.
    $guest = guestGroup();
    expect($guest->price_display)->toBe(PriceDisplayMode::Show)
        ->and($guest->category_access_mode)->toBe(CategoryAccessMode::All)
        ->and($guest->min_order_amount)->toBeNull()
        ->and($guest->allowed_delivery_codes)->toBeNull()
        ->and($guest->allowed_payment_codes)->toBeNull()
        ->and($guest->allowed_sales_modes)->toBeNull()
        ->and($guest->default_discount_percent)->toBe('0.00')
        ->and($guest->visible_sections)->toContain(StorefrontSection::Catalog->value)
        ->and(CustomerGroup::retail()->default_discount_percent)->toBe('0.00');

    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    $cartProduct = sellable(['name' => 'Чайник', 'base_price' => '1500.00', 'stock_qty' => 10, 'sales_mode' => SalesMode::Cart]);
    $reservable = sellable(['name' => 'Диван', 'base_price' => '30000.00', 'stock_qty' => 3, 'sales_mode' => SalesMode::Reservation]);
    $showcase = sellable(['name' => 'Витрина', 'sales_mode' => SalesMode::Showcase]);

    // The product pages: the retail price, the cart button only where the mode allows it.
    $this->get('/product/'.$cartProduct->slug)->assertOk()->assertSeeText("1\u{202F}500\u{00A0}₽");
    $this->get('/product/'.$showcase->slug)->assertOk()->assertDontSee('name="product_id"', false);

    // Cart mode: an order at the retail price, stock deducted, no group, no promo, no tiers.
    $this->postJson('/cart/add', ['product_id' => $cartProduct->id, 'qty' => 2])->assertOk();
    $cartRow = CartItem::query()->firstOrFail();
    expect($cartRow->price_at_add)->toBe('1500.00')->and($cartRow->qty)->toBe(2);

    $component = livewireWithCart(CheckoutForm::class, $cartRow->cart);
    $this->travel(5)->seconds();
    fillCheckout($component)->call('submit')->assertHasNoErrors();

    $order = Order::query()->with('items')->firstOrFail();
    $item = $order->items->sole();
    expect($order->type)->toBe(OrderType::Order)
        ->and($order->status)->toBe(OrderStatus::New)
        ->and($order->items_total)->toBe('3000.00')
        ->and($order->total)->toBe('3000.00')
        ->and($order->customer_id)->toBeNull()
        ->and($order->customer_group_id)->toBeNull()
        ->and($order->promo_code)->toBeNull()
        ->and($order->promo_discount)->toBe('0.00')
        ->and($order->manual_discount)->toBe('0.00')
        ->and($item->price)->toBe('1500.00')
        ->and($item->price_source)->toBe(PriceSource::Retail)
        ->and($item->variant_id)->toBeNull()
        ->and($cartProduct->fresh()->stock_qty)->toBe(8);

    // The default delivery and payment methods of version 1.0 are all offered.
    expect(array_map(fn ($o) => $o->key, app(CheckoutMethods::class)->deliveries()))->toBe(['samovyvoz', 'kurerskaia-dostavka'])
        ->and(array_map(fn ($o) => $o->key, app(CheckoutMethods::class)->payments()))->toBe(['nalicnymi-pri-polucenii', 'kartoi-pri-polucenii']);

    // Reservation mode: the one-product form holds the stock, the stock itself stays.
    $form = livewireWithCart(ReservationForm::class, null, ['productId' => $reservable->id]);
    $this->travel(5)->seconds();

    foreach (['name' => 'Иван Тестов', 'phone' => '8 900 123-45-67', 'email' => '', 'consent' => true, 'qty' => 1] as $key => $value) {
        $form->set($key, $value);
    }

    $form->call('submit')->assertHasNoErrors();
    $reservation = Order::query()->where('type', OrderType::Reservation)->firstOrFail();
    expect($reservable->fresh()->reserved_qty)->toBe(1)->and($reservable->fresh()->stock_qty)->toBe(3)
        ->and($reservation->items->sole()->price)->toBe('30000.00');

    // Showcase: nothing can be bought.
    expect(fn () => app(CartService::class)->add(Cart::factory()->create(), $showcase))->toThrow(SalesException::class);

    // A card without variants is a plain product: no variant columns, no group rows.
    expect(ProductVariant::query()->count())->toBe(0)->and(DB::table('product_price_tiers')->count())->toBe(0);
});

it('keeps the staff roles of version 1.0: the default Manager cannot do what the Administrator alone could (NF-56)', function (): void {
    $manager = User::factory()->manager()->create();
    $admin = User::factory()->admin()->create();

    expect($manager->can('delete', Order::factory()->create()))->toBeFalse()
        ->and($admin->can('delete', Order::factory()->create()))->toBeTrue();
});
