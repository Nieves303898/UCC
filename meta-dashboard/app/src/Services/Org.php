<?php
declare(strict_types=1);

namespace App\Services;

use App\DateRange;
use App\Db;
use App\Settings;

/**
 * Vista consolidada de un cliente con varias marcas (ej. PayLab Gestión):
 * inversión con impuestos, resultados de Meta, ventas/gestiones cargadas en las
 * planillas y avance del objetivo del mes, marca por marca.
 */
final class Org
{
    /**
     * Ventas generales del cliente: totales, por marca (ventas, inversión, costo por venta,
     * conversión, mes a mes) y presupuesto (promedio real, asignado este mes y el próximo).
     */
    public static function sales(int $orgId, array $brandIds, DateRange $range): array
    {
        $base = self::overview($orgId, $brandIds, $range);
        $ids = array_column($base['brands'], 'id');
        $today = new \DateTimeImmutable('today');
        $monthStart = $today->modify('first day of this month');
        $elapsed = max(1, (int) $today->format('j'));
        $nextKey = $monthStart->modify('+1 month')->format('Y-m');
        $planned = [];
        foreach (Db::all("SELECT account_id, month, amount FROM ad_budgets WHERE account_id LIKE 'client:%' AND month IN (?, ?)", [$monthStart->format('Y-m'), $nextKey]) as $p) {
            $planned[(int) substr($p['account_id'], 7)][$p['month']] = (float) $p['amount'];
        }
        $next = [];
        try {
            foreach (Planner::plan($ids, 'next')['brands'] as $b) {
                $next[$b['id']] = $b;
            }
        } catch (\Throwable) {
        }
        foreach ($base['brands'] as &$b) {
            $bid = $b['id'];
            $accounts = Clients::allowed($bid)['ad_account'];
            $mtd = $accounts ? Reports::adsTotals($bid, $accounts, new DateRange($monthStart, $today, 'custom')) : ['spend_tax' => 0.0];
            $months = Sales::monthly($bid, 4)['months'];
            $n = $next[$bid] ?? [];
            $b['budget'] = [
                'mtd_spend_tax' => $mtd['spend_tax'],
                'avg_daily_tax' => $mtd['spend_tax'] / $elapsed,
                'avg_month_tax' => $mtd['spend_tax'] / $elapsed * (int) $today->format('t'),
                'active_daily_tax' => $n['active_daily_tax'] ?? null,
                'planned_month' => $planned[$bid][$monthStart->format('Y-m')] ?? null,
                'planned_next' => $planned[$bid][$nextKey] ?? null,
                'suggested_next' => $n['required'] ?? null,
                'goal_next' => $n['goal_orders'] ?? null,
                'pace_next' => $n['pace_month'] ?? null,
            ];
            $b['months'] = array_map(fn ($m) => ['month' => $m['month'], 'orders' => $m['orders'], 'spend_tax' => $m['meta_spend_tax'], 'cost_per_sale' => $m['cost_per_sale']], $months);
        }
        unset($b);
        // Mes a mes de todo el cliente (suma de marcas).
        $byMonth = [];
        foreach ($base['brands'] as $b) {
            foreach ($b['months'] as $m) {
                $x = &$byMonth[$m['month']];
                $x ??= ['month' => $m['month'], 'orders' => 0.0, 'spend_tax' => 0.0];
                $x['orders'] += $m['orders'];
                $x['spend_tax'] += $m['spend_tax'] ?? 0;
                unset($x);
            }
        }
        ksort($byMonth);
        $sum = fn ($k) => array_sum(array_map(fn ($b) => $b['budget'][$k] ?? 0, $base['brands']));
        return $base + [
            'months' => array_values(array_map(fn ($m) => $m + ['cost_per_sale' => $m['orders'] > 0 && $m['spend_tax'] > 0 ? $m['spend_tax'] / $m['orders'] : null], $byMonth)),
            'budget' => ['avg_daily_tax' => $sum('avg_daily_tax'), 'avg_month_tax' => $sum('avg_month_tax'), 'planned_month' => $sum('planned_month'), 'planned_next' => $sum('planned_next'), 'suggested_next' => $sum('suggested_next'), 'mtd_spend_tax' => $sum('mtd_spend_tax')],
            'next_month' => $nextKey,
        ];
    }

    public static function overview(int $orgId, array $brandIds, DateRange $range): array
    {
        $org = Clients::find($orgId);
        $since = $range->since->format('Y-m-d');
        $until = $range->until->format('Y-m-d');
        $month = date('Y-m');
        $monthStart = date('Y-m-01');
        $today = date('Y-m-d');
        $brands = [];
        foreach (Clients::children($orgId) as $b) {
            if (!in_array($b['id'], $brandIds, true)) {
                continue;
            }
            $bid = $b['id'];
            $accounts = Clients::allowed($bid)['ad_account'];
            $ads = Reports::adsTotals($bid, $accounts, $range);
            $sales = Sales::totals($bid, $since, $until);
            $goal = Planner::goal($bid, $month);
            $mtd = $goal !== null ? Sales::totals($bid, $monthStart, $today)['orders'] : null;
            $daily = [];
            foreach (Db::all('SELECT day, SUM(orders) AS o FROM sales_daily WHERE client_id = ? AND day BETWEEN ? AND ? GROUP BY day ORDER BY day', [$bid, $since, $until]) as $r) {
                $daily[$r['day']] = (float) $r['o'];
            }
            $series = [];
            for ($d = $range->since; $d <= $range->until; $d = $d->modify('+1 day')) {
                $series[] = $daily[$d->format('Y-m-d')] ?? 0;
            }
            $last = Db::one('SELECT MAX(day) AS d FROM sales_daily WHERE client_id = ?', [$bid])['d'] ?? null;
            $brands[] = $b + [
                'gestion_label' => Clients::GESTIONES[$b['gestion'] ?? ''] ?? null,
                'has_ads' => (bool) $accounts,
                'ads' => $ads, 'sales' => $sales, 'series' => $series,
                'cost_per_sale' => $sales['orders'] > 0 && $ads['spend_tax'] > 0 ? $ads['spend_tax'] / $sales['orders'] : null,
                'cost_per_conversation' => $ads['messages'] > 0 ? $ads['spend_tax'] / $ads['messages'] : null,
                'conversion' => $ads['messages'] > 0 && $sales['orders'] > 0 ? $sales['orders'] / $ads['messages'] * 100 : null,
                'goal' => $goal, 'goal_mtd' => $mtd, 'goal_pct' => $goal ? $mtd / $goal * 100 : null,
                'expected_pct' => ((int) date('j') - 1) / (int) date('t') * 100,
                'last_day' => $last,
            ];
        }
        $sum = fn ($f) => array_sum(array_map($f, $brands));
        $spend = $sum(fn ($b) => $b['ads']['spend_tax']);
        $orders = $sum(fn ($b) => $b['sales']['orders']);
        return [
            'org' => ['id' => $org['id'], 'name' => $org['name'], 'logo_url' => $org['logo_url'], 'color' => $org['color'], 'currency' => $org['currency']],
            'range' => $range->toArray(), 'tax_rate' => Settings::taxRate(),
            'totals' => [
                'spend_tax' => $spend, 'messages' => $sum(fn ($b) => $b['ads']['messages']), 'results' => $sum(fn ($b) => $b['ads']['results']),
                'orders' => $orders, 'revenue' => $sum(fn ($b) => $b['sales']['revenue']),
                'cost_per_sale' => $orders > 0 && $spend > 0 ? $spend / $orders : null,
            ],
            'brands' => $brands,
        ];
    }
}
