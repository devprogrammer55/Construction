<?php

namespace App\Helpers;

class EncryptDecrypt
{
    public static function bodyEncrypt($string)
    {
        if (empty($string)) {
            return $string;
        }
        
        $encryptionMethod = 'AES-256-CBC';
        $secret = hash('sha256', config('constant.SECRET'));
        $iv = config('constant.IV');
        return openssl_encrypt($string, $encryptionMethod, $secret, 0, $iv);
    }

    public static function bodyDecrypt($string)
    {
        if (empty($string)) {
            return $string;
        }
        
        $encryptionMethod = 'AES-256-CBC';
        $secret = hash('sha256', config('constant.SECRET'));
        $iv = config('constant.IV');
        return openssl_decrypt($string, $encryptionMethod, $secret, 0, $iv);
    }

    public static function requestDecrypt($encryptedContent, $type = '')
    {
        if (!empty($type) && ($type == 'api-key' || $type == 'token')) {
            if (config('constant.ENCRYPTION_ENABLED') == 1) {
                return self::bodyDecrypt($encryptedContent);
            }
        }
        return $encryptedContent;
    }
}
