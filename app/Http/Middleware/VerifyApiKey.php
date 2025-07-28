<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\ApiKey;

class VerifyApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Skip API key verification for public routes
        if ($this->shouldSkipVerification($request)) {
            return $next($request);
        }

        $apiKey = $this->getApiKey($request);

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'API key is required',
                'code' => 'API_KEY_REQUIRED'
            ], 401);
        }

        try {
            $keyRecord = ApiKey::where('key', $apiKey)
                ->where('is_active', true)
                ->where('expires_at', '>', now())
                ->first();

            if (!$keyRecord) {
                Log::warning('Invalid API key attempt', [
                    'api_key' => substr($apiKey, 0, 8) . '...',
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'timestamp' => now()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid API key',
                    'code' => 'INVALID_API_KEY'
                ], 401);
            }

            // Check rate limits
            if ($this->isRateLimited($keyRecord, $request)) {
                Log::warning('API rate limit exceeded', [
                    'api_key_id' => $keyRecord->id,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'timestamp' => now()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'API rate limit exceeded',
                    'code' => 'RATE_LIMIT_EXCEEDED'
                ], 429);
            }

            // Log API usage
            $this->logApiUsage($keyRecord, $request);

            // Attach key record to request for use in controllers
            $request->attributes->set('api_key', $keyRecord);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('Error verifying API key', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error during API key verification',
                'code' => 'API_KEY_VERIFICATION_ERROR'
            ], 500);
        }
    }

    /**
     * Get API key from request
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function getApiKey(Request $request)
    {
        // Check header first
        $apiKey = $request->header('X-API-Key');
        if ($apiKey) {
            return $apiKey;
        }

        // Check query parameter
        $apiKey = $request->query('api_key');
        if ($apiKey) {
            return $apiKey;
        }

        // Check in request body
        $apiKey = $request->input('api_key');
        if ($apiKey) {
            return $apiKey;
        }

        // Check Bearer token
        $authorization = $request->header('Authorization');
        if ($authorization && str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }

        return null;
    }

    /**
     * Determine if API key verification should be skipped
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function shouldSkipVerification(Request $request)
    {
        // Skip for development/testing environments
        if (config('app.env') === 'local' && !config('app.force_api_key', false)) {
            return true;
        }

        // Skip for public routes
        $publicRoutes = [
            'api/auth/login',
            'api/auth/signup',
            'api/auth/forgot-password',
            'api/auth/reset-password',
            'api/auth/verify-email',
            'api/health',
            'api/status',
        ];

        $currentPath = $request->path();
        
        foreach ($publicRoutes as $route) {
            if (str_contains($currentPath, $route)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if API key is rate limited
     *
     * @param  \App\Models\ApiKey  $apiKey
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function isRateLimited(ApiKey $apiKey, Request $request)
    {
        $rateLimit = $apiKey->rate_limit_per_minute ?? 60;
        $window = 60; // 1 minute

        $cacheKey = "api_rate_limit:{$apiKey->id}:" . date('Y-m-d-H-i');
        
        $currentCount = cache()->increment($cacheKey);
        
        // Set expiration for the cache key
        if ($currentCount === 1) {
            cache()->expire($cacheKey, $window);
        }

        return $currentCount > $rateLimit;
    }

    /**
     * Log API usage
     *
     * @param  \App\Models\ApiKey  $apiKey
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    protected function logApiUsage(ApiKey $apiKey, Request $request)
    {
        try {
            // Increment usage counter
            $apiKey->increment('usage_count');
            
            // Log detailed usage
            Log::info('API usage logged', [
                'api_key_id' => $apiKey->id,
                'endpoint' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_agent' => substr($request->userAgent(), 0, 255),
                'timestamp' => now()
            ]);

        } catch (\Exception $e) {
            // Log error but don't fail the request
            Log::error('Failed to log API usage', [
                'error' => $e->getMessage(),
                'api_key_id' => $apiKey->id
            ]);
        }
    }
}
