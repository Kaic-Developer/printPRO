<?php

namespace App\Actions;

use App\Models\FinanceEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ManageFinance
{
    public function query(User $user): Builder
    {
        // Todos os totais e listagens partem da organização autenticada.
        return FinanceEntry::query()->where('organization_id', $user->organization_id);
    }

    public function create(User $user, array $data): FinanceEntry
    {
        $amount = str_replace('.', '', trim($data['amount']));
        [$whole, $fraction] = array_pad(explode(',', $amount, 2), 2, '00');
        $whole = ltrim($whole, '0') ?: '0';
        $amountCents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if ($amountCents < 1) {
            throw ValidationException::withMessages(['amount' => 'O valor precisa ser maior que zero.']);
        }

        $entry = new FinanceEntry;
        $entry->fill([
            'type' => $data['type'],
            'description' => trim($data['description']),
            'category' => $data['category'] ?? null,
            'amount_cents' => $amountCents,
            'occurred_on' => $data['occurred_on'],
            'payment_method' => $data['payment_method'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $entry->organization_id = $user->organization_id;
        $entry->save();

        return $entry;
    }
}
