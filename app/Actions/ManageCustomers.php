<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class ManageCustomers
{
    public function query(User $user): Builder
    {
        // O escopo também protege as actions quando chamadas pela futura API.
        return Customer::query()->where('organization_id', $user->organization_id);
    }

    public function find(User $user, int $id): Customer
    {
        return $this->query($user)->findOrFail($id);
    }

    public function save(User $user, array $data, ?int $id = null): Customer
    {
        $customer = $id === null ? new Customer : $this->find($user, $id);
        $customer->fill(Arr::only($data, ['name', 'type', 'email', 'phone', 'document', 'notes']));
        $customer->organization_id = $user->organization_id;
        $customer->save();

        return $customer;
    }
}
