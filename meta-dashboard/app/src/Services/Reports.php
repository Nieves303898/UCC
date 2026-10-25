<?php
declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\DateRange;
use App\Settings;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Reportes de agencia por marca (cliente), con el formato de presentación
 * que usa el equipo: avances (semanal / quincenal / mensual) y proyección
 * de inversión y ventas con impuestos.
 */
final class Reports
{
    private const MONTHS = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    // ---------------------------------------------------------------------
    // Avances del equipo (período semanal, quincenal o mensual)
    // ---------------------------------------------------------------------
    public static function progress(array $clientIds, string $period, ?string $until): array
    {
        $end = self::date($until) ?? new DateTimeImmutable('yesterday');
        $days = ['semanal' => 7, 'quincenal' => 15, 'mensual' => 30][$period] ?? 7;
        $range = new DateRange($end->modify('-' . ($days - 1) . ' days'), $end, 'custom');
        $prev = $range->previous();

        $brands = [];
        foreach (self::brands($clientIds) as $c) {
            $cid = (int) $c['id'];
            $allowed = Clients::allowed($cid);
            $ads = self::adsTotals($cid, $allowed['ad_account'], $range);
            $adsPrev = self::adsTotals($cid, $allowed['ad_account'], $prev);
            $sales = Sales::totals($cid, $range->since->format('Y-m-d'), $range->until->format('Y-m-d'));
            $salesPrev = Sales::totals($cid, $prev->since->format('Y-m-d'), $prev->until->format('Y-m-d'));

            // Presencia orgánica: Facebook + Instagram del período contra el anterior.
            $social = ['fb_views' => null, 'fb_views_prev' => null, 'fb_engagement' => null, 'fb_engagement_prev' => null, 'fb_video' => null,
                'ig_views' => null, 'ig_views_prev' => null, 'ig_reach' => null, 'ig_interactions' => null, 'ig_interactions_prev' => null];
            foreach ($allowed['page'] as $pid) {
                try {
                    $cur = Pages::summary($pid, $range)['totals'];
                    $old = Pages::summary($pid, $prev)['totals'];
                    $social['fb_views'] += $cur['page_media_view'] ?? $cur['page_impressions'] ?? 0;
                    $social['fb_views_prev'] += $old['page_media_view'] ?? $old['page_impressions'] ?? 0;
                    $social['fb_engagement'] += $cur['page_post_engagements'] ?? 0;
                    $social['fb_engagement_prev'] += $old['page_post_engagements'] ?? 0;
                    $social['fb_video'] += $cur['page_video_views'] ?? 0;
                } catch (\Throwable) {
                }
            }
            foreach ($allowed['ig_account'] as $ig) {
                try {
                    $cur = Instagram::summary($ig, $range)['totals'];
                    $old = Instagram::summary($ig, $prev)['totals'];
                    $social['ig_views'] += $cur['views'] ?? 0;
                    $social['ig_views_prev'] += $old['views'] ?? 0;
                    $social['ig_reach'] += $cur['reach'] ?? 0;
                    $social['ig_interactions'] += $cur['total_interactions'] ?? 0;
                    $social['ig_interactions_prev'] += $old['total_interactions'] ?? 0;
                } catch (\Throwable) {
                }
            }

            $brands[] = [
                'id' => $cid, 'name' => $c['name'], 'color' => $c['color'], 'sales_label' => $c['sales_label'],
                'has_ads' => (bool) $allowed['ad_account'], 'has_social' => $allowed['page'] || $allowed['ig_account'],
                'ads' => $ads, 'ads_prev' => $adsPrev, 'sales' => $sales, 'sales_prev' => $salesPrev,
                'cost_per_sale' => $sales['orders'] > 0 ? $ads['spend_tax'] / $sales['orders'] : null,
                'social' => $social,
                'done' => Db::all("SELECT title, kind, updated_at FROM client_updates WHERE client_id = ? AND status = 'hecho' AND updated_at BETWEEN ? AND ? ORDER BY updated_at DESC LIMIT 8",
                    [$cid, $range->since->format('Y-m-d 00:00:00'), $range->until->format('Y-m-d 23:59:59')]),
            ];
        }

        $key = 'progress:' . $period . ':' . $range->until->format('Y-m-d');
        return [
            'type' => 'progress', 'period' => $period, 'range' => $range->toArray(), 'previous' => $prev->toArray(),
            'label' => self::rangeLabel($range), 'brands' => $brands, 'tax_rate' => Settings::taxRate(),
            'key' => $key, 'notes' => self::notes($key),
        ];
    }

    // ---------------------------------------------------------------------
    // Proyección de inversión y ventas (mes en curso + mes siguiente)
    // ---------------------------------------------------------------------
    public static function projection(array $clientIds, ?string $asOf): array
    {
        $asOf = self::date($asOf) ?? new DateTimeImmutable('yesterday');
        $tax = Settings::taxRate();
        $monthStart = $asOf->modify('first day of this month');
        $daysInMonth = (int) $asOf->format('t');
        $remaining = $daysInMonth - (int) $asOf->format('j');
        $next = $monthStart->modify('+1 month');
        $daysNext = (int) $next->format('t');

        // Meses a comparar: los 3 anteriores + el actual (hasta la fecha de corte).
        $months = [];
        for ($i = 3; $i >= 1; $i--) {
            $m = $monthStart->modify("-$i month");
            $months[] = ['key' => $m->format('Y-m'), 'label' => ucfirst(self::MONTHS[(int) $m->format('n')]), 'range' => new DateRange($m, $m->modify('last day of this month'), 'custom'), 'partial' => false];
        }
        $months[] = ['key' => $monthStart->format('Y-m'), 'label' => ucfirst(self::MONTHS[(int) $monthStart->format('n')]) . ' (al ' . $asOf->format('j') . ')', 'range' => new DateRange($monthStart, $asOf, 'custom'), 'partial' => true];

        $budgets = [];
        try {
            foreach (Budgets::overview(null, false, $clientIds)['brands'] as $b) {
                $budgets[$b['client_id']] = $b;
            }
        } catch (\Throwable) {
        }

        $brands = [];
        foreach (self::brands($clientIds) as $c) {
            $cid = (int) $c['id'];
            $accounts = Clients::allowed($cid)['ad_account'];
            $hist = [];
            $sumSpend = 0.0;
            $sumOrders = 0.0;
            foreach ($months as $m) {
                $ads = self::adsTotals($cid, $accounts, $m['range']);
                $sales = Sales::totals($cid, $m['range']->since->format('Y-m-d'), $m['range']->until->format('Y-m-d'));
                $hist[] = ['key' => $m['key'], 'spend_tax' => $ads['spend_tax'], 'orders' => $sales['orders'], 'revenue' => $sales['revenue'], 'messages' => $ads['messages'],
                    'cost_per_sale' => $sales['orders'] > 0 ? $ads['spend_tax'] / $sales['orders'] : null];
                $sumSpend += $ads['spend_tax'];
                $sumOrders += $sales['orders'];
            }
            $cur = end($hist);
            $daily = (float) ($budgets[$cid]['active_daily_tax'] ?? 0); // presupuesto diario activo, con impuestos
            $costMonth = $cur['cost_per_sale'];
            $costAvg = $sumOrders > 0 ? $sumSpend / $sumOrders : null;
            $estRemaining = $daily * $remaining;
            $client = Clients::find($cid);
            $brands[] = [
                'id' => $cid, 'name' => $c['name'], 'color' => $c['color'], 'sales_label' => $c['sales_label'],
                'history' => $hist,
                'invested' => $cur['spend_tax'], 'estimated_rest' => $estRemaining, 'projected_month' => $cur['spend_tax'] + $estRemaining,
                'daily_tax' => $daily,
                'cost_month' => $costMonth, 'cost_avg' => $costAvg,
                'orders_to_date' => $cur['orders'],
                'orders_rest' => $costMonth ? $estRemaining / $costMonth : null,
                'next_week_spend' => $daily * 7,
                'next_week_sales_avg' => $costAvg ? $daily * 7 / $costAvg : null,
                'next_week_sales_month' => $costMonth ? $daily * 7 / $costMonth : null,
                'next_month_base' => $daily * $daysNext,
                'target_orders' => Planner::goal($cid, $next->format('Y-m')) ?? ($client['target_orders'] !== null ? (float) $client['target_orders'] : null),
            ];
        }

        $key = 'projection:' . $monthStart->format('Y-m');
        return [
            'type' => 'projection', 'as_of' => $asOf->format('Y-m-d'), 'tax_rate' => $tax,
            'month' => ['key' => $monthStart->format('Y-m'), 'name' => self::MONTHS[(int) $monthStart->format('n')], 'year' => (int) $monthStart->format('Y'), 'days' => $daysInMonth, 'remaining' => $remaining],
            'next' => ['key' => $next->format('Y-m'), 'name' => self::MONTHS[(int) $next->format('n')], 'year' => (int) $next->format('Y'), 'days' => $daysNext],
            'week' => ['from' => $asOf->modify('+1 day')->format('Y-m-d'), 'to' => $asOf->modify('+7 days')->format('Y-m-d')],
            'months' => array_map(fn ($m) => ['key' => $m['key'], 'label' => $m['label'], 'partial' => $m['partial']], $months),
            'brands' => $brands, 'key' => $key, 'notes' => self::notes($key),
        ];
    }

    public static function saveNote(string $key, ?string $value): void
    {
        if (!preg_match('/^[a-z0-9:_\-.]{3,190}$/i', $key)) {
            throw new InvalidArgumentException('Clave inválida.');
        }
        Db::upsert('report_notes', ['k' => $key, 'v' => $value !== null ? mb_substr($value, 0, 5000) : null, 'updated_at' => Db::now()], ['k']);
    }

    /** Totales de Meta de una marca (sólo sus campañas si comparte cuenta). */
    public static function adsTotals(int $clientId, array $accounts, DateRange $range): array
    {
        $empty = ['spend' => 0.0, 'spend_tax' => 0.0, 'messages' => 0, 'results' => 0, 'purchases' => 0, 'link_clicks' => 0, 'impressions' => 0, 'reach' => 0, 'active_campaigns' => 0];
        if (!$accounts) {
            return $empty;
        }
        try {
            $ov = Ads::overview($range, false, $accounts, $clientId);
        } catch (\Throwable) {
            return $empty;
        }
        $out = $empty;
        foreach ($ov['totals'] as $t) {
            foreach (['spend', 'spend_tax', 'messages', 'results', 'purchases', 'link_clicks', 'impressions', 'reach'] as $k) {
                $out[$k] += $t['metrics'][$k] ?? 0;
            }
        }
        $out['active_campaigns'] = array_sum(array_column($ov['accounts'], 'active_campaigns'));
        return $out;
    }

    private static function brands(array $clientIds): array
    {
        if (!$clientIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($clientIds), '?'));
        return Db::all("SELECT * FROM clients WHERE id IN ($ph) ORDER BY name", array_map('intval', $clientIds));
    }

    private static function notes(string $prefix): array
    {
        $out = [];
        foreach (Db::all('SELECT k, v FROM report_notes WHERE k LIKE ?', [$prefix . ':%']) as $r) {
            $out[substr($r['k'], strlen($prefix) + 1)] = $r['v'];
        }
        return $out;
    }

    private static function date(?string $s): ?DateTimeImmutable
    {
        if (!$s || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return null;
        }
        $d = new DateTimeImmutable($s);
        return $d > new DateTimeImmutable('today') ? new DateTimeImmutable('today') : $d;
    }

    private static function rangeLabel(DateRange $r): string
    {
        $a = $r->since;
        $b = $r->until;
        $m = fn ($d) => self::MONTHS[(int) $d->format('n')];
        return $a->format('Y-m') === $b->format('Y-m')
            ? 'del ' . $a->format('j') . ' al ' . $b->format('j') . ' de ' . $m($b) . ' de ' . $b->format('Y')
            : 'del ' . $a->format('j') . ' de ' . $m($a) . ' al ' . $b->format('j') . ' de ' . $m($b) . ' de ' . $b->format('Y');
    }
}
