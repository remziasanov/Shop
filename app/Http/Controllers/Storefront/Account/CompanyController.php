<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Http\Requests\Account\CompanyRequest;
use App\Services\Customers\CustomerCompanyService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Company requisites (CA-10): one set per customer.
 */
class CompanyController extends Controller
{
    use RendersAccountPages;

    public function show(Request $request, CustomerCompanyService $companies): View
    {
        return $this->page('company', __('account.company.title'), [
            'company' => $companies->find($this->currentCustomer($request)),
        ]);
    }

    public function save(CompanyRequest $request, CustomerCompanyService $companies): RedirectResponse
    {
        $companies->save($this->currentCustomer($request), $request->companyData());

        return redirect()->route('customer.company')->with('status', __('account.company.saved'));
    }

    public function destroy(Request $request, CustomerCompanyService $companies): RedirectResponse
    {
        $companies->delete($this->currentCustomer($request));

        return redirect()->route('customer.company')->with('status', __('account.company.deleted'));
    }
}
