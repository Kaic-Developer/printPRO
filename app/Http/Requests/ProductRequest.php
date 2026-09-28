<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Product;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        // O escopo é verificado antes das regras para ocultar IDs de outros tenants.
        $productId = $this->route('product');

        if ($productId !== null) {
            abort_unless(Product::query()->where('organization_id', $this->user()->organization_id)->whereKey($productId)->exists(), 404);
        }

        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        $productId = $this->route('product');

        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('products', 'sku')->where('organization_id', $organizationId)->ignore($productId)],
            'description' => ['nullable', 'string', 'max:10000'],
            'unit' => ['required', Rule::in(['unit', 'linear_meter', 'square_meter', 'lot', 'custom'])],
            'unit_label' => ['nullable', 'required_if:unit,custom', 'string', 'max:255'],
            // Limite o tamanho antes da conversão para garantir inteiros seguros em PHP.
            'price' => ['nullable', 'string', 'regex:/\A\d{1,15}(?:[.,]\d{1,2})?\z/'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['price.regex' => 'Informe um valor com até duas casas decimais e sem separador de milhar.'];
    }
}
