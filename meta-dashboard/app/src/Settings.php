<?php
declare(strict_types=1);

namespace App;

/** Ajustes guardados en la base (editables desde Configuración). */
final class Settings
{
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Db::all('SELECT k, v FROM settings') as $r) {
                    self::$cache[$r['k']] = $r['v'];
                }
            } catch (\Throwable) {
                // Tabla todavía no creada.
            }
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        Db::upsert('settings', ['k' => $key, 'v' => $value], ['k']);
        self::$cache[$key] = $value;
    }

    /** Impuestos sobre el gasto en Meta (Argentina: ~35%). Devuelve 0.35. */
    public static function taxRate(): float
    {
        return (float) self::get('tax_rate', (string) Config::get('tax_rate', 35)) / 100;
    }
}
