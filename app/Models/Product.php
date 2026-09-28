<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    // A organização é definida pela conta autenticada, nunca pelo formulário.
    protected $fillable = ['name', 'category', 'sku', 'description', 'unit', 'unit_label', 'price_cents', 'is_active'];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'is_active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // Retorna texto decimal a partir de centavos inteiros, sem cálculo em float.
    public function getPriceAttribute(): ?string
    {
        if ($this->price_cents === null) {
            return null;
        }

        return intdiv($this->price_cents, 100).'.'.str_pad((string) ($this->price_cents % 100), 2, '0', STR_PAD_LEFT);
    }

    // Formata a exibição agrupando dígitos da string, sem converter dinheiro em float.
    public function getFormattedPriceAttribute(): ?string
    {
        if ($this->price === null) {
            return null;
        }

        [$whole, $fraction] = explode('.', $this->price, 2);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole);

        return 'R$ '.$whole.','.$fraction;
    }
}
