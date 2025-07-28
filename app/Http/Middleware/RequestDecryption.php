<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class RequestDecryption
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
        // Skip decryption for public routes or when encryption is disabled
        if (!$this->shouldDecrypt($request)) {
            return $next($request);
        }

        try {
            // Get encrypted content from request
            $encryptedContent = $request->getContent();
            
            if (empty($encryptedContent)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Request body is empty',
                    'code' => 'EMPTY_REQUEST'
                ], 400);
            }

            // Decrypt the content
            $decryptedContent = Crypt::decryptString($encryptedContent);
            
            // Parse JSON content
            $decodedData = json_decode($decryptedContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Invalid JSON in decrypted request', [
                    'error' => json_last_error_msg(),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid JSON format in decrypted data',
                    'code' => 'INVALID_JSON'
                ], 400);
            }

            // Replace request data with decrypted data
            $request->merge($decodedData);
            
            // Store original encrypted data in request attributes for logging
            $request->attributes->set('encrypted_data', $encryptedContent);
            
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::warning('Failed to decrypt request', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to decrypt request data',
                'code' => 'DECRYPTION_FAILED'
            ], 400);
        } catch (\Exception $e) {
            Log::error('Unexpected error during request decryption', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Internal server error during decryption',
                'code' => 'DECRYPTION_ERROR'
            ], 500);
        }

        return $next($request);
    }

    /**
     * Determine if the request should be decrypted
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function shouldDecrypt(Request $request)
    {
        // Skip decryption for GET and HEAD requests
        if (in_array($request->method(), ['GET', 'HEAD'])) {
            return false;
        }

        // Skip decryption for specific routes (e.g., webhooks, public APIs)
        $skipRoutes = [
            'api/auth/login',
            'api/auth/signup',
            'api/auth/forgot-password',
            'api/auth/reset-password',
            'api/webhook',
        ];

        $currentPath = $request->path();
        
        foreach ($skipRoutes as $route) {
            if (str_contains($currentPath, $route)) {
                return false;
            }
        }

        // Check if encryption is enabled in config
        if (!config('app.encryption_enabled', true)) {
            return false;
        }

        // Check for encryption header
        $encryptionHeader = $request->header('X-Encryption');
        if ($encryptionHeader === 'none' || $encryptionHeader === 'disabled') {
            return false;
        }

        return true;
    }
}
