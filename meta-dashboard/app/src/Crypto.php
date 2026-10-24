<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/** Cifrado AES-256-GCM para guardar tokens de Meta en la base. */
final class Crypto
{
    private static function key(): string
    {
        $key = base64_decode((string) Config::get('app_key', ''), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('app_key inválida en config.php (debe ser base64 de 32 bytes).');
        }
        return $key;
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }
}
