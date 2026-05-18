<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\ApiPagination;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $paginator = $query->paginate($request->integer('per_page', 15));
        $includePasswords = $request->user()->isAdminProgrammer();

        $paginator->getCollection()->transform(
            fn (User $user) => $user->toApiArray($includePasswords)
        );

        return response()->json(ApiPagination::format($paginator));
    }

    public function store(Request $request, ActivityLogger $logger)
    {
        $data = $this->validatedUser($request);

        $user = User::create([
            'name' => strip_tags($data['name']),
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'password_plain' => $data['password'],
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $logger->log('users.created', $user, ['role' => $user->role], $request);

        return response()->json($user->toApiArray(true), 201);
    }

    public function update(Request $request, User $user, ActivityLogger $logger)
    {
        $data = $this->validatedUser($request, $user);

        if (isset($data['name'])) {
            $data['name'] = strip_tags($data['name']);
        }
        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }
        if (! empty($data['password'])) {
            $data['password_plain'] = $data['password'];
        } else {
            unset($data['password']);
        }

        $user->update($data);
        $logger->log('users.updated', $user, [], $request);

        return response()->json($user->fresh()->toApiArray(true));
    }

    public function destroy(Request $request, User $user, ActivityLogger $logger)
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Нельзя отключить себя.'], 422);
        }

        $user->update(['is_active' => false]);
        $logger->log('users.disabled', $user, [], $request);

        return response()->json(['message' => 'Пользователь отключен.', 'user' => $user->toApiArray(true)]);
    }

    private function validatedUser(Request $request, ?User $user = null): array
    {
        $allowedRoles = ['admin', 'cashier'];
        if ($request->user()->isAdminProgrammer()) {
            $allowedRoles[] = 'admin_programmer';
        }

        $data = $request->validate([
            'name' => [$user ? 'sometimes' : 'required', 'string', 'max:120'],
            'email' => [$user ? 'sometimes' : 'required', 'email', 'max:180', Rule::unique('users')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => [$user ? 'sometimes' : 'required', Rule::in($allowedRoles)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (($data['role'] ?? null) === 'admin_programmer' && ! $request->user()->isAdminProgrammer()) {
            throw ValidationException::withMessages(['role' => 'Недостаточно прав для этой роли.']);
        }

        if ($user && $user->isAdminProgrammer() && $request->user()->id !== $user->id) {
            if (isset($data['role']) && $data['role'] !== 'admin_programmer') {
                throw ValidationException::withMessages(['role' => 'Нельзя понизить роль администратора программиста.']);
            }
        }

        return $data;
    }
}
