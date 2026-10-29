<?php

declare(strict_types=1);

use App\Mail\TestMail;
use App\Settings\GeneralSettings;
use App\Support\ShopName;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config([
        'mail.default' => 'array',
        'mail.from.address' => 'shop@example.ru',
        'mail.from.name' => 'Магазин',
        'app.name' => 'Магазин',
    ]);

    $settings = app(GeneralSettings::class);
    $settings->shop_name = 'Ромашка';
    $settings->save();
});

function sentMessages(): array
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all();
}

it('uses the shop name from the settings as the sender name', function () {
    Mail::to('buyer@example.ru')->send(new TestMail);

    $from = sentMessages()[0]->getOriginalMessage()->getFrom();

    expect($from)->toHaveCount(1)
        ->and($from[0]->getAddress())->toBe('shop@example.ru')
        ->and($from[0]->getName())->toBe('Ромашка');
});

it('leaves a different sender address untouched', function () {
    Mail::raw('Текст', function ($message) {
        $message->to('buyer@example.ru')->from('other@example.ru', 'Другой');
    });

    $from = sentMessages()[0]->getOriginalMessage()->getFrom();

    expect($from[0]->getName())->toBe('Другой');
});

it('shows the shop name from the settings in the mail header and footer', function () {
    $html = (new TestMail)->render();

    expect($html)->toContain('Ромашка')
        ->and($html)->toContain('© '.date('Y').' Ромашка.')
        ->and($html)->not->toContain('© '.date('Y').' Магазин.');
});

it('falls back to APP_NAME when the shop name is empty', function () {
    $settings = app(GeneralSettings::class);
    $settings->shop_name = '  ';
    $settings->save();

    expect(ShopName::get())->toBe('Магазин');
});
