<?php
declare(strict_types=1);

namespace App\Services;

use App\Crypto;
use App\Db;
use App\Meta\GraphClient;
use RuntimeException;

/** Activos sincronizados: portfolios comerciales, cuentas publicitarias, páginas e Instagram. */
final class Assets
{
    public static function list(string $type, bool $includeHidden = false): array
    {
        $sql = 'SELECT id, connection_id, type, external_id, name, parent_id, business_id, data_json, is_hidden, updated_at
                FROM assets WHERE type = ?' . ($includeHidden ? '' : ' AND is_hidden = 0') . ' ORDER BY name';
        return array_map([self::class, 'hydrate'], Db::all($sql, [$type]));
    }

    public static function find(string $type, string $externalId): array
    {
        $row = Db::one('SELECT * FROM assets WHERE type = ? AND external_id = ?', [$type, $externalId]);
        if (!$row) {
            throw new RuntimeException('No encontramos ese activo. Sincronizá la conexión con Meta.');
        }
        return $row;
    }

    public static function hydrate(array $row): array
    {
        $row['data'] = $row['data_json'] ? json_decode($row['data_json'], true) : [];
        unset($row['data_json'], $row['access_token_enc']);
        $row['is_hidden'] = (bool) $row['is_hidden'];
        return $row;
    }

    /** Cliente Graph con el token correcto para ese activo. */
    public static function client(string $type, string $externalId): GraphClient
    {
        $asset = self::find($type, $externalId);
        if ($type === 'ig_account' && $asset['parent_id']) {
            return self::client('page', $asset['parent_id']);
        }
        $token = Crypto::decrypt($asset['access_token_enc'] ?? null) ?: Connections::token((int) $asset['connection_id']);
        return new GraphClient($token);
    }

    public static function setHidden(int $id, bool $hidden): void
    {
        Db::exec('UPDATE assets SET is_hidden = ? WHERE id = ?', [$hidden ? 1 : 0, $id]);
    }

    public static function counts(): array
    {
        $out = ['business' => 0, 'ad_account' => 0, 'page' => 0, 'ig_account' => 0];
        foreach (Db::all('SELECT type, COUNT(*) AS n FROM assets WHERE is_hidden = 0 GROUP BY type') as $r) {
            $out[$r['type']] = (int) $r['n'];
        }
        return $out;
    }
}
