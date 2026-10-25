<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Cache;
use App\Db;
use InvalidArgumentException;

/** Clientes de la agencia: activos asignados, accesos y "trabajo en curso". */
final class Clients
{
    /** Tipo de gestión de una marca y cómo se llama su conversión por defecto. */
    public const GESTIONES = ['ventas' => 'Ventas', 'cobranzas' => 'Cobranzas', 'recupero' => 'Recuperos', 'turnos' => 'Turnos', 'leads' => 'Consultas', 'otra' => 'Conversiones'];

    /** Roles de las personas del cliente. Dueño y gerencia ven todas las marcas; el resto, las asignadas. */
    public const CLIENT_ROLES = ['dueno' => 'Dueño', 'gerente' => 'Gerente', 'supervisor' => 'Supervisor', 'asesor' => 'Asesor', 'usuario' => 'Usuario'];

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
            'users'   => array_map(fn ($u) => $u + ['brands' => array_map('intval', array_column(Db::all("SELECT ref FROM user_scopes WHERE user_id = ? AND kind = 'client'", [$u['id']]), 'ref'))],
                Db::all('SELECT id, username, name, client_role, last_login_at, created_at FROM users WHERE client_id = ? ORDER BY id', [$id])),
            'children' => self::children($id),
            'parent'   => ($p = self::find($id)['parent_id']) ? ['id' => (int) $p, 'name' => self::find((int) $p)['name']] : null,
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
            'kind' => $c['kind'] ?? 'cliente', 'gestion' => $c['gestion'] ?? null, 'parent_id' => isset($c['parent_id']) ? (int) $c['parent_id'] ?: null : null,
            'has_brands' => (bool) self::children($c['id']),
            'has_sheets' => (bool) Db::one('SELECT id FROM client_sheets WHERE client_id = ? LIMIT 1', [$c['id']]),
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
        if (isset($data['kind']) || empty($data['id'])) {
            $row['kind'] = ($data['kind'] ?? '') === 'marca' ? 'marca' : 'cliente';
        }
        if (array_key_exists('gestion', $data)) {
            $row['gestion'] = isset(self::GESTIONES[$data['gestion']]) ? $data['gestion'] : null;
        }
        if (array_key_exists('parent_id', $data) || isset($row['kind'])) {
            $row['parent_id'] = ($row['kind'] ?? 'marca') === 'marca' ? self::validParent($data['parent_id'] ?? null, (int) ($data['id'] ?? 0)) : null;
        }
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
        Db::exec('UPDATE clients SET parent_id = NULL WHERE parent_id = ?', [$id]);
        Db::exec('DELETE FROM client_sheets WHERE client_id = ?', [$id]);
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

    /**
     * IDs de las marcas (clientes marcados como "marca") dentro de $scope.
     * Si todavía no se definió ninguna marca, devuelve todos los clientes del alcance.
     */
    public static function brandIds(?array $scope = null): array
    {
        $rows = Db::all('SELECT id, kind FROM clients');
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $marcas = array_map(fn ($r) => (int) $r['id'], array_filter($rows, fn ($r) => $r['kind'] === 'marca'));
        $out = $marcas ?: $ids;
        return array_values($scope === null ? $out : array_intersect($out, $scope));
    }

    public static function hasBrands(): bool
    {
        return (bool) Db::one("SELECT id FROM clients WHERE kind = 'marca' LIMIT 1");
    }

    public static function setKind(int $id, string $kind): void
    {
        self::find($id);
        $kind = $kind === 'marca' ? 'marca' : 'cliente';
        Db::exec('UPDATE clients SET kind = ?, parent_id = CASE WHEN ? = ? THEN parent_id ELSE NULL END WHERE id = ?', [$kind, $kind, 'marca', $id]);
        if ($kind === 'marca') { // una marca no puede tener marcas
            Db::exec('UPDATE clients SET parent_id = NULL WHERE parent_id = ?', [$id]);
        }
    }

    /** Marcas de un cliente. */
    public static function children(int $id): array
    {
        return array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'color' => $c['color'], 'logo_url' => $c['logo_url'], 'gestion' => $c['gestion'] ?? null, 'sales_label' => $c['sales_label']],
            Db::all('SELECT * FROM clients WHERE parent_id = ? ORDER BY name', [$id]));
    }

    /**
     * Qué clientes/marcas puede abrir un usuario del portal: su cliente y, si el cliente tiene
     * marcas, todas (dueño, gerente o sin restricción) o sólo las que se le asignaron.
     */
    public static function portalClients(array $user): array
    {
        $base = (int) ($user['client_id'] ?? 0);
        if (!$base) {
            return [];
        }
        $children = array_column(self::children($base), 'id');
        $assigned = array_map('intval', array_column(Db::all("SELECT ref FROM user_scopes WHERE user_id = ? AND kind = 'client'", [(int) $user['id']]), 'ref'));
        $all = in_array($user['client_role'] ?? '', ['dueno', 'gerente'], true) || !$assigned;
        if ($all) {
            return array_values(array_unique([$base, ...$children]));
        }
        // Restringido a algunas marcas: no ve el cliente completo (sus cuentas mezclan marcas).
        $mine = array_values(array_intersect($children, $assigned));
        return $mine ?: [$base];
    }

    /** Guarda rol y marcas visibles de una persona del cliente. */
    public static function setClientUser(int $userId, ?string $role, array $brandIds): void
    {
        $u = Db::one("SELECT id, client_id FROM users WHERE id = ? AND role = 'client'", [$userId]);
        if (!$u) {
            throw new InvalidArgumentException('Usuario inexistente.');
        }
        Db::exec('UPDATE users SET client_role = ? WHERE id = ?', [isset(self::CLIENT_ROLES[$role]) ? $role : null, $userId]);
        Db::exec("DELETE FROM user_scopes WHERE user_id = ? AND kind = 'client'", [$userId]);
        if (in_array($role, ['dueno', 'gerente'], true)) {
            return; // ven todas, incluidas las marcas nuevas
        }
        $valid = array_column(self::children((int) $u['client_id']), 'id');
        foreach (array_unique(array_map('intval', $brandIds)) as $b) {
            if (in_array($b, $valid, true)) {
                Db::insert('user_scopes', ['user_id' => $userId, 'kind' => 'client', 'ref' => (string) $b]);
            }
        }
    }

    /** Cliente dueño de una marca: debe existir, ser de tipo "cliente" y no ser la misma marca. */
    public static function validParent(mixed $parentId, int $selfId = 0): ?int
    {
        $pid = (int) $parentId;
        if ($pid <= 0 || $pid === $selfId) {
            return null;
        }
        return Db::one("SELECT id FROM clients WHERE id = ? AND kind <> 'marca'", [$pid]) ? $pid : null;
    }

    public static function setParent(int $brandId, mixed $parentId): void
    {
        self::find($brandId);
        Db::exec('UPDATE clients SET parent_id = ? WHERE id = ?', [self::validParent($parentId, $brandId), $brandId]);
    }

    /** Clientes de tipo "cliente" (para elegir a quién pertenece cada marca). */
    public static function parents(): array
    {
        return array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name']], Db::all("SELECT id, name FROM clients WHERE kind <> 'marca' ORDER BY name"));
    }

    /**
     * Mapa de marcas por cuenta publicitaria: cada cuenta es de una marca, de ninguna,
     * o compartida (cada campaña se asigna a una marca por su ID).
     */
    public static function brandMap(): array
    {
        $brands = array_map(fn ($b) => ['id' => (int) $b['id'], 'name' => $b['name'], 'color' => $b['color'], 'parent_id' => $b['parent_id'] !== null ? (int) $b['parent_id'] : null],
            Db::all("SELECT id, name, color, parent_id FROM clients WHERE kind = 'marca' ORDER BY name"));
        $brandIds = array_column($brands, 'id');
        $accounts = [];
        foreach (Assets::list('ad_account') as $a) {
            $acc = $a['external_id'];
            $full = array_map('intval', array_column(Db::all("SELECT client_id FROM client_assets WHERE asset_type = 'ad_account' AND external_id = ?", [$acc]), 'client_id'));
            $fullBrand = array_values(array_intersect($full, $brandIds))[0] ?? null;
            $ruled = $brandIds ? (int) Db::one('SELECT COUNT(*) AS n FROM client_campaign_rules WHERE account_id = ? AND client_id IN (' . implode(',', $brandIds) . ')', [$acc])['n'] : 0;
            $mode = $fullBrand ? 'single' : ($ruled ? 'multi' : 'none');
            $suggest = null;
            if ($mode === 'none') { // la cuenta se llama como una marca (ej. "Osacra")
                foreach ($brands as $b) {
                    if (str_contains(SalesImport::normName($a['name']), SalesImport::normName($b['name']))) {
                        $suggest = $b['id'];
                        break;
                    }
                }
            }
            $accounts[] = [
                'id' => $acc, 'name' => $a['name'], 'business' => $a['data']['business']['name'] ?? null, 'currency' => $a['data']['currency'] ?? 'ARS',
                'status' => (int) ($a['data']['account_status'] ?? 1), 'lifetime' => ($a['data']['amount_spent'] ?? 0) / 100,
                'mode' => $mode, 'brand_id' => $fullBrand, 'suggest' => $suggest,
                'clients' => array_column(Db::all("SELECT c.name FROM client_assets x JOIN clients c ON c.id = x.client_id WHERE x.asset_type = 'ad_account' AND x.external_id = ? AND c.kind <> 'marca'", [$acc]), 'name'),
            ];
        }
        // Primero las que ya tienen marca (o sugerencia), después el resto por nombre.
        usort($accounts, fn ($x, $y) => [($x['mode'] === 'none' && !$x['suggest']), $x['name']] <=> [($y['mode'] === 'none' && !$y['suggest']), $y['name']]);
        $others = array_column(Db::all("SELECT name FROM clients WHERE kind <> 'marca' ORDER BY name"), 'name');
        return ['brands' => $brands, 'accounts' => $accounts, 'clients' => $others, 'parents' => self::parents()];
    }

    /** Campañas con actividad (90 días) de una cuenta y la marca asignada a cada una. */
    public static function brandMapCampaigns(string $acc): array
    {
        $brandIds = array_map('intval', array_column(Db::all("SELECT id FROM clients WHERE kind = 'marca'"), 'id'));
        $camps = [];
        foreach (Ads::campaigns($acc, \App\DateRange::fromRequest(['preset' => 'last_90d'])) as $c) {
            if ($c['effective_status'] === 'ACTIVE' || $c['metrics']['spend'] > 0) {
                $camps[] = ['id' => (string) $c['id'], 'name' => $c['name'], 'status' => $c['effective_status'], 'spend' => $c['metrics']['spend']];
            }
        }
        usort($camps, fn ($x, $y) => ($y['status'] === 'ACTIVE') <=> ($x['status'] === 'ACTIVE') ?: $y['spend'] <=> $x['spend']);
        $byCamp = [];
        foreach ($brandIds as $bid) {
            $patterns = self::rules($bid)[$acc] ?? null;
            foreach ($patterns ? $camps : [] as $c) {
                if (!isset($byCamp[$c['id']]) && self::matches($patterns, $c['id'], $c['name'])) {
                    $byCamp[$c['id']] = $bid;
                }
            }
        }
        return ['campaigns' => $camps, 'campaign_brand' => (object) $byCamp];
    }

    /**
     * Guarda el mapa. Sólo toca las asignaciones de cuentas de las MARCAS; las de los
     * clientes (ej. PayLab Gestión con su cuenta entera) quedan como estaban.
     * $accounts: [['account' => 'act_1', 'mode' => 'single|multi|none', 'brand_id' => 3, 'campaigns' => ['123' => 3]], …]
     */
    public static function saveBrandMap(array $accounts): void
    {
        $brandIds = array_map('intval', array_column(Db::all("SELECT id FROM clients WHERE kind = 'marca'"), 'id'));
        if (!$brandIds) {
            throw new InvalidArgumentException('Primero agregá tus marcas.');
        }
        $ph = implode(',', array_fill(0, count($brandIds), '?'));
        foreach ($accounts as $a) {
            $acc = (string) ($a['account'] ?? '');
            if (!preg_match('/^act_\d+$/', $acc) || !Db::one("SELECT id FROM assets WHERE type = 'ad_account' AND external_id = ?", [$acc])) {
                continue;
            }
            Db::exec("DELETE FROM client_assets WHERE asset_type = 'ad_account' AND external_id = ? AND client_id IN ($ph)", [$acc, ...$brandIds]);
            Db::exec("DELETE FROM client_campaign_rules WHERE account_id = ? AND client_id IN ($ph)", [$acc, ...$brandIds]);
            $mode = (string) ($a['mode'] ?? 'none');
            if ($mode === 'single' && in_array((int) ($a['brand_id'] ?? 0), $brandIds, true)) {
                Db::insert('client_assets', ['client_id' => (int) $a['brand_id'], 'asset_type' => 'ad_account', 'external_id' => $acc]);
            } elseif ($mode === 'multi') {
                foreach ((array) ($a['campaigns'] ?? []) as $cid => $bid) {
                    if (preg_match('/^\d+$/', (string) $cid) && in_array((int) $bid, $brandIds, true)) {
                        Db::insert('client_campaign_rules', ['client_id' => (int) $bid, 'account_id' => $acc, 'pattern' => (string) $cid, 'created_at' => Db::now()]);
                    }
                }
            }
        }
        Cache::flush();
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
