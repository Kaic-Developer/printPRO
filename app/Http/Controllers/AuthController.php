<?php

namespace App\Http\Controllers;

use App\Actions\AuthenticateUser;
use App\Actions\RegisterOwner;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function register()
    {
        return view('auth.register');
    }

    public function storeLogin(LoginRequest $request, AuthenticateUser $action)
    {
        $action->execute($request->validated(), $request->ip());
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function storeRegister(RegisterRequest $request, RegisterOwner $action)
    {
        Auth::login($action->execute($request->validated()));
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
