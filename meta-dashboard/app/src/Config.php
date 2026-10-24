<?php
declare(strict_types=1);

namespace App;

final class Config
{
    private static array $data = [];

    public static function load(array $data): void
    {
        self::$data = $data;
    }

    /** Lee un valor con notación de puntos: Config::get('meta.app_id') */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function url(string $path = ''): string
    {
        return rtrim((string) self::get('app_url', ''), '/') . '/' . ltrim($path, '/');
    }
}
