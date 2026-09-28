<?php

namespace App\Http\Controllers;

use App\Actions\ManageCustomers;
use App\Http\Requests\CustomerRequest;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(private ManageCustomers $customers) {}

    public function index(Request $request)
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:255']]);
        $search = trim($validated['q'] ?? '');
        $customers = $this->customers->query($request->user())
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')->orWhere('document', 'like', '%'.$search.'%');
            }))->orderBy('name')->orderBy('id')->paginate(15)->withQueryString();

        return view('customers.index', compact('customers', 'search'));
    }

    public function create()
    {
        return view('customers.create');
    }

    public function store(CustomerRequest $request)
    {
        $customer = $this->customers->save($request->user(), $request->validated());

        return redirect()->route('customers.show', $customer)->with('status', 'Cliente cadastrado.');
    }

    public function show(Request $request, int $customer)
    {
        return view('customers.show', ['customer' => $this->customers->find($request->user(), $customer)]);
    }

    public function edit(Request $request, int $customer)
    {
        return view('customers.edit', ['customer' => $this->customers->find($request->user(), $customer)]);
    }

    public function update(CustomerRequest $request, int $customer)
    {
        $customer = $this->customers->save($request->user(), $request->validated(), $customer);

        return redirect()->route('customers.show', $customer)->with('status', 'Cliente atualizado.');
    }
}
