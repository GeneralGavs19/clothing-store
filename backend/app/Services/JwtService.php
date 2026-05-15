<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

class JwtService
{
    public function issue(User $user): string
    {
        $now = Carbon::now();

        return $this->encode([
            'iss' => config('app.url'),
            'sub' => $user->id,
            'role' => $user->role,
            'iat' => $now->timestamp,
            'exp' => $now->copy()->addMinutes((int) env('JWT_TTL', 480))->timestamp,
        ]);
    }

    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token.');
        }

        [$header, $payload, $signature] = $parts;
        $expected = $this->sign($header.'.'.$payload);

        if (! hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid token signature.');
        }

        $claims = json_decode($this->base64UrlDecode($payload), true);
        if (! is_array($claims) || ($claims['exp'] ?? 0) < time()) {
            throw new RuntimeException('Expired token.');
        }

        return $claims;
    }

    private function encode(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $body = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        return $header.'.'.$body.'.'.$this->sign($header.'.'.$body);
    }

    private function sign(string $value): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $value, $this->secret(), true));
    }

    private function secret(): string
    {
        $secret = (string) env('JWT_SECRET', config('app.key'));
        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET is not configured.');
        }

        return $secret;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}
