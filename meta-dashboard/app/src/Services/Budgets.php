<?php
declare(strict_types=1);

namespace App\Services;

use App\Cache;
use App\Config;
use App\Db;
use App\Meta\GraphClient;
use App\Meta\GraphException;
use App\Settings;
use InvalidArgumentException;

/**
 * Presupuestos del mes: lo planificado por cuenta, lo configurado en Meta
 * (CBO o suma de conjuntos ABO) y lo gastado, siempre con impuestos.
 */
final class Budgets
{
    private const STATUSES = ['ACTIVE', 'PAUSED', 'IN_PROCESS', 'WITH_ISSUES', 'PENDING_REVIEW', 'PENDING_BILLING_INFO', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED'];

    /** $only: cuentas visibles (null = todas). $clientIds: marcas visibles (null = todas). */
    public static function overview(?array $only = null, bool $force = false, ?array $clientIds = null): array
    {
        $tax = Settings::taxRate();
        $month = date('Y-m');
        $daysInMonth = (int) date('t');
        $elapsed = (int) date('j') - 1 + (int) date('G') / 24 + 0.0001; // días transcurridos, con fracción de hoy
        $daysLeft = $daysInMonth - (int) date('j') + 1; // incluye hoy

        $goals = [];
        foreach (Db::all('SELECT campaign_id, target_orders, target_revenue FROM campaign_targets WHERE month = ?', [$month]) as $g) {
            $goals[$g['campaign_id']] = [
                'target_orders'  => $g['target_orders'] !== null ? (float) $g['target_orders'] : null,
                'target_revenue' => $g['target_revenue'] !== null ? (float) $g['target_revenue'] : null,
            ];
        }
        // Marcas (clientes) y qué campañas les corresponden en cada cuenta.
        $brands = [];
        foreach (Db::all('SELECT id, name, color, currency FROM clients ORDER BY name') as $c) {
            $cid = (int) $c['id'];
            if ($clientIds !== null && !in_array($cid, $clientIds, true)) {
                continue;
            }
            $brands[$cid] = $c + [
                'full'  => array_column(Db::all("SELECT external_id FROM client_assets WHERE client_id = ? AND asset_type = 'ad_account'", [$cid]), 'external_id'),
                'rules' => Clients::rules($cid),
            ];
        }
        $brandsOf = function (string $accountId, string $campaignId, string $name) use ($brands): array {
            $out = [];
            foreach ($brands as $cid => $b) {
                if (in_array($accountId, $b['full'], true) || (isset($b['rules'][$accountId]) && Clients::matches($b['rules'][$accountId], $campaignId, $name, $accountId))) {
                    $out[] = $cid;
                }
            }
            return $out;
        };

        $planned = [];
        foreach (Db::all('SELECT account_id, amount, note FROM ad_budgets WHERE month = ?', [$month]) as $r) {
            $planned[$r['account_id']] = ['amount' => (float) $r['amount'], 'note' => $r['note']];
        }

        $accounts = Assets::list('ad_account', false, $only);
        $byConnection = [];
        foreach ($accounts as $a) {
            $byConnection[$a['connection_id']][] = $a;
        }

        $out = [];
        foreach ($byConnection as $connectionId => $list) {
            $client = new GraphClient(Connections::token((int) $connectionId));
            $key = 'budgets:' . $connectionId . ':' . date('Y-m-d') . ':' . md5(implode(',', array_column($list, 'external_id')));
            $res = Cache::remember($key, (int) Config::get('cache.realtime_ttl', 120), function () use ($client, $list) {
                $req = [];
                foreach ($list as $a) {
                    $id = $a['external_id'];
                    $req["$id|camps"] = ['path' => "$id/campaigns", 'params' => [
                        'fields' => 'id,name,effective_status,objective,daily_budget,lifetime_budget,budget_remaining,start_time,stop_time,'
                            . 'adsets.limit(100){daily_budget,lifetime_budget,budget_remaining,effective_status}',
                        'effective_status' => self::STATUSES, 'limit' => 200,
                    ]];
                    $req["$id|mtd"] = ['path' => "$id/insights", 'params' => ['level' => 'campaign', 'date_preset' => 'this_month', 'fields' => 'campaign_id,campaign_name,spend', 'limit' => 500]];
                    $req["$id|today"] = ['path' => "$id/insights", 'params' => ['level' => 'campaign', 'date_preset' => 'today', 'fields' => 'campaign_id,spend', 'limit' => 500]];
                }
                $o = [];
                foreach ($client->batch($req) as $k => $v) {
                    $o[$k] = $v instanceof GraphException ? ['__error' => $v->getMessage()] : $v;
                }
                return $o;
            }, $force);

            foreach ($list as $a) {
                $id = $a['external_id'];
                $mtd = [];
                $mtdNames = [];
                foreach ($res["$id|mtd"]['data'] ?? [] as $r) {
                    $mtd[$r['campaign_id']] = (float) $r['spend'];
                    $mtdNames[$r['campaign_id']] = $r['campaign_name'] ?? $r['campaign_id'];
                }
                $today = [];
                foreach ($res["$id|today"]['data'] ?? [] as $r) {
                    $today[$r['campaign_id']] = (float) $r['spend'];
                }

                $camps = [];
                $activeDaily = 0.0;
                $seen = [];
                foreach ($res["$id|camps"]['data'] ?? [] as $c) {
                    $seen[$c['id']] = true;
                    [$type, $daily, $lifetime, $remaining] = self::budgetOf($c);
                    $active = ($c['effective_status'] ?? '') === 'ACTIVE';
                    if ($active && $daily) {
                        $activeDaily += $daily;
                    }
                    $spentMtd = $mtd[$c['id']] ?? 0.0;
                    if (!$active && $spentMtd <= 0) {
                        continue;
                    }
                    $spentToday = $today[$c['id']] ?? 0.0;
                    $camps[] = [
                        'id' => $c['id'], 'name' => $c['name'], 'effective_status' => $c['effective_status'] ?? null, 'objective' => $c['objective'] ?? null,
                        'budget_type' => $type, 'daily' => $daily, 'daily_tax' => $daily !== null ? $daily * (1 + $tax) : null,
                        'lifetime' => $lifetime, 'remaining' => $remaining,
                        'stop_time' => $c['stop_time'] ?? null,
                        'spent_today' => $spentToday, 'spent_today_tax' => $spentToday * (1 + $tax),
                        'today_pct' => $daily ? min(999, $spentToday / $daily * 100) : null,
                        'spent_mtd' => $spentMtd, 'spent_mtd_tax' => $spentMtd * (1 + $tax),
                        'goal' => $goals[$c['id']] ?? null,
                        'brands' => $brandsOf($id, $c['id'], $c['name']),
                        'active' => $active,
                    ];
                }
                // Campañas que gastaron este mes pero ya no están activas ni pausadas (archivadas, etc.).
                foreach ($mtd as $cid => $spent) {
                    if (!isset($seen[$cid]) && $spent > 0) {
                        $camps[] = ['id' => $cid, 'name' => $mtdNames[$cid], 'effective_status' => 'ARCHIVED', 'objective' => null, 'budget_type' => null,
                            'daily' => null, 'daily_tax' => null, 'lifetime' => null, 'remaining' => null, 'stop_time' => null,
                            'spent_today' => 0, 'spent_today_tax' => 0, 'today_pct' => null, 'spent_mtd' => $spent, 'spent_mtd_tax' => $spent * (1 + $tax), 'goal' => $goals[$cid] ?? null,
                            'brands' => $brandsOf($id, (string) $cid, (string) $mtdNames[$cid]), 'active' => false];
                    }
                }
                usort($camps, fn ($x, $y) => $y['spent_mtd'] <=> $x['spent_mtd']);

                $spentMtd = array_sum($mtd);
                $spentMtdTax = $spentMtd * (1 + $tax);
                $spentToday = array_sum($today);
                $plan = $planned[$id]['amount'] ?? null;
                $projPace = $spentMtdTax / $elapsed * $daysInMonth;
                // Lo gastado + lo que falta de hoy + días restantes a presupuesto diario activo.
                $projBudgets = $spentMtdTax + max(0, $activeDaily - $spentToday) * (1 + $tax) + $activeDaily * (1 + $tax) * max(0, $daysLeft - 1);
                $out[] = [
                    'id' => $id, 'name' => $a['name'], 'currency' => $a['data']['currency'] ?? 'ARS',
                    'business' => $a['data']['business']['name'] ?? null, 'error' => $res["$id|camps"]['__error'] ?? $res["$id|mtd"]['__error'] ?? null,
                    'planned' => $plan, 'note' => $planned[$id]['note'] ?? null,
                    'spent_today' => $spentToday, 'spent_today_tax' => $spentToday * (1 + $tax),
                    'spent_mtd' => $spentMtd, 'spent_mtd_tax' => $spentMtdTax,
                    'active_daily' => $activeDaily, 'active_daily_tax' => $activeDaily * (1 + $tax),
                    'projected_pace' => $projPace, 'projected_budgets' => $projBudgets,
                    'remaining' => $plan !== null ? $plan - $spentMtdTax : null,
                    'pct' => $plan ? $spentMtdTax / $plan * 100 : null,
                    // Presupuesto diario a cargar en Meta (sin impuestos) para cerrar justo en lo planificado.
                    'suggested_daily' => $plan !== null && $daysLeft > 0 ? max(0, ($plan - $spentMtdTax) / $daysLeft / (1 + $tax)) : null,
                    'status' => self::status($plan, $projBudgets ?: $projPace),
                    'campaigns' => $camps,
                ];
            }
        }
        usort($out, fn ($x, $y) => $y['spent_mtd'] <=> $x['spent_mtd']);

        // Resumen por marca: suma de sus campañas en todas las cuentas.
        $byBrand = [];
        foreach ($out as $a) {
            foreach ($a['campaigns'] as $c) {
                foreach ($c['brands'] as $cid) {
                    $b = &$byBrand[$cid];
                    $b ??= ['client_id' => $cid, 'name' => $brands[$cid]['name'], 'color' => $brands[$cid]['color'], 'currency' => $a['currency'],
                        'campaigns' => 0, 'active' => 0, 'spent_mtd' => 0.0, 'spent_mtd_tax' => 0.0, 'spent_today_tax' => 0.0, 'active_daily_tax' => 0.0, 'projected' => 0.0, 'accounts' => []];
                    $b['campaigns']++;
                    $b['spent_mtd'] += $c['spent_mtd'];
                    $b['spent_mtd_tax'] += $c['spent_mtd_tax'];
                    $b['spent_today_tax'] += $c['spent_today_tax'];
                    $b['projected'] += $c['spent_mtd_tax'];
                    if ($c['active'] && $c['daily']) {
                        $b['active']++;
                        $b['active_daily_tax'] += $c['daily_tax'];
                        $b['projected'] += max(0, $c['daily'] - $c['spent_today']) * (1 + $tax) + $c['daily_tax'] * max(0, $daysLeft - 1);
                    }
                    if (!in_array($a['name'], $b['accounts'], true)) {
                        $b['accounts'][] = $a['name'];
                    }
                    unset($b);
                }
            }
        }
        foreach ($byBrand as $cid => &$b) {
            $plan = $planned['client:' . $cid]['amount'] ?? null;
            $b['planned'] = $plan;
            $b['remaining'] = $plan !== null ? $plan - $b['spent_mtd_tax'] : null;
            $b['pct'] = $plan ? $b['spent_mtd_tax'] / $plan * 100 : null;
            $b['suggested_daily'] = $plan !== null && $daysLeft > 0 ? max(0, ($plan - $b['spent_mtd_tax']) / $daysLeft / (1 + $tax)) : null;
            $b['status'] = self::status($plan, $b['projected']);
        }
        unset($b);
        $byBrand = array_values($byBrand);
        usort($byBrand, fn ($x, $y) => $y['spent_mtd'] <=> $x['spent_mtd']);
        $brandNames = array_map(fn ($b) => ['name' => $b['name'], 'color' => $b['color']], $brands);

        $totals = [];
        foreach ($out as $a) {
            $t = &$totals[$a['currency']];
            $t ??= ['currency' => $a['currency'], 'planned' => 0, 'spent_mtd' => 0, 'spent_mtd_tax' => 0, 'spent_today_tax' => 0, 'projected_budgets' => 0, 'active_daily_tax' => 0, 'accounts' => 0];
            $t['planned'] += $a['planned'] ?? 0;
            $t['spent_mtd'] += $a['spent_mtd'];
            $t['spent_mtd_tax'] += $a['spent_mtd_tax'];
            $t['spent_today_tax'] += $a['spent_today_tax'];
            $t['projected_budgets'] += $a['projected_budgets'];
            $t['active_daily_tax'] += $a['active_daily_tax'];
            $t['accounts']++;
            unset($t);
        }
        $totals = array_values($totals);
        usort($totals, fn ($x, $y) => $y['spent_mtd'] <=> $x['spent_mtd']);

        return [
            'month' => $month, 'days_in_month' => $daysInMonth, 'day' => (int) date('j'), 'days_left' => $daysLeft,
            'tax_rate' => $tax, 'accounts' => $out, 'totals' => $totals,
            'brands' => $byBrand, 'brand_names' => $brandNames,
        ];
    }

    /** $accountId: 'act_…' para una cuenta o 'client:ID' para una marca. */
    public static function save(string $accountId, ?string $month, mixed $amount, ?string $note = null): void
    {
        if (str_starts_with($accountId, 'client:')) {
            Clients::find((int) substr($accountId, 7));
        } else {
            Assets::find('ad_account', $accountId);
        }
        $month = $month && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
        $value = SalesImport::parseNumber($amount);
        if ($value === null || $value <= 0) {
            Db::exec('DELETE FROM ad_budgets WHERE account_id = ? AND month = ?', [$accountId, $month]);
            return;
        }
        if ($value > 1e13) {
            throw new InvalidArgumentException('Monto inválido.');
        }
        Db::upsert('ad_budgets', ['account_id' => $accountId, 'month' => $month, 'amount' => $value, 'note' => $note ? mb_substr($note, 0, 250) : null, 'updated_at' => Db::now()], ['account_id', 'month']);
    }

    /** [tipo, diario, total, restante] — CBO (campaña) o suma de conjuntos activos (ABO). */
    private static function budgetOf(array $c): array
    {
        if (!empty($c['daily_budget'])) {
            return ['Campaña · diario', $c['daily_budget'] / 100, null, null];
        }
        if (!empty($c['lifetime_budget'])) {
            return ['Campaña · total', null, $c['lifetime_budget'] / 100, isset($c['budget_remaining']) ? $c['budget_remaining'] / 100 : null];
        }
        $daily = 0.0;
        $lifetime = 0.0;
        $remaining = 0.0;
        foreach ($c['adsets']['data'] ?? [] as $s) {
            if (($s['effective_status'] ?? '') !== 'ACTIVE') {
                continue;
            }
            $daily += !empty($s['daily_budget']) ? $s['daily_budget'] / 100 : 0;
            $lifetime += !empty($s['lifetime_budget']) ? $s['lifetime_budget'] / 100 : 0;
            $remaining += !empty($s['lifetime_budget']) && isset($s['budget_remaining']) ? $s['budget_remaining'] / 100 : 0;
        }
        if ($daily > 0) {
            return ['Conjuntos · diario', $daily, $lifetime ?: null, $remaining ?: null];
        }
        if ($lifetime > 0) {
            return ['Conjuntos · total', null, $lifetime, $remaining ?: null];
        }
        return [null, null, null, null];
    }

    private static function status(?float $plan, float $projected): string
    {
        if (!$plan) {
            return 'sin_plan';
        }
        $r = $projected / $plan;
        return $r > 1.05 ? 'excede' : ($r < 0.85 ? 'subejecuta' : 'en_ritmo');
    }
}
