<?php

declare(strict_types=1);

use App\Rules\Inn;
use App\Rules\Kpp;
use App\Rules\Ogrn;
use App\Services\Customers\InnValidator;
use Illuminate\Support\Facades\Validator;

it('accepts INNs with correct check digits (CA-10)', function (string $inn) {
    expect((new InnValidator)->isValidInn($inn))->toBeTrue();
})->with(['7707083893', '7830002293', '500100732259', ' 7707083893 ']);

it('rejects INNs with a wrong length, letters, zeros or check digits (CA-10)', function (string $inn) {
    expect((new InnValidator)->isValidInn($inn))->toBeFalse();
})->with(['7707083894', '500100732258', '500100732269', '770708389', '77070838931', '0000000000', '77070838 3', 'abcdefghij', '']);

it('tells a legal entity INN from an entrepreneur one', function () {
    $validator = new InnValidator;

    expect($validator->isLegalEntityInn('7707083893'))->toBeTrue()
        ->and($validator->isLegalEntityInn('500100732259'))->toBeFalse();
});

it('checks the KPP format NNNNXXNNN (CA-10)', function () {
    $validator = new InnValidator;

    expect($validator->isValidKpp('773601001'))->toBeTrue()
        ->and($validator->isValidKpp('7736AB001'))->toBeTrue()
        ->and($validator->isValidKpp('77360100'))->toBeFalse()
        ->and($validator->isValidKpp('7736ab001'))->toBeFalse()
        ->and($validator->isValidKpp('A73601001'))->toBeFalse();
});

it('checks OGRN and OGRNIP check digits (CA-10)', function () {
    $validator = new InnValidator;

    expect($validator->isValidOgrn('1027700132195'))->toBeTrue()
        ->and($validator->isValidOgrn('1027700132196'))->toBeFalse()
        ->and($validator->isValidOgrn('304500116000157'))->toBeTrue()
        ->and($validator->isValidOgrn('304500116000158'))->toBeFalse()
        ->and($validator->isValidOgrn('10277001321'))->toBeFalse();
});

it('exposes the checks as validation rules with Russian messages', function () {
    $validator = Validator::make(
        ['inn' => '7707083894', 'kpp' => '12', 'ogrn' => '1027700132195'],
        ['inn' => [new Inn], 'kpp' => [new Kpp], 'ogrn' => [new Ogrn]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('inn'))->toBe(__('requisites.validation.inn'))
        ->and($validator->errors()->first('kpp'))->toBe(__('requisites.validation.kpp'))
        ->and($validator->errors()->has('ogrn'))->toBeFalse();
});
