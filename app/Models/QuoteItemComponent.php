<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteItemComponent extends Model
{
    protected $fillable = ['quote_item_id', 'preset_code', 'name', 'kind', 'unit', 'quantity_per_unit_milli', 'quantity_milli', 'unit_cost_cents', 'cost_cents', 'production_sector'];

    protected function casts(): array
    {
        return ['quantity_per_unit_milli' => 'integer', 'quantity_milli' => 'integer', 'unit_cost_cents' => 'integer', 'cost_cents' => 'integer'];
    }
}
