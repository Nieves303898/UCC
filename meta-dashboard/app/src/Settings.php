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
        self::get($key); // carga el resto antes de cachear (si no, quedaría una caché parcial)
        self::$cache[$key] = $value;
    }

    /** Nombre de la plataforma (marca blanca): "Paylab" salvo que se cambie en Configuración. */
    public static function platformName(): string
    {
        $n = trim((string) self::get('platform_name', ''));
        return $n !== '' ? $n : 'Paylab';
    }

    /** Logo propio subido en Configuración (o null para usar el de Paylab). */
    public static function platformLogo(): ?string
    {
        return self::get('platform_logo') ?: null;
    }

    /** Impuestos sobre el gasto en Meta (Argentina: ~35%). Devuelve 0.35. */
    public static function taxRate(): float
    {
        return (float) self::get('tax_rate', (string) Config::get('tax_rate', 35)) / 100;
    }
}
