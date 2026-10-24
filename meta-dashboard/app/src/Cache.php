<?php
declare(strict_types=1);

namespace App;

/**
 * Caché en base de datos. Si Meta falla (límite de llamadas, caída),
 * devuelve el último dato guardado aunque esté vencido ("stale").
 */
final class Cache
{
    /** Metadatos de la última lectura, para mostrar "actualizado hace X". */
    public static array $meta = ['cached_at' => null, 'stale' => false];

    public static function remember(string $key, int $ttl, callable $fn, bool $force = false): mixed
    {
        $hash = hash('sha256', $key);
        $row = Db::one('SELECT payload, created_at, expires_at FROM api_cache WHERE cache_key = ?', [$hash]);
        if (!$force && $row && (int) $row['expires_at'] > time()) {
            self::touchMeta((int) $row['created_at'], false);
            return json_decode($row['payload'], true);
        }
        try {
            $value = $fn();
        } catch (\Throwable $e) {
            if ($row) {
                self::touchMeta((int) $row['created_at'], true);
                return json_decode($row['payload'], true);
            }
            throw $e;
        }
        Db::upsert('api_cache', [
            'cache_key'  => $hash,
            'payload'    => json_encode($value, JSON_UNESCAPED_UNICODE),
            'created_at' => time(),
            'expires_at' => time() + $ttl,
        ], ['cache_key']);
        self::touchMeta(time(), false);
        return $value;
    }

    private static function touchMeta(int $createdAt, bool $stale): void
    {
        // Guardamos el dato más viejo usado en esta request.
        if (self::$meta['cached_at'] === null || $createdAt < self::$meta['cached_at']) {
            self::$meta['cached_at'] = $createdAt;
        }
        self::$meta['stale'] = self::$meta['stale'] || $stale;
    }

    public static function purgeExpired(int $graceSeconds = 86400 * 7): int
    {
        return Db::exec('DELETE FROM api_cache WHERE expires_at < ?', [time() - $graceSeconds]);
    }

    public static function flush(): void
    {
        Db::exec('DELETE FROM api_cache');
    }
}
