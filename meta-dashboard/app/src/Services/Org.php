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
