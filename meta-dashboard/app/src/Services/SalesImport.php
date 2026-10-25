<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use ZipArchive;

/**
 * Lee planillas de ventas (CSV, XLSX o Google Sheets publicado) y las
 * convierte en filas diarias. Acepta tanto una fila por día (totales)
 * como una fila por venta/turno (se cuentan las filas).
 */
final class SalesImport
{
    /** Campos que se pueden mapear y palabras con las que se los reconoce. */
    public const FIELDS = [
        'date'    => ['fecha', 'dia', 'día', 'date', 'day', 'fecha de venta', 'fecha turno', 'fecha de verificacion'],
        'orders'  => ['ventas', 'cantidad', 'cant', 'operaciones', 'conversiones', 'pedidos', 'ordenes', 'órdenes', 'turnos', 'verificaciones', 'unidades', 'orders', 'sales', 'qty'],
        'revenue' => ['facturacion', 'facturación', 'monto', 'importe', 'total', 'ingresos', 'revenue', 'recaudacion', 'recaudación', 'neto', 'valor'],
        'leads'   => ['consultas', 'leads', 'contactos', 'visitas', 'mensajes', 'solicitudes', 'reservas', 'turnos solicitados', 'interacciones', 'interacc', 'conversaciones'],
        'spend'   => ['costo campaña', 'costo campana', 'costo de campaña', 'inversion', 'inversión', 'gasto', 'spend', 'importe gastado'],
        'campaign' => ['campaña', 'campana', 'campaign', 'nombre de campaña', 'campaña meta', 'anuncio'],
        'dim'     => ['sucursal', 'planta', 'canal', 'origen', 'producto', 'servicio', 'local', 'sede', 'categoria', 'categoría', 'medio'],
    ];

    /** Lee un archivo subido o una URL y devuelve ['headers' => [...], 'rows' => [[...], ...]]. */
    public static function readFile(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            return self::combine(self::readXlsxSheets($path));
        }
        if (in_array($ext, ['csv', 'txt', 'tsv'], true)) {
            return self::table(self::readCsv((string) file_get_contents($path)));
        }
        if ($ext === 'xls') {
            throw new InvalidArgumentException('El formato .xls (Excel 97) no es compatible. Guardalo como .xlsx o .csv.');
        }
        throw new InvalidArgumentException('Subí un archivo .xlsx o .csv.');
    }

    /**
     * Descarga un Google Sheets. Lee el libro COMPLETO (todas las pestañas, ej. una por mes)
     * como Excel; si Google no lo permite, cae a la pestaña del link como CSV.
     * Acepta el link de edición o el de "Publicar en la web".
     */
    public static function readGoogleSheet(string $url): array
    {
        $csvUrl = self::googleCsvUrl($url);
        $xlsxUrl = preg_replace(['#/export\?format=csv(&gid=\d+)?#', '#/pub\?output=csv(&gid=\d+)?#'], ['/export?format=xlsx', '/pub?output=xlsx'], $csvUrl);
        [$body, $status, $type] = self::download($xlsxUrl);
        if (is_string($body) && $status < 400 && str_starts_with($body, "PK")) {
            $tmp = tempnam(sys_get_temp_dir(), 'gsx');
            file_put_contents($tmp, $body);
            try {
                return self::combine(self::readXlsxSheets($tmp));
            } catch (InvalidArgumentException) {
                // se intenta como CSV
            } finally {
                @unlink($tmp);
            }
        }
        [$body, $status, $type] = self::download($csvUrl);
        if (!is_string($body) || $status >= 400 || str_contains($type, 'text/html')) {
            throw new InvalidArgumentException('No pudimos leer la planilla. En Google Sheets: Compartir → Acceso general → "Cualquier persona con el enlace" → Lector.');
        }
        return self::table(self::readCsv($body));
    }

    /**
     * Libro de Google tal cual, para mostrarlo (no para importar): [['name' => pestaña, 'rows' => matriz], …].
     * Recorta filas y columnas vacías.
     */
    public static function readGoogleRaw(string $url, int $maxRows = 3000, int $maxCols = 60): array
    {
        $csvUrl = self::googleCsvUrl($url);
        $xlsxUrl = preg_replace(['#/export\?format=csv(&gid=\d+)?#', '#/pub\?output=csv(&gid=\d+)?#'], ['/export?format=xlsx', '/pub?output=xlsx'], $csvUrl);
        $sheets = null;
        [$body, $status] = self::download($xlsxUrl);
        if (is_string($body) && $status < 400 && str_starts_with($body, "PK")) {
            $tmp = tempnam(sys_get_temp_dir(), 'gsx');
            file_put_contents($tmp, $body);
            try {
                $sheets = self::readXlsxSheets($tmp);
            } catch (InvalidArgumentException) {
            } finally {
                @unlink($tmp);
            }
        }
        if ($sheets === null) {
            [$body, $status, $type] = self::download($csvUrl);
            if (!is_string($body) || $status >= 400 || str_contains($type, 'text/html')) {
                throw new InvalidArgumentException('No pudimos leer la planilla. En Google Sheets: Compartir → Acceso general → "Cualquier persona con el enlace" → Lector.');
            }
            $sheets = ['Hoja 1' => self::readCsv($body)];
        }
        $out = [];
        foreach ($sheets as $name => $m) {
            $m = array_values(array_filter($m, fn ($r) => count(array_filter($r, fn ($c) => trim((string) $c) !== '')) > 0));
            $m = array_slice($m, 0, $maxRows);
            $width = 0;
            foreach ($m as $r) {
                foreach ($r as $i => $c) {
                    if (trim((string) $c) !== '') {
                        $width = max($width, $i + 1);
                    }
                }
            }
            $width = min($width, $maxCols);
            $out[] = ['name' => (string) $name, 'rows' => array_map(fn ($r) => array_map(fn ($i) => (string) ($r[$i] ?? ''), $width ? range(0, $width - 1) : []), $m)];
        }
        return $out;
    }

    /** @return array{0: string|false, 1: int, 2: string} */
    private static function download(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return [$body, $status, $type];
    }

    /**
     * Une las pestañas de un libro: la primera con datos define las columnas y las demás
     * se alinean por el nombre del encabezado (así una columna movida en otro mes no rompe nada).
     * @param array<string, array> $sheets nombre => matriz
     */
    private static function combine(array $sheets): array
    {
        $tables = [];
        foreach ($sheets as $name => $matrix) {
            try {
                $tables[$name] = self::table($matrix);
            } catch (InvalidArgumentException) {
                // pestaña vacía
            }
        }
        if (!$tables) {
            throw new InvalidArgumentException('La planilla está vacía.');
        }
        // La pestaña modelo es la que más se parece a una carga diaria (fecha + ventas),
        // aunque no sea la primera (ej. un "Resumen" adelante).
        $score = function (array $t): int {
            $s = self::suggest($t['headers'], $t['rows']);
            if ($s['date'] === null) {
                return 0;
            }
            $dated = count(array_filter($t['rows'], fn ($r) => self::parseDate($r[$s['date']] ?? null) !== null));
            return $dated * 2 + ($s['orders'] !== null ? 1000 : 0);
        };
        uasort($tables, fn ($a, $b) => $score($b) <=> $score($a));
        $template = reset($tables);
        $headers = $template['headers'];
        $generic = fn ($h) => (bool) preg_match('/^Columna \d+$/', $h);
        $key = fn ($h) => self::normName($h);
        $tabs = [];
        $all = [];
        foreach ($tables as $name => $t) {
            $index = [];
            foreach ($t['headers'] as $j => $h) {
                if (!$generic($h)) {
                    $index[$key($h)] ??= $j;
                }
            }
            $pos = [];
            foreach ($headers as $i => $h) {
                $pos[$i] = $generic($h) ? $i : ($index[$key($h)] ?? (isset($t['headers'][$i]) && $generic($t['headers'][$i]) ? $i : null));
            }
            $rows = array_map(fn ($r) => array_map(fn ($j) => $j === null ? '' : ($r[$j] ?? ''), $pos), $t['rows']);
            $tabs[] = ['name' => (string) $name, 'rows' => $rows];
            array_push($all, ...$rows);
        }
        return ['headers' => $headers, 'rows' => $all, 'tabs' => $tabs];
    }

    /** Agrupa por día una tabla (con todas sus pestañas). Si un día aparece en dos pestañas, gana la que tiene datos. */
    public static function aggregateTable(array $table, array $map): array
    {
        if (empty($table['tabs']) || count($table['tabs']) === 1) {
            return self::aggregate($table['rows'], $map);
        }
        $days = [];
        $skipped = 0;
        foreach ($table['tabs'] as $tab) {
            $agg = self::aggregate($tab['rows'], $map);
            $skipped += $agg['skipped'];
            foreach ($agg['days'] as $d) {
                $k = $d['day'] . '|' . $d['dim'] . '|' . $d['campaign'];
                if (!isset($days[$k]) || $d['orders'] + $d['revenue'] > $days[$k]['orders'] + $days[$k]['revenue']) {
                    $days[$k] = $d;
                }
            }
        }
        $dates = array_column($days, 'day');
        return ['days' => array_values($days), 'skipped' => $skipped, 'min' => $dates ? min($dates) : null, 'max' => $dates ? max($dates) : null];
    }

    public static function googleCsvUrl(string $url): string
    {
        $url = trim($url);
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host !== 'docs.google.com') {
            throw new InvalidArgumentException('Pegá un link de Google Sheets (https://docs.google.com/spreadsheets/…).');
        }
        if (preg_match('#/spreadsheets/d/e/([^/]+)/#', $url, $m)) {
            // Link de "Publicar en la web": forzamos salida CSV.
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            return 'https://docs.google.com/spreadsheets/d/e/' . $m[1] . '/pub?output=csv' . (isset($q['gid']) ? '&gid=' . (int) $q['gid'] : '');
        }
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            $gid = preg_match('/[#&?]gid=(\d+)/', $url, $g) ? $g[1] : '0';
            return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv&gid=' . $gid;
        }
        throw new InvalidArgumentException('No reconocimos el link de Google Sheets.');
    }

    /** Normaliza nombres de campaña para cruzarlos con Meta (sin tildes, mayúsculas ni espacios dobles). */
    public static function normName(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return trim(preg_replace('/[\s\-_|·]+/u', ' ', $s));
    }

    /**
     * Sugiere qué columna corresponde a cada campo. Gana la palabra más específica
     * (ej. "Ventas" antes que "Cant."), se ignoran columnas de costo, inversión,
     * interacciones o impuestos, y si la fecha no tiene título se busca por el contenido.
     */
    public static function suggest(array $headers, array $rows = []): array
    {
        $norm = fn ($s) => trim(preg_replace('/\s+/', ' ', strtolower(strtr((string) $s, 'áéíóúÁÉÍÓÚñÑ', 'aeiouAEIOUnN'))));
        $ratio = ['cpl', 'cpa', 'ctr', 'cpm', '%', 'promedio', 'prom ', ' x ', 'por ', 'costo x', 'costo por'];
        // Qué palabras descartan una columna para cada campo.
        $noise = [
            'orders' => ['costo', 'inversion', 'gasto', 'presupuesto', 'interacc', 'impuesto', ...$ratio],
            'revenue' => ['costo', 'inversion', 'gasto', 'presupuesto', 'interacc', 'impuesto', ...$ratio],
            'leads' => ['costo', 'inversion', 'gasto', 'impuesto', ...$ratio],
            'spend' => ['impuesto', 'presupuesto', 'total gastado', ...$ratio],
            'campaign' => ['costo', 'interacc', 'cant'],
            'dim' => [],
        ];
        $multi = ['leads', 'spend']; // se suman todas las columnas que coinciden (ej. una por campaña)
        $out = [];
        $used = [];
        foreach (self::FIELDS as $field => $words) {
            $out[$field] = in_array($field, $multi, true) ? [] : null;
            foreach ($words as $w) {
                foreach ($headers as $i => $h) {
                    $hn = $norm($h);
                    if (in_array($i, $used, true) || ($field !== 'date' && array_filter($noise[$field] ?? [], fn ($x) => str_contains($hn, $x)))) {
                        continue;
                    }
                    if ($hn === $norm($w) || preg_match('/(^|[^a-z])' . preg_quote($norm($w), '/') . '/', $hn)) {
                        $used[] = $i;
                        if (in_array($field, $multi, true)) {
                            $out[$field][] = $i;
                            continue;
                        }
                        $out[$field] = $i;
                        continue 3;
                    }
                }
            }
            if (in_array($field, $multi, true)) {
                $out[$field] = $out[$field] ?: null;
            }
        }
        if ($out['date'] === null && $rows) {
            $best = 0;
            foreach ($headers as $i => $h) {
                $n = count(array_filter(array_slice($rows, 0, 60), fn ($r) => self::parseDate($r[$i] ?? null) !== null));
                if ($n > $best && !in_array($i, $used, true)) {
                    [$best, $out['date']] = [$n, $i];
                }
            }
        }
        return $out;
    }

    /**
     * Agrupa las filas por día (+ dimensión opcional).
     * $map = ['date' => idx, 'orders' => idx|null, 'revenue' => idx|null, 'leads' => idx|null, 'campaign' => idx|null, 'dim' => idx|null]
     * Si no se mapea 'orders', cada fila cuenta como 1.
     * @return array{days: array<string, array>, skipped: int, min: ?string, max: ?string}
     */
    public static function aggregate(array $rows, array $map): array
    {
        if (!isset($map['date']) || $map['date'] === '' || $map['date'] === null) {
            throw new InvalidArgumentException('Elegí qué columna tiene la fecha.');
        }
        $col = fn ($k) => isset($map[$k]) && $map[$k] !== '' && $map[$k] !== null && !is_array($map[$k]) ? (int) $map[$k] : null;
        $cols = fn ($k) => isset($map[$k]) && $map[$k] !== '' && $map[$k] !== null ? array_map('intval', (array) $map[$k]) : [];
        [$d, $o, $r, $dim, $camp] = [$col('date'), $col('orders'), $col('revenue'), $col('dim'), $col('campaign')];
        $l = $cols('leads') ?: null;
        $sp = $cols('spend') ?: null;
        $sum = function (array $row, array $idx): ?float {
            $t = null;
            foreach ($idx as $i) {
                $v = self::parseNumber($row[$i] ?? null);
                if ($v !== null) {
                    $t = ($t ?? 0) + $v;
                }
            }
            return $t;
        };
        $days = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $day = self::parseDate($row[$d] ?? null);
            if (!$day) {
                $skipped++;
                continue;
            }
            if ($o !== null && trim((string) ($row[$o] ?? '')) === '' && ($r === null || trim((string) ($row[$r] ?? '')) === '')
                && ($l === null || $sum($row, $l) === null) && ($sp === null || $sum($row, $sp) === null)) {
                continue; // día todavía sin cargar
            }
            $key = mb_substr(trim((string) ($dim !== null ? ($row[$dim] ?? '') : '')), 0, 120);
            $campaign = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($camp !== null ? ($row[$camp] ?? '') : ''))), 0, 190);
            $agg = &$days[$day . '|' . $key . '|' . $campaign];
            $agg ??= ['day' => $day, 'dim' => $key, 'campaign' => $campaign, 'orders' => 0.0, 'revenue' => 0.0, 'leads' => null, 'spend' => null];
            $agg['orders'] += $o !== null ? (self::parseNumber($row[$o] ?? null) ?? 0) : 1;
            $agg['revenue'] += $r !== null ? (self::parseNumber($row[$r] ?? null) ?? 0) : 0;
            if ($l !== null && ($v = $sum($row, $l)) !== null) {
                $agg['leads'] = ($agg['leads'] ?? 0) + $v;
            }
            if ($sp !== null && ($v = $sum($row, $sp)) !== null) {
                $agg['spend'] = ($agg['spend'] ?? 0) + $v;
            }
            unset($agg);
        }
        $dates = array_column($days, 'day');
        return ['days' => array_values($days), 'skipped' => $skipped, 'min' => $dates ? min($dates) : null, 'max' => $dates ? max($dates) : null];
    }

    /** Fechas: serial de Excel, dd/mm/aaaa, dd-mm-aa, aaaa-mm-dd (con o sin hora). */
    public static function parseDate(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (is_numeric($s) && (float) $s > 20000 && (float) $s < 80000) {
            return gmdate('Y-m-d', (int) round(((float) $s - 25569) * 86400));
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $s, $m)) {
            $y = (int) $m[3];
            $y = $y < 100 ? 2000 + $y : $y;
            [$day, $mon] = [(int) $m[1], (int) $m[2]];
            if ($mon > 12 && $day <= 12) {
                [$day, $mon] = [$mon, $day]; // venía en formato mm/dd
            }
            return checkdate($mon, $day, $y) ? sprintf('%04d-%02d-%02d', $y, $mon, $day) : null;
        }
        return null;
    }

    /** Números en formato argentino (1.234,56) o internacional (1,234.56), con o sin $. */
    public static function parseNumber(mixed $v): ?float
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = preg_replace('/[^\d.,\-]/', '', (string) $v);
        if ($s === '' || $s === '-') {
            return null;
        }
        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $s = $lastComma > $lastDot ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } elseif ($lastComma !== false) {
            $s = preg_match('/^-?\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($lastDot !== false && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s); // 1.234 = mil doscientos treinta y cuatro
        }
        return is_numeric($s) ? (float) $s : null;
    }

    /** Primera fila no vacía = encabezados; el resto, datos. */
    private static function table(array $matrix): array
    {
        $matrix = array_values(array_filter($matrix, fn ($r) => count(array_filter($r, fn ($c) => trim((string) $c) !== '')) > 0));
        if (!$matrix) {
            throw new InvalidArgumentException('La planilla está vacía.');
        }
        $headers = array_map(fn ($h) => trim((string) $h), array_shift($matrix));
        $width = max(count($headers), ...array_map('count', $matrix ?: [[]]));
        foreach ($headers as $i => $h) {
            if ($h === '') {
                $headers[$i] = 'Columna ' . ($i + 1);
            }
        }
        for ($i = count($headers); $i < $width; $i++) {
            $headers[$i] = 'Columna ' . ($i + 1);
        }
        return ['headers' => $headers, 'rows' => $matrix];
    }

    private static function readCsv(string $raw): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($raw, "\n") ?: '';
        $delims = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
        arsort($delims);
        $delim = array_key_first($delims);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $out = [];
        while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $out[] = $row;
            if (count($out) > 200000) {
                break;
            }
        }
        fclose($fh);
        return $out;
    }

    /**
     * Lector mínimo de XLSX sin librerías externas: devuelve todas las hojas en orden.
     * @return array<string, array> nombre de la hoja => filas
     */
    private static function readXlsxSheets(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new InvalidArgumentException('Tu servidor no tiene la extensión ZIP de PHP. Guardá la planilla como .csv.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('No se pudo abrir el Excel. ¿Está dañado o protegido con contraseña?');
        }
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = simplexml_load_string($xml);
            foreach ($sx->si as $si) {
                $text = isset($si->t) ? (string) $si->t : '';
                foreach ($si->r as $r) {
                    $text .= (string) $r->t;
                }
                $shared[] = $text;
            }
        }
        $paths = [];
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb && $rels) {
            $targets = [];
            foreach (simplexml_load_string($rels)->Relationship as $rel) {
                $t = ltrim((string) $rel['Target'], '/');
                $targets[(string) $rel['Id']] = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
            }
            $wbx = simplexml_load_string($wb);
            $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($wbx->xpath('//m:sheets/m:sheet') ?: [] as $sh) {
                if ((string) $sh['state'] === 'hidden') {
                    continue;
                }
                $rid = (string) $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                if (isset($targets[$rid])) {
                    $paths[(string) $sh['name']] = $targets[$rid];
                }
            }
        }
        if (!$paths) {
            $paths = ['Hoja 1' => 'xl/worksheets/sheet1.xml'];
        }
        $out = [];
        foreach ($paths as $name => $sheetPath) {
            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                continue;
            }
            $out[$name] = self::sheetRows(simplexml_load_string($xml), $shared);
        }
        $zip->close();
        if (!$out) {
            throw new InvalidArgumentException('No encontramos hojas en el Excel.');
        }
        return $out;
    }

    private static function sheetRows(\SimpleXMLElement $sheet, array $shared): array
    {
        $out = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $idx = self::colIndex(preg_replace('/\d+/', '', $ref));
                $type = (string) $c['t'];
                $value = match ($type) {
                    's'         => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) $c->is->t,
                    'b'         => ((string) $c->v) === '1' ? 'VERDADERO' : 'FALSO',
                    default     => (string) $c->v,
                };
                $cells[$idx] = $value;
            }
            if ($cells) {
                $max = max(array_keys($cells));
                $out[] = array_map(fn ($i) => $cells[$i] ?? '', range(0, $max));
            }
            if (count($out) > 200000) {
                break;
            }
        }
        return $out;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }
}
