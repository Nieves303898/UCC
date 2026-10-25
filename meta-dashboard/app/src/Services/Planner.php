<?php
declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\DateRange;
use App\Settings;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Plan de presupuesto por objetivos: a partir del objetivo de ventas de cada
 * marca y de su costo por venta real (con impuestos), estima cuánto hay que
 * invertir por mes, semana y día, y marca quién supera el objetivo gastando menos.
 */
final class Planner
{
    private const MONTHS = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    public const BASES = ['avg3' => 'Promedio últimos 3 meses', 'prev' => 'Mes anterior', 'current' => 'Mes en curso'];

    /** $which: 'current' (mes en curso) o 'next' (mes siguiente). */
    public static function plan(array $clientIds, string $which = 'next', string $basis = 'avg3'): array
    {
        $basis = isset(self::BASES[$basis]) ? $basis : 'avg3';
        $tax = Settings::taxRate();
        $today = new DateTimeImmutable('today');
        $cutoff = $today->modify('-1 day'); // las ventas se cargan hasta ayer
        $curStart = $today->modify('first day of this month');
        $isCurrent = $which === 'current';
        $target = $isCurrent ? $curStart : $curStart->modify('+1 month');
        $days = (int) $target->format('t');
        $elapsed = (int) $today->format('j') - 1;            // días completos del mes en curso
        $remaining = (int) $today->format('t') - $elapsed;    // incluye hoy

        // Ventanas de costo: dos meses cerrados + lo que va del actual.
        $prevStart = $curStart->modify('-1 month');
        $windows = [
            'prev'    => new DateRange($prevStart, $prevStart->modify('last day of this month'), 'custom'),
            'avg3'    => new DateRange($curStart->modify('-2 month'), $cutoff, 'custom'),
            'current' => $elapsed > 0 ? new DateRange($curStart, $cutoff, 'custom') : null,
        ];

        $budgets = [];
        try {
            foreach (Budgets::overview(null, false, $clientIds)['brands'] as $b) {
                $budgets[$b['client_id']] = $b;
            }
        } catch (\Throwable) {
        }
        $goals = [];
        foreach (Db::all('SELECT client_id, target_orders, target_revenue FROM brand_goals WHERE month = ?', [$target->format('Y-m')]) as $g) {
            $goals[(int) $g['client_id']] = $g;
        }
        $planned = [];
        foreach (Db::all("SELECT account_id, amount FROM ad_budgets WHERE month = ? AND account_id LIKE 'client:%'", [$target->format('Y-m')]) as $p) {
            $planned[(int) substr($p['account_id'], 7)] = (float) $p['amount'];
        }

        $brands = [];
        foreach (self::brands($clientIds) as $c) {
            $cid = (int) $c['id'];
            $accounts = Clients::allowed($cid)['ad_account'];

            $stats = [];
            foreach ($windows as $k => $r) {
                $stats[$k] = $r ? self::stats($cid, $accounts, $r) : null;
            }
            $cost = null;
            $costFrom = null;
            foreach (array_unique([$basis, 'avg3', 'prev', 'current']) as $k) {
                if (($stats[$k]['cost'] ?? null) !== null) {
                    [$cost, $costFrom] = [$stats[$k]['cost'], $k];
                    break;
                }
            }
            $aovFrom = $stats[$costFrom ?? $basis] ?? $stats['avg3'];
            $aov = $aovFrom && $aovFrom['orders'] > 0 && $aovFrom['revenue'] > 0 ? $aovFrom['revenue'] / $aovFrom['orders'] : null;

            // Objetivo: el del mes; si no hay, el objetivo mensual fijo de la ficha del cliente.
            $g = $goals[$cid] ?? null;
            $tOrders = $g && $g['target_orders'] !== null ? (float) $g['target_orders'] : null;
            $tRevenue = $g && $g['target_revenue'] !== null ? (float) $g['target_revenue'] : null;
            $goalFrom = $g ? 'mes' : null;
            if (!$g && $c['target_orders'] !== null) {
                $tOrders = (float) $c['target_orders'];
                $tRevenue = $c['target_revenue'] !== null ? (float) $c['target_revenue'] : null;
                $goalFrom = 'cliente';
            }
            $orders = $tOrders ?? ($tRevenue !== null && $aov ? $tRevenue / $aov : null);
            $required = $orders !== null && $cost !== null ? $orders * $cost : null;

            $daily = (float) ($budgets[$cid]['active_daily_tax'] ?? 0);
            $row = [
                'id' => $cid, 'name' => $c['name'], 'color' => $c['color'], 'logo_url' => $c['logo_url'] ?? null, 'currency' => $c['currency'],
                'sales_label' => $c['sales_label'] ?? null,
                'has_ads' => (bool) $accounts,
                'target_orders' => $tOrders, 'target_revenue' => $tRevenue, 'goal_orders' => $orders, 'goal_from' => $goalFrom,
                'cost' => $cost, 'cost_from' => $costFrom, 'aov' => $aov,
                'costs' => array_map(fn ($s) => $s['cost'] ?? null, $stats),
                'required' => $required,
                'required_daily' => $required !== null ? $required / $days : null,
                'required_daily_meta' => $required !== null ? $required / $days / (1 + $tax) : null,
                'active_daily_tax' => $daily,
                'pace_month' => $daily * $days,
                'pace_orders' => $cost ? $daily * $days / $cost : null,
                'planned' => $planned[$cid] ?? null,
                'status' => $orders === null ? 'sin_objetivo' : ($cost === null ? 'sin_datos' : 'plan'),
                'efficient' => false,
            ];

            if ($isCurrent) {
                $mtd = $stats['current'] ?? ['spend_tax' => 0.0, 'orders' => 0.0, 'revenue' => 0.0, 'cost' => null];
                $todo = $orders !== null ? max(0, $orders - $mtd['orders']) : null;
                // Para lo que falta usamos el costo del mes si ya hay ventas; si no, la base elegida.
                $restCost = $mtd['cost'] ?? $cost;
                $projected = $restCost ? $mtd['orders'] + $daily * $remaining / $restCost : null;
                $row += [
                    'to_date' => $mtd,
                    'remaining_orders' => $todo,
                    'needed_rest' => $todo !== null && $restCost ? $todo * $restCost : null,
                    'daily_needed' => $todo !== null && $restCost ? $todo * $restCost / $remaining : null,
                    'projected_orders' => $projected,
                    'progress' => $orders ? $mtd['orders'] / $orders * 100 : null,
                    'expected_progress' => $elapsed / (int) $today->format('t') * 100,
                ];
                if ($orders !== null && $mtd['orders'] > 0) {
                    $row['status'] = $mtd['orders'] >= $orders ? 'cumplido'
                        : ($projected !== null && $projected >= $orders ? 'en_camino'
                        : ($projected !== null && $projected >= $orders * 0.8 ? 'ajustar' : 'en_riesgo'));
                } elseif ($orders !== null) {
                    $row['status'] = $restCost ? 'en_riesgo' : 'sin_datos';
                }
                // Supera (o va a superar) el objetivo gastando menos de lo que hubiera costado con la base.
                $budgetRef = $required ?? 0;
                $row['efficient'] = $orders !== null && $budgetRef > 0
                    && ($projected ?? 0) >= $orders
                    && $mtd['spend_tax'] + $daily * $remaining < $budgetRef;
                $row['savings'] = $row['efficient'] ? $budgetRef - ($mtd['spend_tax'] + $daily * $remaining) : null;
            } else {
                $row['delta_daily'] = $required !== null ? $required / $days - $daily : null;
                $row['efficient'] = $cost !== null && ($stats['current']['cost'] ?? null) !== null && $stats['current']['cost'] < $cost * 0.9;
            }
            // Costo del mes en curso bastante por debajo de la base: escala bien con poco presupuesto.
            $row['cheaper_pct'] = $cost && ($stats['current']['cost'] ?? null) ? (1 - $stats['current']['cost'] / $cost) * 100 : null;
            $brands[] = $row;
        }

        return [
            'which' => $isCurrent ? 'current' : 'next',
            'month' => ['key' => $target->format('Y-m'), 'name' => self::MONTHS[(int) $target->format('n')], 'year' => (int) $target->format('Y'), 'days' => $days],
            'day' => (int) $today->format('j'), 'remaining_days' => $remaining,
            'basis' => $basis, 'bases' => self::BASES, 'tax_rate' => $tax,
            'brands' => $brands,
        ];
    }

    public static function saveGoal(int $clientId, ?string $month, mixed $orders, mixed $revenue): void
    {
        Clients::find($clientId);
        $month = $month && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
        $o = SalesImport::parseNumber($orders);
        $r = SalesImport::parseNumber($revenue);
        if (($o !== null && ($o < 0 || $o > 1e9)) || ($r !== null && ($r < 0 || $r > 1e14))) {
            throw new InvalidArgumentException('Objetivo inválido.');
        }
        if (!$o && !$r) {
            Db::exec('DELETE FROM brand_goals WHERE client_id = ? AND month = ?', [$clientId, $month]);
            return;
        }
        Db::upsert('brand_goals', ['client_id' => $clientId, 'month' => $month, 'target_orders' => $o ?: null, 'target_revenue' => $r ?: null, 'updated_at' => Db::now()], ['client_id', 'month']);
    }

    /** Objetivo de ventas cargado para una marca y mes (o null). */
    public static function goal(int $clientId, string $month): ?float
    {
        $g = Db::one('SELECT target_orders FROM brand_goals WHERE client_id = ? AND month = ?', [$clientId, $month]);
        return $g && $g['target_orders'] !== null ? (float) $g['target_orders'] : null;
    }

    private static function stats(int $cid, array $accounts, DateRange $r): array
    {
        $ads = Reports::adsTotals($cid, $accounts, $r);
        $sales = Sales::totals($cid, $r->since->format('Y-m-d'), $r->until->format('Y-m-d'));
        return [
            'spend_tax' => (float) $ads['spend_tax'], 'orders' => (float) $sales['orders'], 'revenue' => (float) $sales['revenue'],
            'cost' => $sales['orders'] > 0 && $ads['spend_tax'] > 0 ? $ads['spend_tax'] / $sales['orders'] : null,
        ];
    }

    private static function brands(array $clientIds): array
    {
        if (!$clientIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($clientIds), '?'));
        return Db::all("SELECT * FROM clients WHERE id IN ($ph) ORDER BY name", array_map('intval', $clientIds));
    }
}
