<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuotePreset extends Model
{
    protected $fillable = ['parent_id', 'code', 'kind', 'name', 'unit', 'production_sector', 'wizard_key', 'suggested_components', 'wizard_schema', 'is_available'];

    protected function casts(): array
    {
        return ['suggested_components' => 'array', 'wizard_schema' => 'array', 'is_available' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }
}
