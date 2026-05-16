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

        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Vary' => 'Origin',
        ];
    }

    private function resolveAllowOrigin(Request $request): string
    {
        $configured = trim((string) env('FRONTEND_URL', '*'));
        if ($configured === '' || $configured === '*') {
            return '*';
        }

        $allowed = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $configured) ?: [])));
        $requestOrigin = $request->headers->get('Origin');

        if ($requestOrigin && in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }

        return $allowed[0] ?? '*';
    }
}
