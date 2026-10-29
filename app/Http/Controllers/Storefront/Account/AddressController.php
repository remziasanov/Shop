<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Exceptions\CustomerAccountException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Http\Requests\Account\AddressRequest;
use App\Models\CustomerAddress;
use App\Services\Customers\CustomerAddressService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Address book (CA-09). The address is looked up among the addresses of the signed-in customer only: the id of
 * someone else's address is a 404 (CA-28).
 */
class AddressController extends Controller
{
    use RendersAccountPages;

    public function index(Request $request, CustomerAddressService $addresses): View
    {
        return $this->page('addresses', __('account.addresses.title'), [
            'addresses' => $addresses->listFor($this->currentCustomer($request)),
            'max' => CustomerAddress::MAX_PER_CUSTOMER,
        ]);
    }

    public function store(AddressRequest $request, CustomerAddressService $addresses): RedirectResponse
    {
        try {
            $addresses->create($this->currentCustomer($request), $request->addressData());
        } catch (CustomerAccountException $e) {
            return back()->withInput()->withErrors(['label' => $e->getMessage()]);
        }

        return redirect()->route('customer.addresses')->with('status', __('account.addresses.saved'));
    }

    public function update(AddressRequest $request, int $address, CustomerAddressService $addresses): RedirectResponse
    {
        $record = $addresses->find($this->currentCustomer($request), $address) ?? abort(404);
        $addresses->update($record, $request->addressData());

        return redirect()->route('customer.addresses')->with('status', __('account.addresses.saved'));
    }

    public function makeDefault(Request $request, int $address, CustomerAddressService $addresses): RedirectResponse
    {
        $record = $addresses->find($this->currentCustomer($request), $address) ?? abort(404);
        $addresses->makeDefault($record);

        return redirect()->route('customer.addresses')->with('status', __('account.addresses.default_set'));
    }

    public function destroy(Request $request, int $address, CustomerAddressService $addresses): RedirectResponse
    {
        $record = $addresses->find($this->currentCustomer($request), $address) ?? abort(404);
        $addresses->delete($record);

        return redirect()->route('customer.addresses')->with('status', __('account.addresses.deleted'));
    }
}
