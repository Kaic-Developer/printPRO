<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    // A organização só pode ser atribuída pela relação autenticada.
    protected $fillable = ['name', 'type', 'email', 'phone', 'document', 'notes'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
