<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceEntry extends Model
{
    protected $fillable = ['type', 'description', 'category', 'amount_cents', 'occurred_on', 'payment_method', 'notes'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'occurred_on' => 'date'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // Valores em reais sao exibidos agrupando strings, sem conversao monetaria em float.
    public function getFormattedAmountAttribute(): string
    {
        return Money::format($this->amount_cents);
    }
}
