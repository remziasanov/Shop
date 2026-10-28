<?php

declare(strict_types=1);

use App\Enums\AgeGateMode;
use App\Enums\AgeRestriction;
use App\Services\Age\AgeGate;

beforeEach(function () {
    $this->withoutVite();
});

it('sets the plain cookie with the level and returns to the page (AG-06, AG-08)', function () {
    ageGate(['remember_days' => 10]);

    $response = $this->post(route('age-gate.confirm'), ['level' => '18', 'return' => '/catalog?page=2'])
        ->assertRedirect('/catalog?page=2');

    $cookie = $response->getCookie('shop_age', false);

    expect($cookie)->not->toBeNull()
        ->and($cookie->getValue())->toBe('18')
        ->and($cookie->isHttpOnly())->toBeFalse()
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(9)->getTimestamp())
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(10)->getTimestamp());
});

it('keeps the cookie unencrypted so CSS and scripts can read it (AG-06)', function () {
    $this->post(route('age-gate.confirm'), ['level' => '16'])->assertCookie('shop_age', '16', false);

    expect(app(AgeGate::class)->confirmedAge(request()->duplicate(cookies: ['shop_age' => '16'])))->toBe(16);
});

it('accepts only the configured levels (AG-08, TZ 16.7)', function (mixed $level) {
    $this->post(route('age-gate.confirm'), ['level' => $level])
        ->assertSessionHasErrors('level')
        ->assertCookieMissing('shop_age');
})->with(['21', '0', '17', 'abc', '', '18; Secure']);

it('requires a level', function () {
    $this->post(route('age-gate.confirm'))->assertSessionHasErrors('level');
});

it('ignores a return target that is not an internal path (open redirect, TZ 16.7)', function (string $return) {
    $this->post(route('age-gate.confirm'), ['level' => '18', 'return' => $return])->assertRedirect('/');
})->with([
    'absolute URL' => 'https://evil.example/phish',
    'protocol-relative' => '//evil.example/phish',
    'backslash trick' => '/\\evil.example',
    'javascript' => 'javascript:alert(1)',
    'no leading slash' => 'evil.example',
    'control characters' => "/ok\r\nLocation: https://evil.example",
]);

it('falls back to the home page without a return target', function () {
    $this->post(route('age-gate.confirm'), ['level' => '16'])->assertRedirect('/');
});

it('does not lower an already confirmed higher level', function () {
    $this->withUnencryptedCookie('shop_age', '18')->post(route('age-gate.confirm'), ['level' => '16'])
        ->assertCookie('shop_age', '18', false);
});

it('protects the confirmation with the web middleware group, CSRF included (TZ 16.7)', function () {
    $route = app('router')->getRoutes()->getByName('age-gate.confirm');

    expect($route->methods())->toContain('POST')
        ->and($route->gatherMiddleware())->toContain('web');
});

it('reads only 16 and 18 from the cookie', function (mixed $value, int $expected) {
    $request = request()->duplicate(cookies: ['shop_age' => $value]);

    expect(app(AgeGate::class)->confirmedAge($request))->toBe($expected);
})->with([['16', 16], ['18', 18], ['21', 0], ['', 0], ['18+', 0], ['0', 0]]);

it('sends a refusal to the home page with a flash message (AG-08)', function () {
    ageGate(['mode' => AgeGateMode::Restricted, 'decline_message' => 'Приходите, когда вам будет :x']);

    $this->get(route('age-gate.decline'))->assertRedirect(route('home'))->assertSessionHas(AgeGate::FLASH_DECLINED, '18');
});

it('shows the refusal state of the overlay on the home page in site mode (AG-08)', function () {
    ageGate(['mode' => AgeGateMode::Site, 'decline_message' => 'Содержимое только с {age} лет.']);
    ageProduct(ageCategory(AgeRestriction::Age18));

    $this->followingRedirects()->get(route('age-gate.decline', ['level' => '18']))->assertOk()
        ->assertSee('Содержимое только с 18 лет.')
        ->assertSee('Мне уже есть 18')
        ->assertDontSee('Да, мне есть 18');
});

it('shows the refusal as a notice in restricted mode, where the home page has no overlay (AG-08)', function () {
    ageGate(['mode' => AgeGateMode::Restricted, 'decline_message' => 'Раздел только с {age} лет.']);
    ageProduct(ageCategory(AgeRestriction::Age16));

    $this->followingRedirects()->get(route('age-gate.decline', ['level' => '16']))->assertOk()
        ->assertSee('Раздел только с 16 лет.')
        ->assertDontSee('data-age-need', false);

    // One time only.
    $this->get(route('home'))->assertDontSee('Раздел только с 16 лет.');
});

it('replaces {age} in every text of the overlay (AG-02)', function () {
    ageGate([
        'mode' => AgeGateMode::Site,
        'title' => 'Нужно {age}?',
        'text' => 'Только с {age} лет.',
        'yes_label' => 'Мне {age}',
        'no_label' => 'Нет, мне меньше {age}',
    ]);
    ageProduct(ageCategory(AgeRestriction::Age18));

    $this->get(route('home'))->assertSee('Нужно 18?')->assertSee('Только с 18 лет.')->assertSee('Мне 18')->assertSee('Нет, мне меньше 18');
});
