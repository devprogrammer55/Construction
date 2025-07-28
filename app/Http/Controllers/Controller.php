<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

abstract class Controller
{
    /**
     * Send encrypted JSON response
     *
     * @param mixed $data
     * @param string $message
     * @param int $code
     * @param array $additionalData
     * @return \Illuminate\Http\JsonResponse
     */
    protected function toJsonEnc($data = null, string $message = 'Success', int $code = 200, array $additionalData = [])
    {
        $response = array_merge([
            'success' => $code >= 200 && $code < 300,
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => now()->toISOString()
        ], $additionalData);

        try {
            // Check if encryption is enabled
            if (config('app.encryption_enabled', true)) {
                $encryptedResponse = Crypt::encryptString(json_encode($response));
                return response()->json(['encrypted' => $encryptedResponse], $code);
            }

            return response()->json($response, $code);

        } catch (\Exception $e) {
            Log::error('Failed to encrypt response', [
                'error' => $e->getMessage(),
                'response_data' => $response
            ]);

            // Return unencrypted response if encryption fails
            return response()->json($response, $code);
        }
    }

    /**
     * Send validation error response
     *
     * @param \Illuminate\Support\MessageBag|array $errors
     * @param string $message
     * @return \Illuminate\Http\JsonResponse
     */
    protected function validateResponse($errors, string $message = 'Validation failed')
    {
        $formattedErrors = [];

        if (is_array($errors)) {
            foreach ($errors as $field => $messages) {
                $formattedErrors[$field] = is_array($messages) ? $messages : [$messages];
            }
        } else {
            // Handle MessageBag from validator
            foreach ($errors->toArray() as $field => $messages) {
                $formattedErrors[$field] = $messages;
            }
        }

        $response = [
            'success' => false,
            'code' => 422,
            'message' => $message,
            'errors' => $formattedErrors,
            'timestamp' => now()->toISOString()
        ];

        return response()->json($response, 422);
    }

    /**
     * Send error response
     *
     * @param string $message
     * @param int $code
     * @param string|null $errorCode
     * @param mixed $data
     * @return \Illuminate\Http\JsonResponse
     */
    protected function errorResponse(string $message, int $code = 500, ?string $errorCode = null, $data = null)
    {
        $response = [
            'success' => false,
            'code' => $code,
            'message' => $message,
            'timestamp' => now()->toISOString()
        ];

        if ($errorCode) {
            $response['error_code'] = $errorCode;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $code);
    }

    /**
     * Send success response
     *
     * @param string $message
     * @param mixed $data
     * @param int $code
     * @param array $additionalData
     * @return \Illuminate\Http\JsonResponse
     */
    protected function successResponse(string $message = 'Success', $data = null, int $code = 200, array $additionalData = [])
    {
        return $this->toJsonEnc($data, $message, $code, $additionalData);
    }

    /**
     * Validate request data
     *
     * @param array $data
     * @param array $rules
     * @param array $messages
     * @param array $customAttributes
     * @return \Illuminate\Validation\Validator
     */
    protected function validateData(array $data, array $rules, array $messages = [], array $customAttributes = [])
    {
        return Validator::make($data, $rules, $messages, $customAttributes);
    }

    /**
     * Handle validation and return formatted errors if any
     *
     * @param array $data
     * @param array $rules
     * @param array $messages
     * @param array $customAttributes
     * @return bool|array
     */
    protected function handleValidation(array $data, array $rules, array $messages = [], array $customAttributes = [])
    {
        $validator = $this->validateData($data, $rules, $messages, $customAttributes);

        if ($validator->fails()) {
            return $validator->errors();
        }

        return true;
    }
}
