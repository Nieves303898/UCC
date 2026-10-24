<?php
declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Db;

/** Webhooks de Meta: comentarios, menciones, cambios en el feed, etc. */
final class Webhooks
{
    public static function verifySignature(string $raw, ?string $header): bool
    {
        $secret = (string) Config::get('meta.app_secret', '');
        if ($secret === '' || !$header || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $header);
    }

    public static function store(array $payload): int
    {
        $n = 0;
        $object = (string) ($payload['object'] ?? 'unknown');
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [['field' => 'messaging', 'value' => $entry['messaging'] ?? $entry]] as $change) {
                Db::insert('webhook_events', [
                    'object'      => $object,
                    'object_id'   => (string) ($entry['id'] ?? ''),
                    'field'       => (string) ($change['field'] ?? ''),
                    'payload'     => json_encode($change['value'] ?? $change, JSON_UNESCAPED_UNICODE),
                    'received_at' => Db::now(),
                ]);
                $n++;
            }
        }
        return $n;
    }

    /** $only: IDs de páginas/Instagram permitidos (null = todos). */
    public static function recent(int $limit = 50, ?array $only = null): array
    {
        if ($only !== null && !$only) {
            return [];
        }
        $where = $only !== null ? ' WHERE object_id IN (' . implode(',', array_fill(0, count($only), '?')) . ')' : '';
        $names = [];
        foreach (Db::all("SELECT external_id, name FROM assets WHERE type IN ('page','ig_account')") as $a) {
            $names[$a['external_id']] = $a['name'];
        }
        return array_map(function ($r) use ($names) {
            $value = json_decode($r['payload'], true) ?: [];
            return [
                'id'          => (int) $r['id'],
                'object'      => $r['object'],
                'asset'       => $names[$r['object_id']] ?? $r['object_id'],
                'field'       => $r['field'],
                'text'        => $value['message'] ?? $value['text'] ?? $value['verb'] ?? null,
                'from'        => $value['from']['name'] ?? $value['from']['username'] ?? null,
                'item'        => $value['item'] ?? null,
                'received_at' => $r['received_at'],
            ];
        }, Db::all('SELECT * FROM webhook_events' . $where . ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)), $only ?? []));
    }
}
