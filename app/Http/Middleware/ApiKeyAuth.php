<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API Key Authentication Middleware
 *
 * Ensures that only authorized clients (OpenLuxe) can access the API.
 * Checks both API key and origin domain for security.
 */
class ApiKeyAuth
{
    /**
     * Allowed domains that can access the API
     */
    private array $allowedDomains = [
        'openluxe.test',
        'openluxe.co',
        'www.openluxe.co',
        'localhost', // For local development
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if API key is present
        $apiKey = $this->getApiKey($request);

        if (!$apiKey) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'API key is required',
                'error' => 'Missing Authorization header. Please provide X-API-Key or Authorization: Bearer <key>',
            ], 401);
        }

        // Verify API key
        $validApiKey = env('API_KEY_OPENLUXE');

        if (!$validApiKey) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'API key not configured',
                'error' => 'Server configuration error',
            ], 500);
        }

        if (!hash_equals($validApiKey, $apiKey)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Invalid API key',
                'error' => 'The provided API key is not valid',
            ], 403);
        }

        // Verify origin domain (additional security layer)
        if (!$this->isOriginAllowed($request)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Origin not allowed',
                'error' => 'Requests from this domain are not permitted',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Create a JSON response (Lumen-compatible)
     *
     * @param array $data
     * @param int $status
     * @return Response
     */
    private function jsonResponse(array $data, int $status = 200): Response
    {
        return new Response(json_encode($data), $status, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Get API key from request headers
     *
     * @param Request $request
     * @return string|null
     */
    private function getApiKey(Request $request): ?string
    {
        // Check X-API-Key header first
        $apiKey = $request->header('X-API-Key');

        if ($apiKey) {
            return $apiKey;
        }

        // Check Authorization header with Bearer token
        $authorization = $request->header('Authorization');

        if ($authorization && preg_match('/Bearer\s+(.*)$/i', $authorization, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if the request origin is from an allowed domain
     *
     * @param Request $request
     * @return bool
     */
    private function isOriginAllowed(Request $request): bool
    {
        // In local development, allow if no origin is set
        if (env('APP_ENV') === 'local' && !$request->header('Origin') && !$request->header('Referer')) {
            return true;
        }

        // Check Origin header (for CORS requests)
        $origin = $request->header('Origin');
        if ($origin && $this->isDomainAllowed($origin)) {
            return true;
        }

        // Check Referer header (for regular requests)
        $referer = $request->header('Referer');
        if ($referer && $this->isDomainAllowed($referer)) {
            return true;
        }

        // Check Host header (for server-to-server requests)
        $host = $request->header('Host');
        if ($host && in_array($host, $this->allowedDomains)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a URL contains an allowed domain
     *
     * @param string $url
     * @return bool
     */
    private function isDomainAllowed(string $url): bool
    {
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? '';

        foreach ($this->allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }
}
