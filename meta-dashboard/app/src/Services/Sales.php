<?php
declare(strict_types=1);

namespace App\Services;

use App\DateRange;
use App\Db;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Ventas / conversiones propias del cliente (independientes de Meta).
 * Sirven para clientes sin campañas (ej. VTV) y, si hay campañas,
 * para calcular el costo por venta y el ROAS reales.
 */
final class Sales
{
    private const UPLOAD_DIR = APP_ROOT . '/storage/uploads';
    private const AUTO_SYNC_MINUTES = 20;

    // ---------------------------------------------------------------------
    // Fuentes
    // ---------------------------------------------------------------------
    public static function sources(int $clientId): array
    {
        return array_map(function ($s) {
            $s['id'] = (int) $s['id'];
            $s['mapping'] = $s['mapping_json'] ? json_decode($s['mapping_json'], true) : null;
            unset($s['mapping_json']);
            $range = Db::one('SELECT MIN(day) AS min_day, MAX(day) AS max_day FROM sales_daily WHERE source_id = ?', [$s['id']]);
            return $s + $range;
        }, Db::all('SELECT * FROM sales_sources WHERE client_id = ? ORDER BY id', [$clientId]));
    }

    /** Paso 1: guarda el archivo (o descarga la planilla) y devuelve columnas + muestra + mapeo sugerido. */
    public static function preview(?array $file, ?string $url): array
    {
        self::cleanupUploads();
        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0750, true);
        }
        $token = bin2hex(random_bytes(12));
        if ($url) {
            $table = SalesImport::readGoogleSheet($url);
            file_put_contents(self::UPLOAD_DIR . "/$token.json", json_encode($table));
        } else {
            if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('No se recibió el archivo (máximo ' . ini_get('upload_max_filesize') . ').');
            }
            if ($file['size'] > 15 * 1024 * 1024) {
                throw new InvalidArgumentException('El archivo supera los 15 MB.');
            }
            $table = SalesImport::readFile($file['tmp_name'], (string) $file['name']);
            file_put_contents(self::UPLOAD_DIR . "/$token.json", json_encode($table));
        }
        $suggest = SalesImport::suggest($table['headers']);
        $agg = $suggest['date'] !== null ? SalesImport::aggregate($table['rows'], $suggest) : null;
        return [
            'token'    => $token,
            'headers'  => $table['headers'],
            'sample'   => array_slice($table['rows'], 0, 8),
            'total'    => count($table['rows']),
            'suggest'  => $suggest,
            'detected' => $agg ? ['days' => count(array_unique(array_column($agg['days'], 'day'))), 'min' => $agg['min'], 'max' => $agg['max']] : null,
        ];
    }

    /** Paso 2: importa con el mapeo elegido. Crea o actualiza la fuente. */
    public static function import(int $clientId, array $d): array
    {
        Clients::find($clientId);
        $token = preg_replace('/[^a-f0-9]/', '', (string) ($d['token'] ?? ''));
        $path = self::UPLOAD_DIR . "/$token.json";
        if ($token === '' || !is_file($path)) {
            throw new InvalidArgumentException('La vista previa venció. Volvé a subir el archivo.');
        }
        $table = json_decode((string) file_get_contents($path), true);
        $map = self::cleanMap($d['mapping'] ?? []);
        $agg = SalesImport::aggregate($table['rows'], $map);
        if (!$agg['days']) {
            throw new InvalidArgumentException('No encontramos fechas válidas en la columna elegida.');
        }

        $kind = ($d['kind'] ?? '') === 'gsheet' ? 'gsheet' : 'upload';
        $url = $kind === 'gsheet' ? SalesImport::googleCsvUrl((string) ($d['url'] ?? '')) : null;
        $sourceId = (int) ($d['source_id'] ?? 0);
        $name = trim((string) ($d['name'] ?? '')) ?: ($kind === 'gsheet' ? 'Google Sheets' : 'Planilla de ventas');
        if ($sourceId) {
            $src = Db::one('SELECT * FROM sales_sources WHERE id = ? AND client_id = ?', [$sourceId, $clientId]);
            if (!$src) {
                throw new InvalidArgumentException('Fuente inexistente.');
            }
            Db::exec('UPDATE sales_sources SET name = ?, mapping_json = ?, url = COALESCE(?, url) WHERE id = ?', [$name, json_encode($map), $url, $sourceId]);
        } else {
            $sourceId = Db::insert('sales_sources', [
                'client_id' => $clientId, 'name' => mb_substr($name, 0, 190), 'kind' => $kind, 'url' => $url,
                'mapping_json' => json_encode($map), 'created_at' => Db::now(),
            ]);
        }
        // Planilla completa (Sheets) reemplaza todo; un archivo reemplaza sólo las fechas que trae.
        self::replaceRows($clientId, $sourceId, $agg, $kind === 'gsheet');
        @unlink($path);
        return ['source_id' => $sourceId, 'days' => count(array_unique(array_column($agg['days'], 'day'))), 'skipped' => $agg['skipped'], 'min' => $agg['min'], 'max' => $agg['max']];
    }

    public static function sync(int $sourceId): array
    {
        $src = Db::one("SELECT * FROM sales_sources WHERE id = ? AND kind = 'gsheet'", [$sourceId]);
        if (!$src) {
            throw new InvalidArgumentException('Sólo se pueden sincronizar fuentes de Google Sheets.');
        }
        try {
            $table = SalesImport::readGoogleSheet((string) $src['url']);
            $agg = SalesImport::aggregate($table['rows'], json_decode((string) $src['mapping_json'], true) ?: []);
            self::replaceRows((int) $src['client_id'], $sourceId, $agg, true);
            return ['days' => count($agg['days']), 'max' => $agg['max']];
        } catch (\Throwable $e) {
            Db::exec('UPDATE sales_sources SET last_error = ?, last_sync_at = ? WHERE id = ?', [$e->getMessage(), Db::now(), $sourceId]);
            throw $e;
        }
    }

    public static function syncAll(): array
    {
        $out = [];
        foreach (Db::all("SELECT id, name FROM sales_sources WHERE kind = 'gsheet'") as $s) {
            try {
                $out[$s['name']] = self::sync((int) $s['id']);
            } catch (\Throwable $e) {
                $out[$s['name']] = ['error' => $e->getMessage()];
            }
        }
        return $out;
    }

    /** Carga manual de un día (reemplaza el valor de ese día). */
    public static function entry(int $clientId, array $d): void
    {
        Clients::find($clientId);
        $day = SalesImport::parseDate($d['day'] ?? '');
        if (!$day || $day > date('Y-m-d')) {
            throw new InvalidArgumentException('Fecha inválida.');
        }
        $src = Db::one("SELECT id FROM sales_sources WHERE client_id = ? AND kind = 'manual'", [$clientId]);
        $sourceId = $src ? (int) $src['id'] : Db::insert('sales_sources', ['client_id' => $clientId, 'name' => 'Carga manual', 'kind' => 'manual', 'created_at' => Db::now()]);
        $leads = trim((string) ($d['leads'] ?? '')) === '' ? null : SalesImport::parseNumber($d['leads']);
        Db::upsert('sales_daily', [
            'client_id' => $clientId, 'source_id' => $sourceId, 'day' => $day, 'dim' => mb_substr(trim((string) ($d['dim'] ?? '')), 0, 120),
            'orders' => SalesImport::parseNumber($d['orders'] ?? 0) ?? 0, 'revenue' => SalesImport::parseNumber($d['revenue'] ?? 0) ?? 0, 'leads' => $leads,
        ], ['source_id', 'day', 'dim']);
        self::touch($sourceId);
    }

    public static function deleteSource(int $id): void
    {
        Db::exec('DELETE FROM sales_daily WHERE source_id = ?', [$id]);
        Db::exec('DELETE FROM sales_sources WHERE id = ?', [$id]);
    }

    /** Últimos días cargados (para revisar/corregir desde el admin). */
    public static function recent(int $clientId, int $limit = 60): array
    {
        return Db::all('SELECT d.day, d.dim, d.orders, d.revenue, d.leads, s.name AS source FROM sales_daily d JOIN sales_sources s ON s.id = d.source_id
                        WHERE d.client_id = ? ORDER BY d.day DESC, d.dim LIMIT ' . max(1, min(500, $limit)), [$clientId]);
    }

    public static function hasData(int $clientId): bool
    {
        return (bool) Db::one('SELECT 1 AS x FROM sales_daily WHERE client_id = ? LIMIT 1', [$clientId]);
    }

    // ---------------------------------------------------------------------
    // Tablero
    // ---------------------------------------------------------------------
    public static function summary(int $clientId, DateRange $range): array
    {
        self::autoSync($clientId);
        $client = Clients::find($clientId);
        $prev = $range->previous();
        [$since, $until] = [$range->since->format('Y-m-d'), $range->until->format('Y-m-d')];

        $lastDay = Db::one('SELECT MAX(day) AS d FROM sales_daily WHERE client_id = ?', [$clientId])['d'] ?? null;
        $byDay = [];
        foreach (Db::all('SELECT day, SUM(orders) AS orders, SUM(revenue) AS revenue, SUM(leads) AS leads, COUNT(leads) AS has_leads
                          FROM sales_daily WHERE client_id = ? AND day BETWEEN ? AND ? GROUP BY day', [$clientId, $since, $until]) as $r) {
            $byDay[$r['day']] = $r;
        }

        // Serie diaria completa: 0 para días sin ventas, null después del último día cargado.
        $daily = [];
        for ($d = $range->since; $d <= $range->until; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $row = $byDay[$k] ?? null;
            $future = $lastDay === null || $k > $lastDay;
            $daily[] = [
                'date'    => $k,
                'orders'  => $row ? (float) $row['orders'] : ($future ? null : 0.0),
                'revenue' => $row ? (float) $row['revenue'] : ($future ? null : 0.0),
                'leads'   => $row && $row['has_leads'] ? (float) $row['leads'] : null,
            ];
        }

        $cur = self::totals($clientId, $since, min($until, $lastDay ?? $until));
        // Comparamos la misma cantidad de días cargados para no castigar días sin cargar.
        $loadedDays = $lastDay && $lastDay >= $since ? (new DateTimeImmutable($since))->diff(new DateTimeImmutable(min($until, $lastDay)))->days + 1 : $range->days();
        $prevUntil = $prev->since->modify('+' . ($loadedDays - 1) . ' days')->format('Y-m-d');
        $previous = self::totals($clientId, $prev->since->format('Y-m-d'), $prevUntil);

        $dims = array_map(fn ($r) => [
            'label' => $r['dim'], 'orders' => (float) $r['orders'], 'revenue' => (float) $r['revenue'],
            'leads' => $r['leads'] !== null ? (float) $r['leads'] : null,
        ], Db::all("SELECT dim, SUM(orders) AS orders, SUM(revenue) AS revenue, SUM(leads) AS leads FROM sales_daily
                    WHERE client_id = ? AND day BETWEEN ? AND ? AND dim <> '' GROUP BY dim ORDER BY SUM(orders) DESC LIMIT 25", [$clientId, $since, $until]));

        // Promedio por día de la semana (últimos 90 días con datos).
        $wd = array_fill(0, 7, ['orders' => 0.0, 'revenue' => 0.0, 'n' => 0]);
        if ($lastDay) {
            $from = (new DateTimeImmutable($lastDay))->modify('-89 days')->format('Y-m-d');
            $map = [];
            foreach (Db::all('SELECT day, SUM(orders) AS o, SUM(revenue) AS r FROM sales_daily WHERE client_id = ? AND day BETWEEN ? AND ? GROUP BY day', [$clientId, $from, $lastDay]) as $r) {
                $map[$r['day']] = $r;
            }
            for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $lastDay; $d = $d->modify('+1 day')) {
                $i = (int) $d->format('N') - 1;
                $r = $map[$d->format('Y-m-d')] ?? null;
                $wd[$i]['orders'] += $r ? (float) $r['o'] : 0;
                $wd[$i]['revenue'] += $r ? (float) $r['r'] : 0;
                $wd[$i]['n']++;
            }
        }
        $weekday = array_map(fn ($w, $i) => [
            'label' => ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'][$i],
            'orders' => $w['n'] ? $w['orders'] / $w['n'] : 0, 'revenue' => $w['n'] ? $w['revenue'] / $w['n'] : 0,
        ], $wd, array_keys($wd));

        return [
            'labels'     => ['sales' => $client['sales_label'], 'leads' => $client['leads_label'], 'dim' => $client['dim_label']],
            'currency'   => $client['currency'],
            'range'      => $range->toArray(),
            'last_day'   => $lastDay,
            'has_data'   => $lastDay !== null,
            'has_leads'  => (bool) array_filter($daily, fn ($d) => $d['leads'] !== null),
            'totals'     => $cur,
            'previous'   => $previous,
            'compared_days' => $loadedDays,
            'daily'      => $daily,
            'dims'       => $dims,
            'weekday'    => $weekday,
            'month'      => self::monthProjection($clientId, $lastDay, $client),
            'last'       => $lastDay ? self::totals($clientId, $lastDay, $lastDay) + ['day' => $lastDay] : null,
            'ads'        => self::adsCrossover($clientId, $range, $cur),
            'sources'    => array_map(fn ($s) => ['name' => $s['name'], 'kind' => $s['kind'], 'last_sync_at' => $s['last_sync_at'], 'last_error' => $s['last_error']], self::sources($clientId)),
        ];
    }

    private static function totals(int $clientId, string $since, string $until): array
    {
        $r = Db::one('SELECT SUM(orders) AS orders, SUM(revenue) AS revenue, SUM(leads) AS leads, COUNT(DISTINCT day) AS days
                      FROM sales_daily WHERE client_id = ? AND day BETWEEN ? AND ?', [$clientId, $since, $until]);
        $orders = (float) ($r['orders'] ?? 0);
        $revenue = (float) ($r['revenue'] ?? 0);
        $leads = $r['leads'] !== null ? (float) $r['leads'] : null;
        $days = max(1, (new DateTimeImmutable($since))->diff(new DateTimeImmutable(max($since, $until)))->days + 1);
        return [
            'orders'       => $orders,
            'revenue'      => $revenue,
            'leads'        => $leads,
            'avg_ticket'   => $orders > 0 && $revenue > 0 ? $revenue / $orders : null,
            'conv_rate'    => $leads ? $orders / $leads * 100 : null,
            'per_day'      => $orders / $days,
            'revenue_day'  => $revenue / $days,
            'days_with_data' => (int) ($r['days'] ?? 0),
        ];
    }

    /** Mes en curso: acumulado, proyección lineal al cierre y avance contra objetivo. */
    private static function monthProjection(int $clientId, ?string $lastDay, array $client): ?array
    {
        if (!$lastDay) {
            return null;
        }
        $ref = new DateTimeImmutable(min($lastDay, date('Y-m-d')));
        $start = $ref->modify('first day of this month');
        $daysInMonth = (int) $ref->format('t');
        $elapsed = (int) $ref->format('j');
        $mtd = self::totals($clientId, $start->format('Y-m-d'), $ref->format('Y-m-d'));
        $prevMonthStart = $start->modify('-1 month');
        $prevSame = self::totals($clientId, $prevMonthStart->format('Y-m-d'), $prevMonthStart->modify('+' . ($elapsed - 1) . ' days')->format('Y-m-d'));
        $prevFull = self::totals($clientId, $prevMonthStart->format('Y-m-d'), $start->modify('-1 day')->format('Y-m-d'));
        $proj = fn ($v) => $elapsed ? $v / $elapsed * $daysInMonth : 0;
        $tOrders = $client['target_orders'] !== null ? (float) $client['target_orders'] : null;
        $tRevenue = $client['target_revenue'] !== null ? (float) $client['target_revenue'] : null;
        return [
            'month'             => $ref->format('Y-m'),
            'through'           => $ref->format('Y-m-d'),
            'elapsed'           => $elapsed,
            'days_in_month'     => $daysInMonth,
            'orders'            => $mtd['orders'],
            'revenue'           => $mtd['revenue'],
            'projected_orders'  => $proj($mtd['orders']),
            'projected_revenue' => $proj($mtd['revenue']),
            'prev_same_orders'  => $prevSame['orders'],
            'prev_same_revenue' => $prevSame['revenue'],
            'prev_month_orders' => $prevFull['orders'],
            'prev_month_revenue' => $prevFull['revenue'],
            'target_orders'     => $tOrders,
            'target_revenue'    => $tRevenue,
            // Ritmo diario necesario para llegar al objetivo.
            'needed_per_day'    => $tOrders !== null && $daysInMonth > $elapsed ? max(0, ($tOrders - $mtd['orders']) / ($daysInMonth - $elapsed)) : null,
        ];
    }

    /** Si el cliente tiene campañas: costo por venta y ROAS con ventas reales. */
    private static function adsCrossover(int $clientId, DateRange $range, array $cur): ?array
    {
        $accounts = Clients::allowed($clientId)['ad_account'];
        if (!$accounts) {
            return null;
        }
        try {
            $ov = Ads::overview($range, false, $accounts);
        } catch (\Throwable) {
            return null;
        }
        $t = $ov['totals'][0] ?? null;
        if (!$t) {
            return null;
        }
        $spend = $t['metrics']['spend'];
        $spendDaily = [];
        foreach ($ov['daily'][$t['currency']] ?? [] as $day => $v) {
            $spendDaily[$day] = $v['spend'];
        }
        return [
            'currency'         => $t['currency'],
            'spend'            => $spend,
            'cost_per_order'   => $cur['orders'] > 0 ? $spend / $cur['orders'] : null,
            'roas_real'        => $spend > 0 && $cur['revenue'] > 0 ? $cur['revenue'] / $spend : null,
            'meta_results'     => $t['metrics']['purchases'] ?: $t['metrics']['results'],
            'meta_share'       => $cur['orders'] > 0 ? min(100, ($t['metrics']['purchases'] ?: $t['metrics']['results']) / $cur['orders'] * 100) : null,
            'spend_daily'      => $spendDaily,
        ];
    }

    // ---------------------------------------------------------------------
    // Internos
    // ---------------------------------------------------------------------
    private static function replaceRows(int $clientId, int $sourceId, array $agg, bool $all): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if ($all) {
                Db::exec('DELETE FROM sales_daily WHERE source_id = ?', [$sourceId]);
            } elseif ($agg['min']) {
                Db::exec('DELETE FROM sales_daily WHERE source_id = ? AND day BETWEEN ? AND ?', [$sourceId, $agg['min'], $agg['max']]);
            }
            foreach ($agg['days'] as $d) {
                Db::insert('sales_daily', [
                    'client_id' => $clientId, 'source_id' => $sourceId, 'day' => $d['day'], 'dim' => $d['dim'],
                    'orders' => round($d['orders'], 2), 'revenue' => round($d['revenue'], 2), 'leads' => $d['leads'] !== null ? round($d['leads'], 2) : null,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::touch($sourceId);
    }

    private static function touch(int $sourceId): void
    {
        $n = (int) Db::one('SELECT COUNT(*) AS n FROM sales_daily WHERE source_id = ?', [$sourceId])['n'];
        Db::exec('UPDATE sales_sources SET rows_count = ?, last_sync_at = ?, last_error = NULL WHERE id = ?', [$n, Db::now(), $sourceId]);
    }

    private static function cleanMap(array $m): array
    {
        $out = [];
        foreach (array_keys(SalesImport::FIELDS) as $f) {
            $out[$f] = isset($m[$f]) && $m[$f] !== '' && $m[$f] !== null ? (int) $m[$f] : null;
        }
        return $out;
    }

    /** Google Sheets se resincroniza solo al abrir el tablero si pasaron más de 20 minutos. */
    private static function autoSync(int $clientId): void
    {
        $limit = date('Y-m-d H:i:s', time() - self::AUTO_SYNC_MINUTES * 60);
        foreach (Db::all("SELECT id FROM sales_sources WHERE client_id = ? AND kind = 'gsheet' AND (last_sync_at IS NULL OR last_sync_at < ?)", [$clientId, $limit]) as $s) {
            try {
                self::sync((int) $s['id']);
            } catch (\Throwable) {
                // Queda registrado en last_error; se muestran los últimos datos.
            }
        }
    }

    private static function cleanupUploads(): void
    {
        foreach (glob(self::UPLOAD_DIR . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }
}
