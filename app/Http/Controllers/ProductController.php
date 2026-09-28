<?php

namespace App\Http\Controllers;

use App\Actions\ManageProducts;
use App\Http\Requests\ProductRequest;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private ManageProducts $products) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:all,active,inactive'],
        ]);
        $search = trim($validated['q'] ?? '');
        $status = $validated['status'] ?? 'all';
        $products = $this->products->query($request->user())
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')->orWhere('category', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%');
            }))
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')->orderBy('id')->paginate(12)->withQueryString();

        return view('products.index', compact('products', 'search', 'status'));
    }

    public function create()
    {
        return view('products.create');
    }

    public function store(ProductRequest $request)
    {
        $product = $this->products->save($request->user(), $request->validated());

        return redirect()->route('products.show', $product)->with('status', 'Produto cadastrado.');
    }

    public function show(Request $request, int $product)
    {
        return view('products.show', ['product' => $this->products->find($request->user(), $product)]);
    }

    public function edit(Request $request, int $product)
    {
        return view('products.edit', ['product' => $this->products->find($request->user(), $product)]);
    }

    public function update(ProductRequest $request, int $product)
    {
        $product = $this->products->save($request->user(), $request->validated(), $product);

        return redirect()->route('products.show', $product)->with('status', 'Produto atualizado.');
    }

    public function toggle(Request $request, int $product)
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $record = $this->products->find($request->user(), $product);
        $record->is_active = (bool) $validated['is_active'];
        $record->save();

        return redirect()->route('products.show', $record)->with('status', 'Situação do produto atualizada.');
    }
}
