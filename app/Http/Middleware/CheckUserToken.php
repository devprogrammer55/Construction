<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\UserToken;

class CheckUserToken
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
        // Skip token verification for public routes
        if ($this->shouldSkipVerification($request)) {
            return $next($request);
        }

        $token = $this->getToken($request);

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication token is required',
                'code' => 'TOKEN_REQUIRED'
            ], 401);
        }

        try {
            // Find valid token
            $userToken = UserToken::where('token', $token)
                ->where('is_active', true)
                ->where('expires_at', '>', now())
                ->first();

            if (!$userToken) {
                Log::warning('Invalid token attempt', [
                    'token' => substr($token, 0, 10) . '...',
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'timestamp' => now()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired token',
                    'code' => 'INVALID_TOKEN'
                ], 401);
            }

            // Get user associated with token
            $user = User::find($userToken->user_id);

            if (!$user) {
                Log::warning('Token associated with non-existent user', [
                    'token_id' => $userToken->id,
                    'user_id' => $userToken->user_id
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ], 401);
            }

            // Check if user is active
            if ($user->status !== 'active') {
                Log::warning('Inactive user attempting to access API', [
                    'user_id' => $user->id,
                    'status' => $user->status
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'User account is inactive',
                    'code' => 'USER_INACTIVE'
                ], 401);
            }

            // Update last used timestamp
            $userToken->update([
                'last_used_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent(), 0, 255)
            ]);

            // Attach user and token to request for use in controllers
            $request->attributes->set('user', $user);
            $request->attributes->set('user_id', $user->id);
            $request->attributes->set('user_token', $userToken);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('Error verifying user token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error during authentication',
                'code' => 'AUTHENTICATION_ERROR'
            ], 500);
        }
    }

    /**
     * Get token from request
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function getToken(Request $request)
    {
        // Check Authorization header first
        $authorization = $request->header('Authorization');
        if ($authorization && str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }

        // Check custom token header
        $token = $request->header('X-Auth-Token');
        if ($token) {
            return $token;
        }

        // Check query parameter
        $token = $request->query('token');
        if ($token) {
            return $token;
        }

        // Check in request body
        $token = $request->input('token');
        if ($token) {
            return $token;
        }

        return null;
    }

    /**
     * Determine if token verification should be skipped
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function shouldSkipVerification(Request $request)
    {
        // Skip for development/testing environments
        if (config('app.env') === 'local' && !config('app.force_token_auth', false)) {
            return true;
        }

        // Skip for public routes
        $publicRoutes = [
            'api/auth/login',
            'api/auth/signup',
            'api/auth/forgot-password',
            'api/auth/reset-password',
            'api/auth/verify-email',
            'api/auth/resend-verification',
            'api/health',
            'api/status',
            'api/webhook',
        ];

        $currentPath = $request->path();
        
        foreach ($publicRoutes as $route) {
            if (str_contains($currentPath, $route)) {
                return true;
            }
        }

        return false;
    }
}
