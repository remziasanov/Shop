<?php

declare(strict_types=1);

use App\Models\CustomerCompany;

/**
 * CA-10 (TZ 17.3): company requisites with INN / KPP / OGRN checks; one set per customer.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->customer = makeCustomer();
    signInCustomer($this, $this->customer);
});

/** @return array<string, mixed> */
function companyBody(array $overrides = []): array
{
    return array_merge([
        'name' => 'ООО «Тестовая компания»',
        'inn' => '7707083893',
        'kpp' => '773601001',
        'ogrn' => '1027700132195',
        'legal_address' => 'г. Москва, ул. Примерная, 1',
    ], $overrides);
}

it('is closed to guests', function (): void {
    session()->flush();
    nextRequest();

    $this->get(route('customer.company'))->assertRedirect(route('customer.login'));
});

it('saves the requisites of an organization', function (): void {
    $this->post(route('customer.company.save'), companyBody())->assertRedirect(route('customer.company'));

    $company = CustomerCompany::query()->firstOrFail();

    expect($company->customer_id)->toBe($this->customer->id)
        ->and($company->inn)->toBe('7707083893')
        ->and($company->kpp)->toBe('773601001')
        ->and($company->ogrn)->toBe('1027700132195');

    $this->get(route('customer.company'))->assertOk()->assertSee('7707083893')->assertSee('Тестовая компания');
});

it('keeps one set of requisites per customer and updates it in place', function (): void {
    $this->post(route('customer.company.save'), companyBody());
    $this->post(route('customer.company.save'), companyBody(['name' => 'ООО «Новое имя»']));

    expect(CustomerCompany::query()->where('customer_id', $this->customer->id)->count())->toBe(1)
        ->and(CustomerCompany::query()->firstOrFail()->name)->toBe('ООО «Новое имя»');
});

it('accepts spaces in the numbers and an individual entrepreneur with OGRNIP and no KPP', function (): void {
    $this->post(route('customer.company.save'), companyBody(['inn' => '5001 0073 2259', 'kpp' => '', 'ogrn' => '304500116000157']))
        ->assertSessionDoesntHaveErrors();

    $company = CustomerCompany::query()->firstOrFail();
    expect($company->inn)->toBe('500100732259')->and($company->kpp)->toBeNull()->and($company->ogrn)->toBe('304500116000157');
});

it('rejects a wrong INN, KPP and OGRN with the messages of the requisites', function (): void {
    $this->post(route('customer.company.save'), companyBody(['inn' => '7707083894', 'kpp' => '12', 'ogrn' => '1027700132196']))
        ->assertSessionHasErrors(['inn', 'kpp', 'ogrn']);

    expect(CustomerCompany::query()->count())->toBe(0);
});

it('allows a KPP only with a 10-digit INN', function (): void {
    $validIp = '500100732259';

    $this->post(route('customer.company.save'), companyBody(['inn' => $validIp, 'kpp' => '773601001']))
        ->assertSessionHasErrors('kpp');
    $this->post(route('customer.company.save'), companyBody(['inn' => $validIp, 'kpp' => '', 'ogrn' => '']))
        ->assertSessionDoesntHaveErrors();
});

it('requires the name, the INN and the legal address', function (): void {
    $this->post(route('customer.company.save'), companyBody(['name' => '', 'inn' => '', 'legal_address' => '']))
        ->assertSessionHasErrors(['name', 'inn', 'legal_address']);
});

it('deletes the requisites', function (): void {
    CustomerCompany::factory()->create(['customer_id' => $this->customer->id]);

    $this->post(route('customer.company.destroy'))->assertRedirect(route('customer.company'));

    expect(CustomerCompany::query()->count())->toBe(0);
});
