<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuoteVersion extends Model
{
    protected $fillable = ['quote_id', 'version_number', 'created_by', 'snapshot', 'cost_total_cents', 'sale_total_cents', 'is_calculable'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version_number' => 'integer', 'cost_total_cents' => 'integer', 'sale_total_cents' => 'integer', 'is_calculable' => 'boolean'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }
}
