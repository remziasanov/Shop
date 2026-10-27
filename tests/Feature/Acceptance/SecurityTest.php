<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Filament\Pages\Settings\ManageAppearanceSettings;
use App\Filament\Pages\Settings\ManageGeneralSettings;
use App\Filament\Pages\Settings\ManageSeoSettings;
use App\Models\Product;
use App\Models\User;
use App\Settings\AppearanceSettings;
use App\Settings\GeneralSettings;
use App\Settings\SeoSettings;
use Filament\Auth\Pages\Login;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

/**
 * NF-10…NF-15 acceptance checks: headers, CSRF, escaping, uploads, admin login hardening, secrets in the repo.
 */
beforeEach(fn () => $this->withoutVite());

function expectSecurityHeaders(TestResponse $response): void
{
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
}

it('sends security headers on storefront pages, JSON, errors and the admin panel (NF-13)', function (): void {
    $product = Product::factory()->create();

    foreach (['/', '/catalog', '/product/'.$product->slug, '/search/suggest?q=ab', '/cart', '/robots.txt', '/no-such-page', '/admin/login'] as $url) {
        expectSecurityHeaders($this->get($url));
    }

    $this->actingAs(User::factory()->admin()->create());

    expectSecurityHeaders($this->get('/admin'));
    expectSecurityHeaders($this->get(ManageGeneralSettings::getUrl()));
});

it('rejects a storefront POST without a CSRF token and accepts one with it (NF-10)', function (): void {
    $product = Product::factory()->salesMode(SalesMode::Cart)->create(['stock_qty' => 5]);

    // The CSRF middleware is skipped when the application runs in the "testing" environment.
    app()->detectEnvironment(fn (): string => 'local');

    $this->post(route('cart.add'), ['product_id' => $product->id])->assertStatus(419);

    $status = $this->withSession(['_token' => 'csrf-token'])
        ->post(route('cart.add'), ['product_id' => $product->id, '_token' => 'csrf-token'])
        ->getStatusCode();

    expect($status)->not->toBe(419);
});

it('escapes a product name with markup on every storefront page (NF-10)', function (): void {
    $payload = '<script>alert("xss")</script>';
    $product = Product::factory()->create(['name' => 'Лампа '.$payload, 'brand' => '<img src=x onerror=alert(1)>']);

    foreach (['/catalog', '/product/'.$product->slug, '/search?q='.rawurlencode('Лампа'), '/search?q='.rawurlencode('<script>alert(1)</script>')] as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect($html)->not->toContain($payload)
            ->and($html)->not->toContain('<img src=x onerror');
    }

    $this->get('/catalog')->assertSee('&lt;script&gt;', false);

    // The suggest endpoint returns data for Alpine `x-text`; it must be JSON, never HTML.
    $response = $this->getJson('/search/suggest?q='.rawurlencode('Лампа'))->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/json');
    expectSecurityHeaders($response);
});

it('sanitizes editor HTML on the product page (NF-10)', function (): void {
    $product = Product::factory()->create(['description' => '<p>Текст</p><script>window.x=1</script><a href="javascript:alert(1)" onclick="y()">link</a>']);

    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

    expect($html)->toContain('Текст')
        ->and($html)->not->toContain('window.x=1')
        ->and($html)->not->toContain('javascript:alert')
        ->and($html)->not->toContain('onclick=');
});

it('refuses SVG and non-images for public settings uploads (NF-11)', function (string $page, string $field, callable $current): void {
    $this->actingAs(User::factory()->admin()->create());

    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

    foreach ([UploadedFile::fake()->createWithContent('x.svg', $svg), UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')] as $file) {
        // A real upload is a Livewire temporary file addressed by a random key inside the field state.
        Livewire::test($page)
            ->set("data.{$field}.".Str::uuid()->toString(), $file)
            ->call('save')
            ->assertHasFormErrors([$field]);

        expect($current())->toBeNull();
    }
})->with([
    'logo' => [ManageGeneralSettings::class, 'logo', fn () => app(GeneralSettings::class)->refresh()->logo],
    'favicon' => [ManageGeneralSettings::class, 'favicon', fn () => app(GeneralSettings::class)->refresh()->favicon],
    'og image' => [ManageSeoSettings::class, 'og_image', fn () => app(SeoSettings::class)->refresh()->og_image],
    'placeholder' => [ManageAppearanceSettings::class, 'placeholder_image', fn () => app(AppearanceSettings::class)->refresh()->placeholder_image],
]);

it('locks the admin login form after repeated failures (NF-12)', function (): void {
    $user = User::factory()->admin()->create(['password' => Hash::make('correct-password-1')]);
    RateLimiter::clear('login');

    $component = Livewire::test(Login::class);

    foreach (range(1, 5) as $attempt) {
        $component->fillForm(['email' => $user->email, 'password' => 'wrong-password'])->call('authenticate');
    }

    // The sixth attempt is throttled even with the right password.
    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'correct-password-1'])
        ->call('authenticate');

    $this->assertGuest();
});

it('logs in with the right password and refuses a wrong one (NF-12)', function (): void {
    $user = User::factory()->admin()->create(['password' => Hash::make('correct-password-1')]);

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
    $this->assertGuest();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'correct-password-1'])
        ->call('authenticate')
        ->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($user);
});

it('does not let an inactive user in (NF-12)', function (): void {
    $user = User::factory()->admin()->create(['password' => Hash::make('correct-password-1'), 'is_active' => false]);

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'correct-password-1'])
        ->call('authenticate');

    $this->assertGuest();
});

it('requires passwords of at least 10 characters (NF-12)', function (): void {
    expect(Password::default()->appliedRules())->toMatchArray(['min' => 10]);
});

it('keeps session and cookie settings strict (NF-12)', function (): void {
    expect(config('session.lifetime'))->toBe(480)
        ->and(config('session.http_only'))->toBeTrue()
        ->and((string) file_get_contents(config_path('session.php')))->toContain("env('SESSION_DRIVER', 'file')")
        ->and((string) file_get_contents(config_path('cache.php')))->toContain("env('CACHE_STORE', 'file')");

    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toContain('SESSION_SECURE_COOKIE=true')
        ->and($example)->toContain('SESSION_LIFETIME=480')
        ->and($example)->toContain('APP_DEBUG=false')
        ->and($example)->toContain('APP_ENV=production');
});

it('uses explicit $fillable and no unguarded models (NF-10)', function (): void {
    foreach (glob(app_path('Models/*.php')) as $file) {
        $source = (string) file_get_contents($file);

        expect($source)->not->toContain('$guarded')
            ->and($source)->toMatch('/\$fillable|#\[Fillable/', basename($file).' has no explicit fillable list');
    }

    expect((string) shell_exec('grep -rn "Model::unguard\|unguarded(" '.escapeshellarg(app_path())))->toBe('');
});

it('does not commit secrets (NF-14)', function (): void {
    $output = [];
    exec('cd '.escapeshellarg(base_path()).' && git grep -nIiE '.escapeshellarg('(password|secret|token|api[_-]?key)\s*[=:]\s*["\'][^"\']{6,}').' -- app config database routes resources lang bootstrap .env.example 2>/dev/null', $output);

    // The only allowed hit: the CSRF token attribute rendered by Blade.
    $output = array_values(array_filter($output, fn (string $line) => ! str_contains($line, 'csrf_token()')));

    expect($output)->toBe([]);
});
