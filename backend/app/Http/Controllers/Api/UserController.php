<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\ApiPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->latest();

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }
        if ($request->filled('role')) {
            $query->where('role', $request->string('role')->toString());
        }

        return response()->json(ApiPagination::format($query->paginate($request->integer('per_page', 15))));
    }

    public function store(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users')],
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

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user, ActivityLogger $logger)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'email', 'max:180', Rule::unique('users')->ignore($user)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['sometimes', Rule::in(['admin', 'cashier'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $data['name'] = strip_tags($data['name']);
        }
        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }
        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);
        $logger->log('users.updated', $user, [], $request);

        return response()->json($user);
    }

    public function destroy(Request $request, User $user, ActivityLogger $logger)
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot disable yourself.'], 422);
        }

        $user->update(['is_active' => false]);
        $logger->log('users.disabled', $user, [], $request);

        return response()->json(['message' => 'User disabled.', 'user' => $user]);
    }
}
