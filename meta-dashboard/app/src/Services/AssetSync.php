<?php
declare(strict_types=1);

namespace App\Services;

use App\Crypto;
use App\Db;
use App\Meta\GraphClient;
use App\Meta\GraphException;

/** Descarga de Meta todo lo que el token puede ver y lo guarda en "assets". */
final class AssetSync
{
    public static function syncAll(): array
    {
        $report = [];
        foreach (Db::all('SELECT id, label FROM meta_connections') as $c) {
            try {
                $report[$c['label']] = self::sync((int) $c['id']);
            } catch (\Throwable $e) {
                $report[$c['label']] = ['error' => $e->getMessage()];
            }
        }
        return $report;
    }

    public static function sync(int $connectionId): array
    {
        $client = new GraphClient(Connections::token($connectionId));
        $startedAt = Db::now();
        $counts = ['business' => 0, 'ad_account' => 0, 'page' => 0, 'ig_account' => 0];
        $warnings = [];

        try {
            $client->get('me', ['fields' => 'id']);
        } catch (GraphException $e) {
            Connections::markError($connectionId, $e->getMessage());
            throw $e;
        }

        // Portfolios comerciales (Business Managers)
        try {
            foreach ($client->paginate('me/businesses', ['fields' => 'id,name,verification_status,profile_picture_uri'], 200) as $b) {
                self::store($connectionId, 'business', $b['id'], $b['name'], null, $b['id'], $b);
                $counts['business']++;
            }
        } catch (GraphException $e) {
            $warnings[] = 'Portfolios: ' . $e->getMessage();
        }

        // Cuentas publicitarias
        try {
            $fields = 'id,account_id,name,currency,account_status,disable_reason,timezone_name,amount_spent,spend_cap,balance,business{id,name}';
            foreach ($client->paginate('me/adaccounts', ['fields' => $fields], 2000) as $a) {
                self::store($connectionId, 'ad_account', $a['id'], $a['name'] ?: $a['id'], null, $a['business']['id'] ?? null, $a);
                $counts['ad_account']++;
            }
        } catch (GraphException $e) {
            $warnings[] = 'Cuentas publicitarias: ' . $e->getMessage();
        }

        // Páginas (con su token propio) e Instagram vinculados
        try {
            $fields = 'id,name,access_token,category,link,fan_count,followers_count,picture{url},'
                . 'instagram_business_account{id,username,name,profile_picture_url,followers_count,follows_count,media_count}';
            foreach ($client->paginate('me/accounts', ['fields' => $fields], 1000) as $p) {
                $pageToken = $p['access_token'] ?? null;
                $ig = $p['instagram_business_account'] ?? null;
                unset($p['access_token'], $p['instagram_business_account']);
                $p['picture'] = $p['picture']['data']['url'] ?? null;
                self::store($connectionId, 'page', $p['id'], $p['name'], null, null, $p, $pageToken);
                $counts['page']++;
                if ($ig) {
                    $ig['page_name'] = $p['name'];
                    self::store($connectionId, 'ig_account', $ig['id'], '@' . ($ig['username'] ?? $ig['id']), $p['id'], null, $ig);
                    $counts['ig_account']++;
                }
            }
        } catch (GraphException $e) {
            $warnings[] = 'Páginas: ' . $e->getMessage();
        }

        // Lo que ya no aparece en esta conexión se elimina.
        Db::exec('DELETE FROM assets WHERE connection_id = ? AND updated_at < ?', [$connectionId, $startedAt]);
        Db::exec('UPDATE meta_connections SET last_sync_at = ?, last_error = ? WHERE id = ?', [
            Db::now(), $warnings ? implode(' | ', $warnings) : null, $connectionId,
        ]);

        return ['counts' => $counts, 'warnings' => $warnings];
    }

    private static function store(int $connectionId, string $type, string $id, string $name, ?string $parentId, ?string $businessId, array $data, ?string $token = null): void
    {
        $existing = Db::one('SELECT is_hidden FROM assets WHERE type = ? AND external_id = ?', [$type, $id]);
        Db::upsert('assets', [
            'connection_id'    => $connectionId,
            'type'             => $type,
            'external_id'      => $id,
            'name'             => mb_substr($name, 0, 250),
            'parent_id'        => $parentId,
            'business_id'      => $businessId,
            'data_json'        => json_encode($data, JSON_UNESCAPED_UNICODE),
            'access_token_enc' => $token ? Crypto::encrypt($token) : null,
            'is_hidden'        => $existing ? (int) $existing['is_hidden'] : 0,
            'updated_at'       => Db::now(),
        ], ['type', 'external_id']);
    }
}
