<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function login(Request $request, JwtService $jwt, ActivityLogger $logger)
    {
        Log::info('AuthController@login payload', $request->all());
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Неверный email или пароль.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Пользователь отключен.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $logger->log('auth.login', $user, [], $request);

        return response()->json([
            'token' => $jwt->issue($user),
            'user' => $user,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }

    public function register(Request $request, JwtService $jwt, ActivityLogger $logger)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['admin', 'cashier'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = User::create([
            'name' => strip_tags($data['name']),
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $logger->log('users.created', $user, ['role' => $user->role], $request);

        return response()->json([
            'user' => $user,
            'token' => $jwt->issue($user),
        ], 201);
    }

    public function logout(Request $request, ActivityLogger $logger)
    {
        $logger->log('auth.logout', $request->user(), [], $request);

        return response()->json(['message' => 'Logged out.']);
    }
}
