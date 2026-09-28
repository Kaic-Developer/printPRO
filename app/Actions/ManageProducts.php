<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class ManageProducts
{
    public function query(User $user): Builder
    {
        // Toda leitura começa no tenant autenticado para evitar IDOR.
        return Product::query()->where('organization_id', $user->organization_id);
    }

    public function find(User $user, int $id): Product
    {
        return $this->query($user)->findOrFail($id);
    }

    public function save(User $user, array $data, ?int $id = null): Product
    {
        $product = $id === null ? new Product : $this->find($user, $id);
        $price = trim((string) ($data['price'] ?? ''));
        $priceCents = null;

        // Converta a string validada em inteiro sem operações de ponto flutuante.
        if ($price !== '') {
            [$whole, $fraction] = array_pad(preg_split('/[.,]/', $price, 2), 2, '0');
            $priceCents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        }

        $attributes = Arr::only($data, ['name', 'category', 'sku', 'description', 'unit', 'unit_label', 'is_active']);
        $product->fill($attributes);
        $product->unit_label = $data['unit'] === 'custom' ? trim($data['unit_label']) : null;
        $product->price_cents = $priceCents;
        $product->organization_id = $user->organization_id;
        $product->save();

        return $product;
    }
}
