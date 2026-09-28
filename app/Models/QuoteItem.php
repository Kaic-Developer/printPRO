<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuoteItem extends Model
{
    protected $fillable = ['quote_version_id', 'preset_code', 'name', 'unit', 'quantity_milli', 'answers', 'nesting', 'cost_cents', 'sale_cents', 'production_sector'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'nesting' => 'array', 'quantity_milli' => 'integer', 'cost_cents' => 'integer', 'sale_cents' => 'integer'];
    }

    public function components(): HasMany
    {
        return $this->hasMany(QuoteItemComponent::class);
    }
}
