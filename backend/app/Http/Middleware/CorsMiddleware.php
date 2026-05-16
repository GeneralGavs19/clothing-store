<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)->withHeaders($this->headers($request));
        }

        $response = $next($request);
        foreach ($this->headers($request) as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }

    private function headers(Request $request): array
    {
        $origin = $this->resolveAllowOrigin($request);
        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ];

        if ($origin !== null) {
            $headers['Access-Control-Allow-Origin'] = $origin;
        }

        return $headers;
    }

    private function resolveAllowOrigin(Request $request): ?string
    {
        $requestOrigin = $request->headers->get('Origin');
        if (! $requestOrigin) {
            return '*';
        }

        if ($this->isAllowedVercelOrigin($requestOrigin)) {
            return $requestOrigin;
        }

        if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $requestOrigin)) {
            return $requestOrigin;
        }

        $configured = trim((string) env('FRONTEND_URL', '*'));
        if ($configured === '' || $configured === '*') {
            return $requestOrigin;
        }

        $allowed = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $configured) ?: [])));

        if (in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }

        return null;
    }

    private function isAllowedVercelOrigin(string $origin): bool
    {
        return (bool) preg_match('#^https://[\w.-]+\.vercel\.app$#i', $origin);
    }
}
