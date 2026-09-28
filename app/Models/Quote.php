<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    protected $fillable = ['organization_id', 'customer_id', 'created_by', 'number', 'status', 'current_version', 'expires_at', 'approved_at'];

    protected function casts(): array
    {
        return ['current_version' => 'integer', 'expires_at' => 'date', 'approved_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuoteVersion::class)->orderByDesc('version_number');
    }

    public function currentVersion(): ?QuoteVersion
    {
        return $this->versions()->where('version_number', $this->current_version)->first();
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }
}
