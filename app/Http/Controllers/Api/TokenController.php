<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['required', 'string', 'max:80']]);
        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'As credenciais informadas são inválidas.']);
        }
        $token = $user->createToken($data['device_name'], ['quotes:read', 'quotes:write', 'catalog:read', 'catalog:write', 'production:read']);
        return response()->json(['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'user' => $user->only(['id', 'name', 'email', 'organization_id'])], 201);
    }

    public function destroy(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->noContent();
    }
}
