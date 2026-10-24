<?php
declare(strict_types=1);

namespace App\Services;

use App\Cache;
use App\Config;
use App\DateRange;
use App\Meta\GraphClient;
use App\Meta\GraphException;

/** Cuentas de Instagram profesionales: métricas de cuenta, posteos, reels e historias. */
final class Instagram
{
    private const DAY_METRICS = ['reach', 'follower_count'];

    private const TOTAL_METRICS = ['views', 'reach', 'accounts_engaged', 'total_interactions', 'likes', 'comments',
        'shares', 'saves', 'replies', 'profile_links_taps', 'follows_and_unfollows', 'website_clicks', 'profile_views'];

    private const MEDIA_METRICS = [
        'FEED'  => ['views', 'reach', 'saved', 'shares', 'total_interactions', 'likes', 'comments', 'profile_visits', 'follows'],
        'REELS' => ['views', 'reach', 'saved', 'shares', 'total_interactions', 'likes', 'comments', 'ig_reels_avg_watch_time', 'ig_reels_video_view_total_time'],
        'STORY' => ['views', 'reach', 'replies', 'shares', 'total_interactions', 'navigation', 'profile_visits', 'follows'],
    ];

    public const LABELS = [
        'views' => 'Visualizaciones', 'reach' => 'Alcance', 'accounts_engaged' => 'Cuentas que interactuaron',
        'total_interactions' => 'Interacciones', 'likes' => 'Me gusta', 'comments' => 'Comentarios', 'shares' => 'Compartidos',
        'saves' => 'Guardados', 'saved' => 'Guardados', 'replies' => 'Respuestas', 'profile_links_taps' => 'Toques en enlaces',
        'follows_and_unfollows' => 'Seguidores netos', 'website_clicks' => 'Clics al sitio', 'profile_views' => 'Visitas al perfil',
        'follower_count' => 'Nuevos seguidores', 'navigation' => 'Navegación', 'profile_visits' => 'Visitas al perfil',
        'follows' => 'Seguimientos', 'ig_reels_avg_watch_time' => 'Tiempo medio (ms)', 'ig_reels_video_view_total_time' => 'Tiempo total (ms)',
    ];

    public static function summary(string $igId, DateRange $range, bool $force = false): array
    {
        $client = Assets::client('ig_account', $igId);

        $data = Cache::remember("ig:$igId:" . $range->key(), $range->ttl(), function () use ($client, $igId, $range) {
            $info = $client->get($igId, ['fields' => 'id,username,name,profile_picture_url,followers_count,follows_count,media_count,biography,website']);

            // Instagram limita las consultas diarias a 30 días: partimos el rango en ventanas.
            $dayMetrics = Metrics::valid($client, 'ig_day', "$igId/insights", self::DAY_METRICS, ['period' => 'day', 'since' => strtotime('-3 days'), 'until' => time()]);
            $series = [];
            foreach (self::windows($range, 30) as [$since, $until]) {
                if (!$dayMetrics) {
                    break;
                }
                // follower_count sólo existe para los últimos 30 días.
                $metrics = $since < strtotime('-29 days') ? array_diff($dayMetrics, ['follower_count']) : $dayMetrics;
                if (!$metrics) {
                    continue;
                }
                try {
                    $res = $client->get("$igId/insights", ['metric' => implode(',', $metrics), 'period' => 'day', 'since' => $since, 'until' => $until]);
                    $series = array_merge_recursive($series, Metrics::series($res));
                } catch (GraphException $e) {
                    if (!$e->isInvalidParam()) {
                        throw $e;
                    }
                }
            }
            foreach ($series as $k => $v) {
                $series[$k] = array_map(fn ($x) => is_array($x) ? array_sum($x) : $x, $v);
                ksort($series[$k]);
            }

            // Totales del período (últimos 30 días del rango como máximo).
            $tSince = max($range->since->getTimestamp(), $range->until->modify('-29 days')->getTimestamp());
            $tUntil = $range->until->modify('+1 day')->getTimestamp();
            $totalMetrics = Metrics::valid($client, 'ig_total', "$igId/insights", self::TOTAL_METRICS, ['period' => 'day', 'metric_type' => 'total_value', 'since' => strtotime('-3 days'), 'until' => time()]);
            $totals = [];
            if ($totalMetrics) {
                $res = $client->get("$igId/insights", ['metric' => implode(',', $totalMetrics), 'period' => 'day', 'metric_type' => 'total_value', 'since' => $tSince, 'until' => $tUntil]);
                $totals = Metrics::totals($res);
            }

            return [
                'account' => [
                    'id'         => $info['id'],
                    'username'   => $info['username'] ?? null,
                    'name'       => $info['name'] ?? null,
                    'picture'    => $info['profile_picture_url'] ?? null,
                    'followers'  => $info['followers_count'] ?? null,
                    'follows'    => $info['follows_count'] ?? null,
                    'media_count' => $info['media_count'] ?? null,
                    'biography'  => $info['biography'] ?? null,
                    'website'    => $info['website'] ?? null,
                ],
                'totals'        => $totals,
                'totals_capped' => $range->days() > 30,
                'series'        => $series,
                'labels'        => self::LABELS,
            ];
        }, $force);

        $data['followers_history'] = Pages::history('ig_account', $igId);
        return $data;
    }

    public static function media(string $igId, DateRange $range, bool $force = false): array
    {
        $client = Assets::client('ig_account', $igId);
        $ttl = $range->includesToday()
            ? (int) Config::get('cache.realtime_ttl', 120) * 3
            : (int) Config::get('cache.structure_ttl', 1800);

        return Cache::remember("ig_media:$igId:" . $range->key(), $ttl, function () use ($client, $igId, $range) {
            $media = $client->paginate("$igId/media", [
                'fields' => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count',
                'since'  => $range->since->getTimestamp(),
                'until'  => $range->until->modify('+1 day')->getTimestamp(),
                'limit'  => 50,
            ], 150);
            return self::withInsights($client, $media);
        }, $force);
    }

    public static function stories(string $igId, bool $force = false): array
    {
        $client = Assets::client('ig_account', $igId);
        return Cache::remember("ig_stories:$igId", (int) Config::get('cache.realtime_ttl', 120), function () use ($client, $igId) {
            $stories = $client->paginate("$igId/stories", [
                'fields' => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp',
            ], 100);
            return self::withInsights($client, $stories);
        }, $force);
    }

    /** Demografía de seguidores (requiere +100 seguidores). */
    public static function audience(string $igId, bool $force = false): array
    {
        $client = Assets::client('ig_account', $igId);
        return Cache::remember("ig_aud:$igId", 86400, function () use ($client, $igId) {
            $requests = [];
            foreach (['age', 'gender', 'city', 'country'] as $b) {
                $requests[$b] = ['path' => "$igId/insights", 'params' => [
                    'metric' => 'follower_demographics', 'period' => 'lifetime', 'metric_type' => 'total_value', 'breakdown' => $b,
                ]];
            }
            $out = [];
            foreach ($client->batch($requests) as $b => $res) {
                $rows = [];
                if (!$res instanceof GraphException) {
                    foreach ($res['data'][0]['total_value']['breakdowns'][0]['results'] ?? [] as $r) {
                        $label = implode(' ', $r['dimension_values'] ?? []);
                        if ($b === 'gender') {
                            $label = ['M' => 'Hombres', 'F' => 'Mujeres', 'U' => 'Sin dato'][$label] ?? $label;
                        }
                        $rows[] = ['label' => $label, 'value' => (int) $r['value']];
                    }
                }
                usort($rows, fn ($x, $y) => $y['value'] <=> $x['value']);
                $out[$b] = $b === 'age' ? self::sortAge($rows) : array_slice($rows, 0, 15);
            }
            return $out;
        }, $force);
    }

    private static function withInsights(GraphClient $client, array $media): array
    {
        if (!$media) {
            return [];
        }
        // Validamos las métricas una vez por tipo de contenido.
        // Si un posteo puntual no admite insights (ej. anterior a la cuenta profesional) probamos con el siguiente.
        $valid = [];
        foreach ($media as $m) {
            $kind = self::kind($m);
            if (!empty($valid[$kind])) {
                continue;
            }
            try {
                $valid[$kind] = Metrics::valid($client, 'ig_media_' . $kind, $m['id'] . '/insights', self::MEDIA_METRICS[$kind], []);
            } catch (GraphException) {
                $valid[$kind] = [];
            }
        }
        $requests = [];
        foreach ($media as $m) {
            $metrics = $valid[self::kind($m)] ?? [];
            if ($metrics) {
                $requests[$m['id']] = ['path' => $m['id'] . '/insights', 'params' => ['metric' => implode(',', $metrics)]];
            }
        }
        $insights = [];
        foreach ($client->batch($requests) as $id => $res) {
            $insights[$id] = $res instanceof GraphException ? [] : Metrics::totals($res);
        }

        return array_map(function ($m) use ($insights) {
            $ins = $insights[$m['id']] ?? [];
            $likes = (int) ($m['like_count'] ?? $ins['likes'] ?? 0);
            $comments = (int) ($m['comments_count'] ?? $ins['comments'] ?? 0);
            $reach = $ins['reach'] ?? null;
            $interactions = $ins['total_interactions'] ?? ($likes + $comments + ($ins['saved'] ?? 0) + ($ins['shares'] ?? 0));
            return [
                'id'           => $m['id'],
                'caption'      => $m['caption'] ?? '',
                'type'         => self::kind($m),
                'media_type'   => $m['media_type'] ?? null,
                'thumbnail'    => $m['thumbnail_url'] ?? (($m['media_type'] ?? '') === 'VIDEO' ? null : ($m['media_url'] ?? null)),
                'permalink'    => $m['permalink'] ?? null,
                'timestamp'    => $m['timestamp'] ?? null,
                'likes'        => $likes,
                'comments'     => $comments,
                'saved'        => $ins['saved'] ?? null,
                'shares'       => $ins['shares'] ?? null,
                'reach'        => $reach,
                'views'        => $ins['views'] ?? null,
                'replies'      => $ins['replies'] ?? null,
                'interactions' => $interactions,
                'avg_watch_ms' => $ins['ig_reels_avg_watch_time'] ?? null,
                'engagement_rate' => $reach ? round($interactions / $reach * 100, 2) : null,
            ];
        }, $media);
    }

    private static function kind(array $m): string
    {
        $t = $m['media_product_type'] ?? 'FEED';
        return in_array($t, ['REELS', 'STORY'], true) ? $t : 'FEED';
    }

    /** @return array<array{0:int,1:int}> ventanas [since, until] en timestamps */
    private static function windows(DateRange $range, int $days): array
    {
        $out = [];
        $start = $range->since;
        $end = $range->until->modify('+1 day');
        while ($start < $end) {
            $stop = min($start->modify("+$days days"), $end);
            $out[] = [$start->getTimestamp(), $stop->getTimestamp()];
            $start = $stop;
        }
        return $out;
    }

    private static function sortAge(array $rows): array
    {
        usort($rows, fn ($a, $b) => strcmp($a['label'], $b['label']));
        return $rows;
    }
}
