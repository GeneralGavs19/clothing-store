<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class JwtMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $claims = app(JwtService::class)->decode($token);
            $user = User::query()->whereKey($claims['sub'] ?? null)->where('is_active', true)->first();
        } catch (Throwable) {
            return response()->json(['message' => 'Invalid or expired token.'], 401);
        }

        if (! $user) {
            return response()->json(['message' => 'User is inactive or missing.'], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
