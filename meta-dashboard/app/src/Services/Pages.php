<?php
declare(strict_types=1);

namespace App\Services;

use App\Cache;
use App\Config;
use App\DateRange;
use App\Db;
use App\Meta\GraphException;

/** Páginas de Facebook: métricas de la página y de cada posteo. */
final class Pages
{
    // Se piden todas; Metrics::valid descarta las que Meta haya deprecado.
    private const PAGE_METRICS = [
        'page_media_view', 'page_total_media_view_unique', 'page_impressions', 'page_impressions_unique',
        'page_post_engagements', 'page_daily_follows_unique', 'page_daily_unfollows_unique',
        'page_views_total', 'page_video_views', 'page_actions_post_reactions_total',
    ];

    private const POST_METRICS = [
        'post_media_view', 'post_total_media_view_unique', 'post_impressions', 'post_impressions_unique',
        'post_clicks', 'post_video_views',
    ];

    public const LABELS = [
        'page_media_view' => 'Visualizaciones', 'page_total_media_view_unique' => 'Espectadores',
        'page_impressions' => 'Impresiones', 'page_impressions_unique' => 'Alcance',
        'page_post_engagements' => 'Interacciones', 'page_daily_follows_unique' => 'Nuevos seguidores',
        'page_daily_unfollows_unique' => 'Dejaron de seguir', 'page_views_total' => 'Visitas a la página',
        'page_video_views' => 'Reproducciones de video', 'page_actions_post_reactions_total' => 'Reacciones',
        'post_media_view' => 'Visualizaciones', 'post_total_media_view_unique' => 'Espectadores',
        'post_impressions' => 'Impresiones', 'post_impressions_unique' => 'Alcance',
        'post_clicks' => 'Clics', 'post_video_views' => 'Reproducciones',
    ];

    public static function summary(string $pageId, DateRange $range, bool $force = false): array
    {
        $client = Assets::client('page', $pageId);
        // La API de páginas acepta hasta 93 días por consulta.
        $since = max($range->since->getTimestamp(), $range->until->modify('-92 days')->getTimestamp());
        $params = [
            'period' => 'day',
            'since'  => date('Y-m-d', $since),
            'until'  => $range->until->modify('+1 day')->format('Y-m-d'),
        ];

        return Cache::remember("page:$pageId:" . $range->key(), $range->ttl(), function () use ($client, $pageId, $params) {
            $info = $client->get($pageId, ['fields' => 'id,name,fan_count,followers_count,link,category,picture{url},about']);
            $metrics = Metrics::valid($client, 'page_day', "$pageId/insights", self::PAGE_METRICS, ['period' => 'day', 'date_preset' => 'yesterday']);
            $series = [];
            if ($metrics) {
                $res = $client->get("$pageId/insights", $params + ['metric' => implode(',', $metrics)]);
                $series = Metrics::series($res);
            }
            $totals = [];
            foreach ($series as $metric => $values) {
                ksort($series[$metric]);
                $totals[$metric] = array_sum($values);
            }
            return [
                'page' => [
                    'id'        => $info['id'],
                    'name'      => $info['name'],
                    'followers' => $info['followers_count'] ?? null,
                    'fans'      => $info['fan_count'] ?? null,
                    'link'      => $info['link'] ?? null,
                    'category'  => $info['category'] ?? null,
                    'picture'   => $info['picture']['data']['url'] ?? null,
                ],
                'totals' => $totals,
                'series' => $series,
                'labels' => array_intersect_key(self::LABELS, $series),
            ];
        }, $force) + ['followers_history' => self::history('page', $pageId)];
    }

    public static function posts(string $pageId, DateRange $range, bool $force = false): array
    {
        $client = Assets::client('page', $pageId);
        $ttl = (int) Config::get('cache.structure_ttl', 1800);
        if ($range->includesToday()) {
            $ttl = min($ttl, (int) Config::get('cache.realtime_ttl', 120) * 3);
        }

        return Cache::remember("page_posts:$pageId:" . $range->key(), $ttl, function () use ($client, $pageId, $range) {
            $posts = $client->paginate("$pageId/posts", [
                'fields' => 'id,message,story,created_time,permalink_url,full_picture,status_type,shares,'
                    . 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)',
                'since'  => $range->since->format('Y-m-d'),
                'until'  => $range->until->modify('+1 day')->format('Y-m-d'),
                'limit'  => 50,
            ], 150);

            if (!$posts) {
                return [];
            }

            try {
                $metrics = Metrics::valid($client, 'page_post', $posts[0]['id'] . '/insights', self::POST_METRICS, ['period' => 'lifetime']);
            } catch (GraphException) {
                $metrics = [];
            }
            $insights = [];
            if ($metrics) {
                $requests = [];
                foreach ($posts as $p) {
                    $requests[$p['id']] = ['path' => $p['id'] . '/insights', 'params' => ['metric' => implode(',', $metrics), 'period' => 'lifetime']];
                }
                foreach ($client->batch($requests) as $id => $res) {
                    $insights[$id] = $res instanceof GraphException ? [] : Metrics::totals($res);
                }
            }

            return array_map(function ($p) use ($insights) {
                $reactions = (int) ($p['reactions']['summary']['total_count'] ?? 0);
                $comments = (int) ($p['comments']['summary']['total_count'] ?? 0);
                $shares = (int) ($p['shares']['count'] ?? 0);
                $ins = $insights[$p['id']] ?? [];
                $reach = $ins['post_total_media_view_unique'] ?? $ins['post_impressions_unique'] ?? null;
                $views = $ins['post_media_view'] ?? $ins['post_impressions'] ?? null;
                $interactions = $reactions + $comments + $shares;
                return [
                    'id'           => $p['id'],
                    'message'      => $p['message'] ?? $p['story'] ?? '',
                    'created_time' => $p['created_time'],
                    'permalink'    => $p['permalink_url'] ?? null,
                    'picture'      => $p['full_picture'] ?? null,
                    'type'         => $p['status_type'] ?? null,
                    'reactions'    => $reactions,
                    'comments'     => $comments,
                    'shares'       => $shares,
                    'interactions' => $interactions,
                    'reach'        => $reach,
                    'views'        => $views,
                    'clicks'       => $ins['post_clicks'] ?? null,
                    'video_views'  => $ins['post_video_views'] ?? null,
                    'engagement_rate' => $reach ? round($interactions / $reach * 100, 2) : null,
                ];
            }, $posts);
        }, $force);
    }

    public static function history(string $type, string $id): array
    {
        $rows = Db::all('SELECT snap_date, metrics_json FROM daily_snapshots WHERE asset_type = ? AND external_id = ? ORDER BY snap_date DESC LIMIT 365', [$type, $id]);
        $out = [];
        foreach (array_reverse($rows) as $r) {
            $m = json_decode($r['metrics_json'], true);
            $out[] = ['date' => $r['snap_date'], 'followers' => $m['followers'] ?? null];
        }
        return $out;
    }
}
