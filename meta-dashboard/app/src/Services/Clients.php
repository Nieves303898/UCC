<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Db;
use InvalidArgumentException;

/** Clientes de la agencia: activos asignados, accesos y "trabajo en curso". */
final class Clients
{
    public const UPDATE_KINDS = [
        'campana' => 'Campaña', 'contenido' => 'Contenido', 'optimizacion' => 'Optimización',
        'reporte' => 'Reporte', 'reunion' => 'Reunión', 'web' => 'Web / landing', 'otro' => 'Otro',
    ];
    public const UPDATE_STATUS = ['planificado' => 'Planificado', 'en_curso' => 'En curso', 'hecho' => 'Hecho'];

    public static function all(): array
    {
        $rows = Db::all('SELECT * FROM clients ORDER BY name');
        foreach ($rows as &$c) {
            $c['id'] = (int) $c['id'];
            $c['assets'] = (int) Db::one('SELECT COUNT(*) AS n FROM client_assets WHERE client_id = ?', [$c['id']])['n']
                + count(self::rules($c['id']));
            $c['users'] = (int) Db::one('SELECT COUNT(*) AS n FROM users WHERE client_id = ?', [$c['id']])['n'];
            $c['open_updates'] = (int) Db::one("SELECT COUNT(*) AS n FROM client_updates WHERE client_id = ? AND status <> 'hecho'", [$c['id']])['n'];
        }
        return $rows;
    }

    public static function find(int $id): array
    {
        $c = Db::one('SELECT * FROM clients WHERE id = ?', [$id]);
        if (!$c) {
            throw new InvalidArgumentException('Cliente inexistente.');
        }
        $c['id'] = (int) $c['id'];
        return $c;
    }

    public static function detail(int $id): array
    {
        return self::find($id) + [
            'assets'  => Db::all('SELECT asset_type, external_id FROM client_assets WHERE client_id = ?', [$id]),
            'rules'   => self::rules($id),
            'users'   => Db::all('SELECT id, username, name, last_login_at, created_at FROM users WHERE client_id = ? ORDER BY id', [$id]),
            'updates' => self::updates($id),
            'sales_sources' => Sales::sources($id),
            'sales_recent'  => Sales::recent($id),
        ];
    }

    /** Datos públicos del cliente para su propio portal. */
    public static function brand(int $id): array
    {
        $c = self::find($id);
        return [
            'id' => $c['id'], 'name' => $c['name'], 'color' => $c['color'], 'logo_url' => $c['logo_url'], 'sales_label' => $c['sales_label'],
            'has_sales' => Sales::hasData($c['id']), 'has_ads' => (bool) Clients::allowed($c['id'])['ad_account'],
        ];
    }

    public static function save(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('El cliente necesita un nombre.');
        }
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($data['color'] ?? '')) ? $data['color'] : '#0e7c66';
        $logo = filter_var($data['logo_url'] ?? '', FILTER_VALIDATE_URL)
            && (str_starts_with((string) $data['logo_url'], 'https://') || str_starts_with((string) $data['logo_url'], \App\Config::url('uploads/')))
            ? $data['logo_url'] : null;
        $label = fn ($k, $def) => mb_substr(trim((string) ($data[$k] ?? '')), 0, 60) ?: $def;
        $num = fn ($k) => trim((string) ($data[$k] ?? '')) === '' ? null : SalesImport::parseNumber($data[$k]);
        $row = [
            'name' => mb_substr($name, 0, 190), 'color' => $color, 'logo_url' => $logo, 'notes' => $data['notes'] ?? null,
            'currency' => preg_match('/^[A-Z]{3}$/', strtoupper((string) ($data['currency'] ?? ''))) ? strtoupper($data['currency']) : 'ARS',
            'sales_label' => $label('sales_label', 'Ventas'), 'leads_label' => $label('leads_label', 'Consultas'), 'dim_label' => $label('dim_label', 'Sucursal'),
            'target_orders' => $num('target_orders'), 'target_revenue' => $num('target_revenue'),
        ];
        if (!empty($data['id'])) {
            self::find((int) $data['id']);
            $sets = implode(',', array_map(fn ($c) => "$c = ?", array_keys($row)));
            Db::exec("UPDATE clients SET $sets WHERE id = ?", [...array_values($row), (int) $data['id']]);
            return (int) $data['id'];
        }
        return Db::insert('clients', $row + ['created_at' => Db::now()]);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM client_assets WHERE client_id = ?', [$id]);
        Db::exec('DELETE FROM client_updates WHERE client_id = ?', [$id]);
        Db::exec('DELETE FROM sales_daily WHERE client_id = ?', [$id]);
        Db::exec('DELETE FROM client_campaign_rules WHERE client_id = ?', [$id]);
        Db::exec('DELETE FROM sales_sources WHERE client_id = ?', [$id]);
        Db::exec('DELETE FROM users WHERE client_id = ?', [$id]);
        Db::exec('DELETE FROM clients WHERE id = ?', [$id]);
    }

    /**
     * Reemplaza los activos asignados.
     * $assets = [['type' => 'ad_account', 'id' => 'act_1'], ...]  (cuenta completa, página, Instagram)
     * $rules  = [['account' => 'act_1', 'patterns' => ['go celular', 'gocelular']], ...]  (sólo esas campañas)
     */
    public static function setAssets(int $id, array $assets, ?array $rules = null): void
    {
        self::find($id);
        $valid = ['ad_account', 'page', 'ig_account'];
        Db::exec('DELETE FROM client_assets WHERE client_id = ?', [$id]);
        $full = [];
        foreach ($assets as $a) {
            if (!in_array($a['type'] ?? '', $valid, true) || !Db::one('SELECT id FROM assets WHERE type = ? AND external_id = ?', [$a['type'], (string) $a['id']])) {
                continue;
            }
            Db::insert('client_assets', ['client_id' => $id, 'asset_type' => $a['type'], 'external_id' => (string) $a['id']]);
            if ($a['type'] === 'ad_account') {
                $full[] = (string) $a['id'];
            }
        }
        if ($rules === null) {
            return;
        }
        Db::exec('DELETE FROM client_campaign_rules WHERE client_id = ?', [$id]);
        foreach ($rules as $r) {
            $acc = (string) ($r['account'] ?? '');
            if (in_array($acc, $full, true) || !Db::one("SELECT id FROM assets WHERE type = 'ad_account' AND external_id = ?", [$acc])) {
                continue; // con la cuenta completa asignada, las reglas no hacen falta
            }
            foreach (array_unique(array_filter(array_map(fn ($p) => mb_substr(trim((string) $p), 0, 190), (array) ($r['patterns'] ?? [])))) as $p) {
                Db::insert('client_campaign_rules', ['client_id' => $id, 'account_id' => $acc, 'pattern' => $p, 'created_at' => Db::now()]);
            }
        }
    }

    /** Agrega palabras clave para un cliente en una cuenta (usado por la detección automática). */
    public static function addRule(int $clientId, string $accountId, string $pattern): void
    {
        self::find($clientId);
        $pattern = mb_substr(trim($pattern), 0, 190);
        if ($pattern === '' || Db::one("SELECT 1 AS x FROM client_assets WHERE client_id = ? AND asset_type = 'ad_account' AND external_id = ?", [$clientId, $accountId])) {
            return;
        }
        if (!Db::one('SELECT id FROM client_campaign_rules WHERE client_id = ? AND account_id = ? AND pattern = ?', [$clientId, $accountId, $pattern])) {
            Db::insert('client_campaign_rules', ['client_id' => $clientId, 'account_id' => $accountId, 'pattern' => $pattern, 'created_at' => Db::now()]);
        }
    }

    /** IDs permitidos por tipo para un cliente (incluye cuentas compartidas por reglas de campaña). */
    public static function allowed(int $clientId): array
    {
        $out = ['ad_account' => [], 'page' => [], 'ig_account' => []];
        foreach (Db::all('SELECT asset_type, external_id FROM client_assets WHERE client_id = ?', [$clientId]) as $r) {
            $out[$r['asset_type']][] = $r['external_id'];
        }
        foreach (array_keys(self::rules($clientId)) as $acc) {
            if (!in_array($acc, $out['ad_account'], true)) {
                $out['ad_account'][] = $acc;
            }
        }
        return $out;
    }

    /** Cuentas compartidas: [account_id => [palabras clave]]. Las cuentas completas no aparecen. */
    public static function rules(int $clientId): array
    {
        static $cache = [];
        if (!isset($cache[$clientId])) {
            $cache[$clientId] = [];
            foreach (Db::all('SELECT account_id, pattern FROM client_campaign_rules WHERE client_id = ? ORDER BY id', [$clientId]) as $r) {
                $cache[$clientId][$r['account_id']][] = $r['pattern'];
            }
        }
        return $cache[$clientId];
    }

    /** ¿La campaña cumple alguna palabra clave? Un patrón numérico se compara con el ID exacto. */
    public static function matches(array $patterns, string $campaignId, string $campaignName): bool
    {
        $name = SalesImport::normName($campaignName);
        $compact = str_replace(' ', '', $name);
        foreach ($patterns as $p) {
            if (ctype_digit($p)) {
                if ($p === $campaignId) {
                    return true;
                }
                continue;
            }
            $np = SalesImport::normName($p);
            if ($np !== '' && (str_contains($name, $np) || str_contains($compact, str_replace(' ', '', $np)))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Agrupa las campañas de una cuenta por marca a partir del nombre
     * ("GetPOS - Captación…", "Go Celular | Ventas…") para sugerir clientes.
     */
    public static function detectGroups(): array
    {
        $range = \App\DateRange::fromRequest(['preset' => 'last_90d']);
        $owners = [];
        foreach (Db::all('SELECT c.id, c.name, c.color FROM clients c ORDER BY c.name') as $c) {
            $owners[(int) $c['id']] = $c + ['rules' => self::rules((int) $c['id']), 'full' => array_column(Db::all("SELECT external_id FROM client_assets WHERE client_id = ? AND asset_type = 'ad_account'", [$c['id']]), 'external_id')];
        }
        $out = [];
        foreach (Assets::list('ad_account') as $a) {
            try {
                $camps = Ads::campaigns($a['external_id'], $range);
            } catch (\Throwable) {
                continue;
            }
            $groups = [];
            foreach ($camps as $c) {
                if ($c['effective_status'] !== 'ACTIVE' && $c['metrics']['spend'] <= 0) {
                    continue;
                }
                $label = self::brandOf($c['name']);
                $k = SalesImport::normName($label);
                $groups[$k] ??= ['label' => $label, 'pattern' => $label, 'campaigns' => [], 'spend' => 0, 'assigned_to' => []];
                $groups[$k]['campaigns'][] = $c['name'];
                $groups[$k]['spend'] += $c['metrics']['spend'];
                foreach ($owners as $oid => $o) {
                    $mine = in_array($a['external_id'], $o['full'], true)
                        || (isset($o['rules'][$a['external_id']]) && self::matches($o['rules'][$a['external_id']], $c['id'], $c['name']));
                    if ($mine && !in_array($o['name'], $groups[$k]['assigned_to'], true)) {
                        $groups[$k]['assigned_to'][] = $o['name'];
                    }
                }
            }
            if ($groups) {
                usort($groups, fn ($x, $y) => $y['spend'] <=> $x['spend']);
                $out[] = ['account' => $a['external_id'], 'name' => $a['name'], 'currency' => $a['data']['currency'] ?? 'ARS', 'groups' => array_values($groups)];
            }
        }
        return $out;
    }

    /** Primer segmento del nombre antes de un separador (" - ", " | ", " · ", ":"). */
    public static function brandOf(string $name): string
    {
        $parts = preg_split('/\s+[-–—|·:]\s+|\s*\|\s*|\s*·\s*/u', trim($name));
        $first = trim($parts[0] ?? $name);
        if ($first === '' || mb_strlen($first) > 40) {
            $first = implode(' ', array_slice(preg_split('/\s+/', trim($name)), 0, 2));
        }
        return $first;
    }

    public static function updates(int $clientId): array
    {
        return array_map(function ($u) {
            $u['id'] = (int) $u['id'];
            $u['kind_label'] = self::UPDATE_KINDS[$u['kind']] ?? $u['kind'];
            $u['status_label'] = self::UPDATE_STATUS[$u['status']] ?? $u['status'];
            return $u;
        }, Db::all('SELECT u.*, us.name AS author FROM client_updates u LEFT JOIN users us ON us.id = u.created_by
                    WHERE u.client_id = ? ORDER BY u.updated_at DESC, u.id DESC LIMIT 300', [$clientId]));
    }

    public static function saveUpdate(array $d): int
    {
        $clientId = (int) ($d['client_id'] ?? 0);
        self::find($clientId);
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Escribí un título.');
        }
        $due = $d['due_date'] ?? null;
        $row = [
            'kind'       => isset(self::UPDATE_KINDS[$d['kind'] ?? '']) ? $d['kind'] : 'otro',
            'status'     => isset(self::UPDATE_STATUS[$d['status'] ?? '']) ? $d['status'] : 'en_curso',
            'title'      => mb_substr($title, 0, 250),
            'body'       => trim((string) ($d['body'] ?? '')) ?: null,
            'link'       => filter_var($d['link'] ?? '', FILTER_VALIDATE_URL) ? $d['link'] : null,
            'due_date'   => $due && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
            'updated_at' => Db::now(),
        ];
        if (!empty($d['id'])) {
            $sets = implode(',', array_map(fn ($c) => "$c = ?", array_keys($row)));
            Db::exec("UPDATE client_updates SET $sets WHERE id = ? AND client_id = ?", [...array_values($row), (int) $d['id'], $clientId]);
            return (int) $d['id'];
        }
        return Db::insert('client_updates', $row + ['client_id' => $clientId, 'created_by' => Auth::user()['id'] ?? null, 'created_at' => Db::now()]);
    }

    public static function deleteUpdate(int $id): void
    {
        Db::exec('DELETE FROM client_updates WHERE id = ?', [$id]);
    }
}
