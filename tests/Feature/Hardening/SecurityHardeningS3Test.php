<?php

declare(strict_types=1);

use App\Filament\Avatars\InitialsAvatarProvider;
use App\Filament\Pages\Settings\ManageNotificationSettings;
use App\Models\Order;
use App\Models\User;
use App\Services\System\EnvironmentChecker;
use App\Support\SanitizedQueryException;
use App\Support\SanitizingFailedJobProvider;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

function adminPath(string $suffix = ''): string
{
    return '/'.trim((string) config('shop.admin_path'), '/').$suffix;
}

// 1. Client address row.
it('has the client address row with the proxy hint on the system report (S3-1)', function (): void {
    // A console request has no REMOTE_ADDR.
    app()->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '']));

    $row = collect(app(EnvironmentChecker::class)->run())->firstWhere('key', 'client_ip');

    expect($row)->not->toBeNull()
        ->and($row->hint)->toContain('прокси')
        ->and($row->value)->toContain('Система'); // the console has no visitor: the value points to the page
});

it('shows the address of the request on the «Система» page (S3-1)', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get(adminPath('/system'))->assertOk()->assertSee('203.0.113.7');
});

it('prints the client address row in shop:doctor (S3-1)', function (): void {
    $this->artisan('shop:doctor --json')->expectsOutputToContain('client_ip');
});

// 2. Security headers.
it('sends the CSP on the storefront, merged with frame-ancestors in Telegram mode (S3-2, TM-12)', function (): void {
    $this->get('/up')->assertHeader('Content-Security-Policy', "object-src 'none'; base-uri 'self'");

    $tg = $this->get('/tg');
    expect($tg->headers->all('content-security-policy'))->toHaveCount(1)
        ->and($tg->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'self' https://web.telegram.org; object-src 'none'; base-uri 'self'");
});

it('sends the CSP on the admin panel and never allows script-src (S3-2)', function (): void {
    $csp = $this->get(adminPath('/login'))->headers->get('Content-Security-Policy');

    expect($csp)->toBe("object-src 'none'; base-uri 'self'")->not->toContain('script-src');
});

it('does not send the CSP on a PDF response but keeps the other headers (S3-2)', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $order = Order::factory()->create();

    $response = $this->get(route('admin.orders.pdf', $order))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Referrer-Policy'))->not->toBeNull();
});

it('sends HSTS only for a secure request in production (S3-2)', function (): void {
    $this->get('/up')->assertHeaderMissing('Strict-Transport-Security');

    $this->app['env'] = 'production';

    try {
        $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=15552000');
        $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
    } finally {
        // In production TrustHosts stores the host patterns in a static of the Symfony request: reset them, or later
        // tests with another host get 400 (as CanonicalHostTest does).
        $this->app['env'] = 'testing';
        Request::setTrustedHosts([]);
    }

    $this->get('https://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
});

// 3. htaccess.
it('refuses PHP-like files under public/storage in .htaccess without php_flag (S3-3)', function (): void {
    $htaccess = (string) file_get_contents(base_path('public/.htaccess'));

    expect($htaccess)->toContain('RewriteRule ^storage/.*\.(php\d*|pht|phps|phar|phtml)(/|$) - [F,L,NC]')
        ->and($htaccess)->not->toContain('php_flag')->not->toContain('php_admin_flag');

    // Apache is not available in tests: evaluate the pattern itself (the NC flag = case-insensitive).
    preg_match('/RewriteRule (\^storage\/\S+) - \[F,L,NC\]/', $htaccess, $m);
    $pattern = '#'.$m[1].'#i';

    foreach (['x.php', 'x.PHP', 'a/b.Php5', 'x.php8', 'x.pht', 'x.phps', 'x.phar', 'x.PHTML', 'x.php/a.jpg', 'x.phtml/y'] as $path) {
        expect(preg_match($pattern, 'storage/'.$path))->toBe(1, $path);
    }

    foreach (['x.jpg', 'photo.phpx.jpg', 'x.phpfoo', 'phpinfo.png', 'x.pdf'] as $path) {
        expect(preg_match($pattern, 'storage/'.$path))->toBe(0, $path);
    }
});

// 4. Avatars.
it('renders the admin without ui-avatars.com and draws an escaped initials avatar (S3-4)', function (): void {
    $user = User::factory()->admin()->create(['name' => '<b>"Иван</b> Петров']);
    $this->actingAs($user);

    $html = $this->get(adminPath())->assertOk()->getContent();

    expect($html)->not->toContain('ui-avatars.com')->toContain('data:image/svg+xml;base64,');

    $uri = app(InitialsAvatarProvider::class)->get($user);
    $svg = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')));

    expect($svg)->not->toContain('<b>')->not->toContain('"Иван');
});

// 5. Deploy workflow.
it('fails the deploy job when BEGET_KNOWN_HOSTS is empty and never runs ssh-keyscan (S3-5)', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));

    expect($workflow)->not->toContain('ssh-keyscan -H')
        ->and($workflow)->toContain('if [ -z "$BEGET_KNOWN_HOSTS" ]');
});

// 6. Role editor hint.
it('warns next to settings.seo.update that the right equals administrator rights (S3-6)', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(adminPath('/roles/create'))->assertOk()->assertSee(__('roles.form.script_right_seo'), false);
});

// 8. Query exceptions.
it('reports a QueryException without the bound values (S3-8, NF-15)', function (): void {
    config(['app.debug' => false]);
    $logged = null;
    Log::shouldReceive('error')->andReturnUsing(function (string $message, array $context = []) use (&$logged): void {
        $logged = [$message, $context];
    });

    $driver = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ivan@mail.ru' for key 'customers.customers_email_unique'");
    $driver->errorInfo = ['23000', 1062, "Duplicate entry 'ivan@mail.ru' for key 'customers.customers_email_unique'"];
    $query = new QueryException('mysql', 'insert into customers (name, email) values (?, ?)', ['Иван', 'ivan@mail.ru'], $driver);

    expect($query->getMessage())->toContain('ivan@mail.ru');

    app(ExceptionHandler::class)->report($query);

    $text = json_encode($logged, JSON_UNESCAPED_UNICODE);

    expect($logged)->not->toBeNull()
        ->and($text)->not->toContain('ivan@mail.ru')->not->toContain('Иван')
        ->and($text)->toContain('23000')->toContain('1062')->toContain('customers_email_unique')->toContain('values (?, ?)');
});

it('keeps the full message when APP_DEBUG is on (S3-8)', function (): void {
    config(['app.debug' => true]);
    $logged = null;
    Log::shouldReceive('error')->andReturnUsing(function (string $message, array $context = []) use (&$logged): void {
        $logged = $message;
    });

    app(ExceptionHandler::class)->report(new QueryException('mysql', 'select ?', ['secret-value'], new PDOException('x')));

    expect($logged)->toContain('secret-value');
});

it('drops an unknown driver message entirely (S3-8)', function (): void {
    $driver = new PDOException("Incorrect integer value: 'ivan@mail.ru' for column 'qty'");
    $driver->errorInfo = ['HY000', 1366, "Incorrect integer value: 'ivan@mail.ru' for column 'qty'"];

    $sanitized = SanitizedQueryException::from(new QueryException('mysql', 'insert into t (qty) values (?)', ['ivan@mail.ru'], $driver));

    expect($sanitized->getMessage())->not->toContain('ivan@mail.ru')->toContain('HY000')
        ->and($sanitized->getSql())->toBe('insert into t (qty) values (?)');
});

// 9. Test mail.
it('hides the test mail action from a view-only role (S3-9)', function (): void {
    Mail::fake();
    $this->actingAs(User::factory()->withPermissions(['settings.notifications.view'])->create());

    $page = Livewire::test(ManageNotificationSettings::class);

    // A hidden action cannot be resolved, so it cannot be run either.
    expect(fn () => $page->callAction(TestAction::make('sendTestMail')->schemaComponent('testMailActions', schema: 'form')))
        ->toThrow(ActionNotResolvableException::class);

    Mail::assertNothingSent();
});

it('shows a generic error and logs only the class when the mailer fails (S3-9)', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Cannot send to ivan@mail.ru'));
    Log::shouldReceive('warning')->once()->withArgs(fn (string $m, array $c): bool => $c === ['exception' => RuntimeException::class]);

    Livewire::test(ManageNotificationSettings::class)
        ->callAction(TestAction::make('sendTestMail')->schemaComponent('testMailActions', schema: 'form'))
        ->assertNotified(__('notifications.test.mail_failed'));
});

// Review of PR #51, round 1 (R2).
it('keeps names and connection errors of a driver message but never a bound value (S3-8, D-206)', function (string $driverText, array $errorInfo, array $keep, array $drop): void {
    $driver = new PDOException($driverText);
    $driver->errorInfo = $errorInfo;

    $text = SanitizedQueryException::from(new QueryException('mysql', 'insert into t (a) values (?)', ['x'], $driver))->getMessage();

    foreach ($keep as $needle) {
        expect($text)->toContain($needle);
    }

    foreach ($drop as $needle) {
        expect($text)->not->toContain($needle);
    }
})->with([
    'unknown column' => ["Unknown column 'qty' in 'field list'", ['42S22', 1054, "Unknown column 'qty' in 'field list'"], ['unknown column qty'], []],
    'missing table' => ["Table 'shop.orders' doesn't exist", ['42S02', 1146, "Table 'shop.orders' doesn't exist"], ['shop.orders'], []],
    'too long' => ["Data too long for column 'name' at row 1", ['22001', 1406, "Data too long for column 'name' at row 1"], ['column name'], []],
    'incorrect value' => ["Incorrect integer value: 'ivan@mail.ru' for column 'qty' at row 1", ['HY000', 1366, "Incorrect integer value: 'ivan@mail.ru' for column 'qty' at row 1"], ['column qty'], ['ivan@mail.ru']],
    'foreign key' => ['Cannot add or update a child row: a foreign key constraint fails (`shop`.`order_items`, CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`))', ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails (`shop`.`order_items`, CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`))'], ['order_items_order_id_foreign'], []],
    'duplicate' => ["Duplicate entry 'ivan@mail.ru' for key 'customers.customers_email_unique'", ['23000', 1062, "Duplicate entry 'ivan@mail.ru' for key 'customers.customers_email_unique'"], ['customers.customers_email_unique'], ['ivan@mail.ru']],
    'sqlite unique' => ['UNIQUE constraint failed: customers.email', ['23000', 19, 'UNIQUE constraint failed: customers.email'], ['customers.email'], []],
    'connection refused' => ['SQLSTATE[HY000] [2002] Connection refused', [], ['[2002] Connection refused'], []],
    'access denied' => ["SQLSTATE[HY000] [1045] Access denied for user 'shop'@'10.0.0.5' (using password: YES)", [], ["Access denied for user 'shop'@'10.0.0.5'"], ['secret']],
    'unknown database' => ["SQLSTATE[HY000] [1049] Unknown database 'shopdb'", [], ["Unknown database 'shopdb'"], []],
]);

it('stores no bound values of a QueryException in failed_jobs unless APP_DEBUG is on (S3-8, D-206)', function (): void {
    config(['app.debug' => false]);
    $driver = new PDOException("Duplicate entry 'ivan@mail.ru' for key 'customers.customers_email_unique'");
    $driver->errorInfo = ['23000', 1062, "Duplicate entry 'ivan@mail.ru' for key 'customers.customers_email_unique'"];
    $query = new QueryException('mysql', 'insert into customers (email) values (?)', ['ivan@mail.ru'], $driver);

    $failer = app('queue.failer');
    expect($failer)->toBeInstanceOf(SanitizingFailedJobProvider::class);

    $failer->log('database', 'default', json_encode(['uuid' => 'u-1', 'job' => 'X']), $query);

    $stored = (string) DB::table('failed_jobs')->where('uuid', 'u-1')->value('exception');
    expect($stored)->not->toContain('ivan@mail.ru')->toContain('customers_email_unique');

    config(['app.debug' => true]);
    $failer->log('database', 'default', json_encode(['uuid' => 'u-2', 'job' => 'X']), $query);
    expect((string) DB::table('failed_jobs')->where('uuid', 'u-2')->value('exception'))->toContain('ivan@mail.ru');
});

it('counts a route throttle by the /64 network of an IPv6 visitor (D-208)', function (): void {
    Route::middleware('throttle:2,1')->get('/_probe-throttle', fn () => 'ok');

    $get = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('/_probe-throttle');

    $get('2001:db8:7:7::1')->assertOk();
    $get('2001:db8:7:7:ffff::2')->assertOk();
    $get('2001:db8:7:7:aaaa::3')->assertStatus(429);
    // Another /64 and another IPv4 address are unaffected.
    $get('2001:db8:7:8::1')->assertOk();
    $get('192.0.2.1')->assertOk();
});
