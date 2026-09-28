<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationQuoteSetting extends Model
{
    protected $fillable = ['organization_id', 'waste_basis_points', 'markup_multiplier_basis_points'];

    protected function casts(): array
    {
        return ['waste_basis_points' => 'integer', 'markup_multiplier_basis_points' => 'integer'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
