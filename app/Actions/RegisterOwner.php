<?php

namespace App\Actions;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterOwner
{
    public function execute(array $data): User
    {
        // Falha no cadastro do proprietário também desfaz a criação da gráfica.
        return DB::transaction(function () use ($data) {
            $organization = Organization::create(['name' => $data['organization_name']]);

            return $organization->users()->create([
                'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
            ]);
        });
    }
}
