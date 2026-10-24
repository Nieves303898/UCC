<?php
declare(strict_types=1);

namespace App\Services;

use App\Cache;
use App\Config;
use App\DateRange;
use App\Meta\GraphClient;
use App\Meta\GraphException;
use InvalidArgumentException;

/** Cuentas publicitarias, campañas, conjuntos y anuncios con sus métricas. */
final class Ads
{
    private const LIVE_STATUSES = ['ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'IN_PROCESS', 'WITH_ISSUES',
        'PENDING_REVIEW', 'DISAPPROVED', 'PREAPPROVED', 'PENDING_BILLING_INFO'];

    public const ACCOUNT_STATUS = [
        1 => 'Activa', 2 => 'Deshabilitada', 3 => 'Pago pendiente', 7 => 'En revisión de riesgo',
        8 => 'Liquidación pendiente', 9 => 'En período de gracia', 100 => 'Cierre pendiente', 101 => 'Cerrada',
        201 => 'Activa (cualquiera)', 202 => 'Cerrada (cualquiera)',
    ];

    public const BREAKDOWNS = [
        'age_gender' => ['age', 'gender'],
        'age'        => ['age'],
        'gender'     => ['gender'],
        'platform'   => ['publisher_platform'],
        'placement'  => ['publisher_platform', 'platform_position'],
        'device'     => ['device_platform'],
        'region'     => ['region'],
        'country'    => ['country'],
        'hour'       => ['hourly_stats_aggregated_by_advertiser_time_zone'],
    ];

    /** Resumen de TODAS las cuentas visibles: KPIs, variación, serie diaria y alertas. */
    public static function overview(DateRange $range, bool $force = false, ?array $only = null, ?int $clientId = null): array
    {
        $accounts = Assets::list('ad_account', false, $only);
        $prev = $range->previous();

        $byConnection = [];
        foreach ($accounts as $a) {
            $byConnection[$a['connection_id']][] = $a;
        }

        $rows = [];
        $daily = [];
        foreach ($byConnection as $connectionId => $list) {
            $client = new GraphClient(Connections::token((int) $connectionId));
            $key = 'overview:' . $connectionId . ':' . $range->key() . ':' . md5(implode(',', array_column($list, 'external_id')));
            $results = Cache::remember($key, $range->ttl(), function () use ($client, $list, $range, $prev) {
                $requests = [];
                foreach ($list as $a) {
                    $id = $a['external_id'];
                    $requests["$id|cur"] = ['path' => "$id/insights", 'params' => ['fields' => AdsInsights::FIELDS, 'time_range' => $range->timeRange()]];
                    $requests["$id|prev"] = ['path' => "$id/insights", 'params' => ['fields' => AdsInsights::FIELDS, 'time_range' => $prev->timeRange()]];
                    $requests["$id|daily"] = ['path' => "$id/insights", 'params' => ['fields' => 'spend,impressions,clicks,inline_link_clicks,actions,action_values', 'time_range' => $range->timeRange(), 'time_increment' => 1, 'limit' => 500]];
                    $requests["$id|active"] = ['path' => "$id/campaigns", 'params' => ['effective_status' => ['ACTIVE'], 'summary' => 'total_count', 'limit' => 0]];
                }
                $out = [];
                foreach ($client->batch($requests) as $k => $res) {
                    $out[$k] = $res instanceof GraphException ? ['__error' => $res->getMessage()] : $res;
                }
                return $out;
            }, $force);

            foreach ($list as $a) {
                $id = $a['external_id'];
                $cur = $results["$id|cur"] ?? [];
                $error = $cur['__error'] ?? null;
                $metrics = AdsInsights::normalize($cur['data'][0] ?? []);
                $previous = AdsInsights::normalize(($results["$id|prev"]['data'] ?? [])[0] ?? []);
                $currency = $a['data']['currency'] ?? 'USD';
                $activeCount = (int) ($results["$id|active"]['summary']['total_count'] ?? 0);
                $dailyRows = array_map(fn ($d) => ['date' => $d['date_start']] + AdsInsights::normalize($d), $results["$id|daily"]['data'] ?? []);
                // Cuenta compartida entre marcas: sólo las campañas del cliente.
                if ($filter = Scope::campaignFilter($clientId, $id)) {
                    try {
                        $mine = self::campaigns($id, $range, $force, $filter);
                        $metrics = self::sliceTotals($mine);
                        $previous = self::sliceTotals(self::campaigns($id, $prev, $force, $filter));
                        $activeCount = count(array_filter($mine, fn ($c) => $c['effective_status'] === 'ACTIVE'));
                        $dailyRows = self::sliceDaily($id, $range, $filter, $force);
                    } catch (GraphException $e) {
                        [$metrics, $previous, $dailyRows, $error] = [AdsInsights::empty(), AdsInsights::empty(), [], $e->getMessage()];
                    }
                }
                foreach ($dailyRows as $n) {
                    $day = $n['date'];
                    $daily[$currency][$day] ??= ['spend' => 0, 'results' => 0, 'purchase_value' => 0, 'link_clicks' => 0, 'impressions' => 0];
                    foreach (['spend', 'results', 'purchase_value', 'link_clicks', 'impressions'] as $f) {
                        $daily[$currency][$day][$f] += $n[$f];
                    }
                }
                $rows[] = [
                    'id'               => $id,
                    'name'             => $filter && $clientId !== null && (\App\Auth::user()['role'] ?? '') === 'client' ? Clients::find($clientId)['name'] : $a['name'],
                    'currency'         => $currency,
                    'business'         => $a['data']['business']['name'] ?? null,
                    'status'           => (int) ($a['data']['account_status'] ?? 1),
                    'status_label'     => self::ACCOUNT_STATUS[(int) ($a['data']['account_status'] ?? 1)] ?? 'Desconocido',
                    'active_campaigns' => $activeCount,
                    'metrics'          => $metrics,
                    'previous'         => $previous,
                    'error'            => $error,
                ];
            }
        }

        usort($rows, fn ($a, $b) => $b['metrics']['spend'] <=> $a['metrics']['spend']);

        $totals = [];
        foreach ($rows as $r) {
            $totals[$r['currency']]['cur'][] = $r['metrics'];
            $totals[$r['currency']]['prev'][] = $r['previous'];
        }
        $totalsOut = [];
        foreach ($totals as $currency => $t) {
            $totalsOut[] = ['currency' => $currency, 'metrics' => AdsInsights::sum($t['cur']), 'previous' => AdsInsights::sum($t['prev'])];
        }
        usort($totalsOut, fn ($a, $b) => $b['metrics']['spend'] <=> $a['metrics']['spend']);

        foreach ($daily as &$series) {
            ksort($series);
        }

        return [
            'accounts' => $rows,
            'totals'   => $totalsOut,
            'daily'    => $daily,
            'alerts'   => self::accountAlerts($rows),
        ];
    }

    /** Detalle de una cuenta: KPIs, serie diaria y campañas. */
    public static function account(string $accountId, DateRange $range, bool $force = false, ?\Closure $filter = null): array
    {
        $asset = Assets::hydrate(Assets::find('ad_account', $accountId));
        $client = Assets::client('ad_account', $accountId);
        $prev = $range->previous();

        $data = Cache::remember("account:$accountId:" . $range->key(), $range->ttl(), function () use ($client, $accountId, $range, $prev) {
            $res = $client->batch([
                'cur'   => ['path' => "$accountId/insights", 'params' => ['fields' => AdsInsights::FIELDS, 'time_range' => $range->timeRange()]],
                'prev'  => ['path' => "$accountId/insights", 'params' => ['fields' => AdsInsights::FIELDS, 'time_range' => $prev->timeRange()]],
                'daily' => ['path' => "$accountId/insights", 'params' => ['fields' => AdsInsights::FIELDS, 'time_range' => $range->timeRange(), 'time_increment' => 1, 'limit' => 500]],
                'info'  => ['path' => $accountId, 'params' => ['fields' => 'name,currency,account_status,disable_reason,amount_spent,spend_cap,balance,timezone_name,business{id,name},funding_source_details']],
            ]);
            foreach ($res as $k => $v) {
                if ($v instanceof GraphException) {
                    if ($k === 'cur') {
                        throw $v;
                    }
                    $res[$k] = [];
                }
            }
            return $res;
        }, $force);

        $campaigns = self::campaigns($accountId, $range, $force, $filter);
        $daily = [];
        foreach ($data['daily']['data'] ?? [] as $d) {
            $daily[] = ['date' => $d['date_start']] + AdsInsights::normalize($d);
        }
        $metrics = array_merge(AdsInsights::normalize(($data['cur']['data'] ?? [])[0] ?? []), self::resultTotals($campaigns));
        $previous = AdsInsights::normalize(($data['prev']['data'] ?? [])[0] ?? []);
        if ($filter) {
            $metrics = self::sliceTotals($campaigns);
            $previous = self::sliceTotals(self::campaigns($accountId, $prev, $force, $filter));
            $daily = self::sliceDaily($accountId, $range, $filter, $force);
        }

        $info = $data['info'] ?: $asset['data'];
        return [
            'account'   => [
                'id'           => $accountId,
                'name'         => $info['name'] ?? $asset['name'],
                'currency'     => $info['currency'] ?? 'USD',
                'status'       => (int) ($info['account_status'] ?? 1),
                'status_label' => self::ACCOUNT_STATUS[(int) ($info['account_status'] ?? 1)] ?? '',
                'amount_spent' => isset($info['amount_spent']) ? $info['amount_spent'] / 100 : null,
                'spend_cap'    => !empty($info['spend_cap']) ? $info['spend_cap'] / 100 : null,
                'balance'      => isset($info['balance']) ? $info['balance'] / 100 : null,
                'timezone'     => $info['timezone_name'] ?? null,
                'business'     => $info['business']['name'] ?? null,
                'payment'      => $info['funding_source_details']['display_string'] ?? null,
            ],
            // Sumamos las campañas para que "resultados" respete el objetivo de cada una.
            'metrics'   => $metrics,
            'previous'  => $previous,
            'daily'     => $daily,
            'sliced'    => $filter !== null,
            'campaigns' => $campaigns,
            'alerts'    => self::campaignAlerts($campaigns),
        ];
    }

    public static function campaigns(string $accountId, DateRange $range, bool $force = false, ?\Closure $filter = null): array
    {
        $client = Assets::client('ad_account', $accountId);
        $fields = 'id,name,status,effective_status,objective,buying_type,bid_strategy,daily_budget,lifetime_budget,budget_remaining,'
            . 'start_time,stop_time,created_time,' . self::insightsExpansion($range);
        $rows = Cache::remember("campaigns:$accountId:" . $range->key(), $range->ttl(), fn () => $client->paginate("$accountId/campaigns", [
            'fields' => $fields,
            'effective_status' => self::LIVE_STATUSES,
            'limit' => 100,
        ], 1000), $force);

        if ($filter) {
            $rows = array_values(array_filter($rows, fn ($c) => $filter(['id' => $c['id'], 'name' => $c['name'] ?? ''])));
        }
        return array_map(fn ($c) => self::entity($c, $c['objective'] ?? null), $rows);
    }

    /** Totales de un grupo de campañas (marca dentro de una cuenta compartida). */
    public static function sliceTotals(array $campaigns): array
    {
        $t = AdsInsights::sum(array_map(fn ($c) => $c['metrics'], $campaigns));
        return $t + ['result_label' => 'Resultados', 'result_key' => 'results', 'frequency' => $t['frequency'] ?? 0];
    }

    /** Serie diaria sumando sólo las campañas que pasan el filtro. */
    public static function sliceDaily(string $accountId, DateRange $range, \Closure $filter, bool $force = false): array
    {
        $client = Assets::client('ad_account', $accountId);
        $rows = Cache::remember("cdaily:$accountId:" . $range->key(), $range->ttl(), fn () => $client->paginate("$accountId/insights", [
            'level' => 'campaign',
            'fields' => 'campaign_id,campaign_name,objective,' . AdsInsights::FIELDS,
            'time_range' => $range->timeRange(),
            'time_increment' => 1,
        ], 10000), $force);
        $byDay = [];
        foreach ($rows as $r) {
            if ($filter(['id' => $r['campaign_id'] ?? '', 'name' => $r['campaign_name'] ?? ''])) {
                $byDay[$r['date_start']][] = AdsInsights::normalize($r, $r['objective'] ?? null);
            }
        }
        ksort($byDay);
        $out = [];
        for ($d = $range->since; $d <= $range->until; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $out[] = ['date' => $k] + (isset($byDay[$k]) ? AdsInsights::sum($byDay[$k]) : AdsInsights::empty());
        }
        return $out;
    }

    public static function adsets(string $campaignId, string $accountId, DateRange $range, bool $force = false): array
    {
        $client = Assets::client('ad_account', $accountId);
        $fields = 'id,name,status,effective_status,optimization_goal,billing_event,bid_strategy,bid_amount,daily_budget,lifetime_budget,'
            . 'budget_remaining,start_time,end_time,campaign{objective},targeting{age_min,age_max,genders,geo_locations,publisher_platforms},'
            . self::insightsExpansion($range);
        $rows = Cache::remember("adsets:$campaignId:" . $range->key(), $range->ttl(), fn () => $client->paginate("$campaignId/adsets", [
            'fields' => $fields,
            'effective_status' => self::LIVE_STATUSES,
        ], 500), $force);

        return array_map(function ($s) {
            $e = self::entity($s, $s['campaign']['objective'] ?? null);
            $e['optimization_goal'] = $s['optimization_goal'] ?? null;
            $e['targeting'] = self::targetingSummary($s['targeting'] ?? []);
            return $e;
        }, $rows);
    }

    public static function ads(string $parentId, string $accountId, DateRange $range, bool $force = false): array
    {
        $client = Assets::client('ad_account', $accountId);
        $fields = 'id,name,status,effective_status,ad_review_feedback,preview_shareable_link,campaign{objective},'
            . 'creative{id,name,title,body,thumbnail_url,image_url,object_type,effective_object_story_id,instagram_permalink_url,call_to_action_type},'
            . self::insightsExpansion($range);
        $rows = Cache::remember("ads:$parentId:" . $range->key(), $range->ttl(), fn () => $client->paginate("$parentId/ads", [
            'fields' => $fields,
            'effective_status' => self::LIVE_STATUSES,
            'thumbnail_width' => 320,
            'thumbnail_height' => 320,
        ], 500), $force);

        return array_map(function ($ad) {
            $e = self::entity($ad, $ad['campaign']['objective'] ?? null);
            $cr = $ad['creative'] ?? [];
            $e['creative'] = [
                'id'        => $cr['id'] ?? null,
                'title'     => $cr['title'] ?? null,
                'body'      => $cr['body'] ?? null,
                'thumbnail' => $cr['thumbnail_url'] ?? $cr['image_url'] ?? null,
                'type'      => $cr['object_type'] ?? null,
                'cta'       => $cr['call_to_action_type'] ?? null,
                'post_id'   => $cr['effective_object_story_id'] ?? null,
                'ig_link'   => $cr['instagram_permalink_url'] ?? null,
            ];
            $e['preview_link'] = $ad['preview_shareable_link'] ?? null;
            $e['review_feedback'] = $ad['ad_review_feedback']['global'] ?? null;
            return $e;
        }, $rows);
    }

    /** Serie diaria de cualquier objeto (cuenta, campaña, conjunto o anuncio). */
    public static function timeseries(string $objectId, string $accountId, DateRange $range, ?string $objective, bool $force = false, ?\Closure $filter = null): array
    {
        if ($filter && $objectId === $accountId) {
            return self::sliceDaily($accountId, $range, $filter, $force);
        }
        $client = Assets::client('ad_account', $accountId);
        $rows = Cache::remember("ts:$objectId:" . $range->key(), $range->ttl(), fn () => $client->paginate("$objectId/insights", [
            'fields' => AdsInsights::FIELDS,
            'time_range' => $range->timeRange(),
            'time_increment' => 1,
        ], 400), $force);
        return array_map(fn ($d) => ['date' => $d['date_start']] + AdsInsights::normalize($d, $objective), $rows);
    }

    /** Desglose por edad, género, ubicación, plataforma, región, dispositivo u hora. */
    public static function breakdown(string $objectId, string $accountId, string $type, DateRange $range, ?string $objective, bool $force = false, ?\Closure $filter = null): array
    {
        if ($filter && $objectId === $accountId) {
            throw new InvalidArgumentException('En cuentas compartidas el desglose se ve dentro de cada campaña.');
        }
        if (!isset(self::BREAKDOWNS[$type])) {
            throw new InvalidArgumentException('Desglose no soportado');
        }
        $client = Assets::client('ad_account', $accountId);
        $dims = self::BREAKDOWNS[$type];
        $rows = Cache::remember("bd:$objectId:$type:" . $range->key(), $range->ttl(), fn () => $client->paginate("$objectId/insights", [
            'fields' => AdsInsights::FIELDS,
            'time_range' => $range->timeRange(),
            'breakdowns' => implode(',', $dims),
        ], 500), $force);

        $out = [];
        foreach ($rows as $r) {
            $label = implode(' · ', array_map(fn ($d) => self::dimLabel($d, (string) ($r[$d] ?? '')), $dims));
            $out[] = ['label' => $label] + AdsInsights::normalize($r, $objective);
        }
        usort($out, fn ($a, $b) => $b['spend'] <=> $a['spend']);
        return $out;
    }

    private static function insightsExpansion(DateRange $range): string
    {
        return 'insights.time_range(' . json_encode($range->timeRange()) . '){' . AdsInsights::FIELDS . '}';
    }

    private static function entity(array $obj, ?string $objective): array
    {
        $row = $obj['insights']['data'][0] ?? [];
        $budget = null;
        $budgetType = null;
        if (!empty($obj['daily_budget'])) {
            $budget = $obj['daily_budget'] / 100;
            $budgetType = 'diario';
        } elseif (!empty($obj['lifetime_budget'])) {
            $budget = $obj['lifetime_budget'] / 100;
            $budgetType = 'total';
        }
        return [
            'id'               => $obj['id'],
            'name'             => $obj['name'] ?? $obj['id'],
            'status'           => $obj['status'] ?? null,
            'effective_status' => $obj['effective_status'] ?? null,
            'objective'        => $objective,
            'budget'           => $budget,
            'budget_type'      => $budgetType,
            'budget_remaining' => isset($obj['budget_remaining']) && $obj['budget_remaining'] !== '0' ? $obj['budget_remaining'] / 100 : null,
            'bid_strategy'     => $obj['bid_strategy'] ?? null,
            'start_time'       => $obj['start_time'] ?? null,
            'stop_time'        => $obj['stop_time'] ?? $obj['end_time'] ?? null,
            'metrics'          => AdsInsights::normalize($row, $objective),
        ];
    }

    private static function resultTotals(array $entities): array
    {
        $results = array_sum(array_map(fn ($e) => $e['metrics']['results'], $entities));
        $spend = array_sum(array_map(fn ($e) => $e['metrics']['spend'], $entities));
        return ['results' => $results, 'cost_per_result' => $results > 0 ? $spend / $results : null, 'result_label' => 'Resultados'];
    }

    private static function targetingSummary(array $t): string
    {
        $parts = [];
        if (isset($t['age_min'])) {
            $parts[] = $t['age_min'] . '–' . ($t['age_max'] ?? '65+') . ' años';
        }
        if (!empty($t['genders'])) {
            $parts[] = in_array(1, $t['genders']) && !in_array(2, $t['genders']) ? 'Hombres' : (in_array(2, $t['genders']) && !in_array(1, $t['genders']) ? 'Mujeres' : 'Todos');
        }
        $geo = $t['geo_locations'] ?? [];
        $places = array_merge(
            $geo['countries'] ?? [],
            array_map(fn ($r) => $r['name'] ?? '', $geo['regions'] ?? []),
            array_map(fn ($c) => $c['name'] ?? '', $geo['cities'] ?? [])
        );
        if ($places) {
            $parts[] = implode(', ', array_slice(array_filter($places), 0, 4)) . (count($places) > 4 ? '…' : '');
        }
        if (!empty($t['publisher_platforms'])) {
            $parts[] = implode('/', $t['publisher_platforms']);
        }
        return implode(' · ', $parts);
    }

    private static function dimLabel(string $dim, string $value): string
    {
        if ($dim === 'gender') {
            return ['male' => 'Hombres', 'female' => 'Mujeres', 'unknown' => 'Sin dato'][$value] ?? $value;
        }
        if ($dim === 'hourly_stats_aggregated_by_advertiser_time_zone') {
            return substr($value, 0, 5) . ' h';
        }
        return $value !== '' ? str_replace('_', ' ', $value) : 'Sin dato';
    }

    private static function accountAlerts(array $rows): array
    {
        $alerts = [];
        foreach ($rows as $r) {
            if (!in_array($r['status'], [1, 201], true)) {
                $alerts[] = ['level' => 'danger', 'text' => "Cuenta «{$r['name']}»: {$r['status_label']}", 'account' => $r['id']];
            }
            if ($r['error']) {
                $alerts[] = ['level' => 'warning', 'text' => "Cuenta «{$r['name']}»: {$r['error']}", 'account' => $r['id']];
            }
            $m = $r['metrics'];
            $p = $r['previous'];
            if ($m['spend'] > 0 && $p['cost_per_result'] && $m['cost_per_result'] && $m['cost_per_result'] > $p['cost_per_result'] * 1.4) {
                $alerts[] = ['level' => 'warning', 'text' => "Cuenta «{$r['name']}»: el costo por resultado subió " . round(($m['cost_per_result'] / $p['cost_per_result'] - 1) * 100) . '% vs. período anterior', 'account' => $r['id']];
            }
            if ($r['active_campaigns'] > 0 && $m['spend'] == 0 && $m['impressions'] == 0) {
                $alerts[] = ['level' => 'warning', 'text' => "Cuenta «{$r['name']}»: tiene campañas activas pero sin entrega en el período", 'account' => $r['id']];
            }
        }
        return $alerts;
    }

    private static function campaignAlerts(array $campaigns): array
    {
        $alerts = [];
        $freqLimit = (float) Config::get('alerts.max_frequency', 3.5);
        foreach ($campaigns as $c) {
            $m = $c['metrics'];
            if (in_array($c['effective_status'], ['WITH_ISSUES', 'DISAPPROVED', 'PENDING_BILLING_INFO'], true)) {
                $alerts[] = ['level' => 'danger', 'text' => "«{$c['name']}»: estado {$c['effective_status']}"];
            }
            if ($m['frequency'] > $freqLimit) {
                $alerts[] = ['level' => 'warning', 'text' => "«{$c['name']}»: frecuencia " . round($m['frequency'], 1) . ' (posible saturación de audiencia)'];
            }
            if ($m['impressions'] > 2000 && $m['link_ctr'] !== null && $m['link_ctr'] < 0.5) {
                $alerts[] = ['level' => 'info', 'text' => "«{$c['name']}»: CTR de enlace bajo (" . round($m['link_ctr'], 2) . '%). Revisá creativos/hook'];
            }
            if ($m['spend'] > 0 && $m['results'] === 0 && $m['impressions'] > 3000) {
                $alerts[] = ['level' => 'warning', 'text' => "«{$c['name']}»: gastó sin generar {$m['result_label']}"];
            }
        }
        return $alerts;
    }
}
