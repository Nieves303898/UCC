<?php
declare(strict_types=1);

namespace App\Services;

use App\Crypto;
use App\Db;
use App\Meta\GraphClient;
use App\Meta\OAuth;
use RuntimeException;

/** Conexiones a Meta: cada una es un token (de usuario o de System User). */
final class Connections
{
    public static function all(): array
    {
        $rows = Db::all('SELECT id, label, meta_user_id, meta_user_name, token_source, expires_at, scopes,
                                last_sync_at, last_error, created_at FROM meta_connections ORDER BY id');
        foreach ($rows as &$row) {
            $row['scopes'] = $row['scopes'] ? explode(',', $row['scopes']) : [];
            $row['days_left'] = $row['expires_at'] ? (int) floor((strtotime($row['expires_at']) - time()) / 86400) : null;
            $row['assets'] = Db::all('SELECT type, COUNT(*) AS total FROM assets WHERE connection_id = ? GROUP BY type', [$row['id']]);
        }
        return $rows;
    }

    public static function token(int $id): string
    {
        $row = Db::one('SELECT access_token_enc FROM meta_connections WHERE id = ?', [$id]);
        $token = $row ? \App\Crypto::decrypt($row['access_token_enc']) : null;
        if (!$token) {
            throw new RuntimeException('La conexión con Meta no existe o su token no se puede leer. Volvé a conectarla.');
        }
        return $token;
    }

    /**
     * Guarda (o actualiza si ya existe ese usuario de Meta) una conexión.
     * $source: oauth | system_user | manual
     */
    public static function save(string $token, string $source, ?string $label = null, ?int $expiresIn = null): int
    {
        $me = (new GraphClient($token))->get('me', ['fields' => 'id,name']);

        $expiresAt = null;
        $scopes = [];
        try {
            $info = OAuth::debugToken($token);
            if (!empty($info['expires_at'])) {
                $expiresAt = date('Y-m-d H:i:s', (int) $info['expires_at']);
            }
            $scopes = $info['scopes'] ?? [];
        } catch (\Throwable) {
            if ($expiresIn) {
                $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
            }
        }

        $data = [
            'label'            => $label ?: ($me['name'] ?? 'Conexión Meta'),
            'meta_user_id'     => $me['id'],
            'meta_user_name'   => $me['name'] ?? null,
            'access_token_enc' => Crypto::encrypt($token),
            'token_source'     => $source,
            'expires_at'       => $expiresAt,
            'scopes'           => implode(',', $scopes),
            'last_error'       => null,
            'updated_at'       => Db::now(),
        ];

        $existing = Db::one('SELECT id FROM meta_connections WHERE meta_user_id = ?', [$me['id']]);
        if ($existing) {
            $sets = implode(',', array_map(fn ($c) => "$c = ?", array_keys($data)));
            Db::exec("UPDATE meta_connections SET $sets WHERE id = ?", [...array_values($data), $existing['id']]);
            return (int) $existing['id'];
        }
        $data['created_at'] = Db::now();
        return Db::insert('meta_connections', $data);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM assets WHERE connection_id = ?', [$id]);
        Db::exec('DELETE FROM meta_connections WHERE id = ?', [$id]);
    }

    public static function markError(int $id, ?string $error): void
    {
        Db::exec('UPDATE meta_connections SET last_error = ?, updated_at = ? WHERE id = ?', [$error, Db::now(), $id]);
    }
}
