<?php

namespace App\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticateUser
{
    public function execute(array $data, string $ip): void
    {
        $key = 'login:'.hash('sha256', Str::lower($data['email']).'|'.$ip);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Muitas tentativas. Tente novamente em '.RateLimiter::availableIn($key).' segundos.']);
        }
        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']], (bool) ($data['remember'] ?? false))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }
        RateLimiter::clear($key);
    }
}
