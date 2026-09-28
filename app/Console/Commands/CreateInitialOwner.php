<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateInitialOwner extends Command
{
    protected $signature = 'printpro:create-owner';

    protected $description = 'Cria com segurança o primeiro proprietário local de uma gráfica';

    public function handle(): int
    {
        // Esta rotina interativa existe para preparar o ambiente local sem deixar senha em argumentos ou arquivos.
        if (! app()->isLocal()) {
            $this->components->error('Este comando só pode ser usado no ambiente local.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim($this->ask('E-mail do proprietário')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('Informe um e-mail válido.');

            return self::INVALID;
        }

        if (User::where('email', $email)->exists()) {
            $this->components->error('Este e-mail já possui uma conta. Nenhuma conta ou senha foi alterada.');

            return self::FAILURE;
        }

        $name = trim($this->ask('Nome do proprietário'));
        $organizationName = trim($this->ask('Nome da gráfica'));
        if ($name === '' || $organizationName === '') {
            $this->components->error('Nome e nome da gráfica são obrigatórios.');

            return self::INVALID;
        }
        $password = $this->secret('Senha inicial (mínimo 8 caracteres)');
        $confirmation = $this->secret('Confirme a senha');

        if ($password === false || strlen($password) < 8 || ! hash_equals($password, (string) $confirmation)) {
            $this->components->error('As senhas não conferem ou possuem menos de 8 caracteres.');

            return self::INVALID;
        }

        $user = DB::transaction(function () use ($email, $name, $organizationName, $password): User {
            $organization = Organization::create(['name' => $organizationName]);

            return $organization->users()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
        });

        $this->components->info("Conta proprietária criada para {$user->email} na gráfica {$user->organization->name}.");

        return self::SUCCESS;
    }
}
