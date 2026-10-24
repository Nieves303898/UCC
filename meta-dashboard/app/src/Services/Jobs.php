<?php
declare(strict_types=1);

namespace App\Services;

use App\Cache;
use App\Db;
use App\Meta\GraphException;

/** Tareas programadas (cron). */
final class Jobs
{
    public static function run(): array
    {
        $out = [];
        $out['assets'] = self::step('sync_assets', fn () => AssetSync::syncAll());
        $out['snapshots'] = self::step('snapshots', fn () => self::snapshots());
        $out['tokens'] = self::step('token_check', fn () => self::tokenCheck());
        $out['cleanup'] = self::step('cleanup', function () {
            $n = Cache::purgeExpired();
            Db::exec('DELETE FROM webhook_events WHERE received_at < ?', [date('Y-m-d H:i:s', strtotime('-60 days'))]);
            Db::exec('DELETE FROM sync_log WHERE created_at < ?', [date('Y-m-d H:i:s', strtotime('-30 days'))]);
            return "caché purgada: $n";
        });
        return $out;
    }

    /** Guarda los seguidores del día de cada página e Instagram (Meta no da historial largo). */
    public static function snapshots(): array
    {
        $today = date('Y-m-d');
        $count = 0;
        $errors = [];
        foreach (['page', 'ig_account'] as $type) {
            foreach (Assets::list($type) as $a) {
                try {
                    $client = Assets::client($type, $a['external_id']);
                    $info = $client->get($a['external_id'], ['fields' => $type === 'page' ? 'followers_count,fan_count' : 'followers_count,media_count']);
                    Db::upsert('daily_snapshots', [
                        'asset_type'   => $type,
                        'external_id'  => $a['external_id'],
                        'snap_date'    => $today,
                        'metrics_json' => json_encode(['followers' => $info['followers_count'] ?? $info['fan_count'] ?? null] + $info),
                    ], ['asset_type', 'external_id', 'snap_date']);
                    $count++;
                } catch (GraphException $e) {
                    $errors[] = $a['name'] . ': ' . $e->getMessage();
                }
            }
        }
        return ['saved' => $count, 'errors' => $errors];
    }

    /** Intenta renovar tokens de usuario que vencen en menos de 10 días. */
    public static function tokenCheck(): array
    {
        $out = [];
        foreach (Db::all("SELECT id, label, expires_at, token_source FROM meta_connections WHERE expires_at IS NOT NULL") as $c) {
            $days = (strtotime($c['expires_at']) - time()) / 86400;
            if ($days > 10) {
                continue;
            }
            $out[] = $c['label'] . ': vence en ' . max(0, (int) $days) . ' días. Reconectá desde Configuración (o usá un token de System User que no vence).';
        }
        return $out;
    }

    private static function step(string $job, callable $fn): mixed
    {
        try {
            $result = $fn();
            Db::insert('sync_log', ['job' => $job, 'status' => 'ok', 'message' => mb_substr(json_encode($result, JSON_UNESCAPED_UNICODE), 0, 4000), 'created_at' => Db::now()]);
            return $result;
        } catch (\Throwable $e) {
            Db::insert('sync_log', ['job' => $job, 'status' => 'error', 'message' => $e->getMessage(), 'created_at' => Db::now()]);
            return ['error' => $e->getMessage()];
        }
    }
}
