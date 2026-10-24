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
        'leads'   => ['consultas', 'leads', 'contactos', 'visitas', 'mensajes', 'solicitudes', 'reservas', 'turnos solicitados'],
        'campaign' => ['campaña', 'campana', 'campaign', 'nombre de campaña', 'campaña meta', 'anuncio'],
        'dim'     => ['sucursal', 'planta', 'canal', 'origen', 'producto', 'servicio', 'local', 'sede', 'categoria', 'categoría', 'medio'],
    ];

    /** Lee un archivo subido o una URL y devuelve ['headers' => [...], 'rows' => [[...], ...]]. */
    public static function readFile(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            return self::table(self::readXlsx($path));
        }
        if (in_array($ext, ['csv', 'txt', 'tsv'], true)) {
            return self::table(self::readCsv((string) file_get_contents($path)));
        }
        if ($ext === 'xls') {
            throw new InvalidArgumentException('El formato .xls (Excel 97) no es compatible. Guardalo como .xlsx o .csv.');
        }
        throw new InvalidArgumentException('Subí un archivo .xlsx o .csv.');
    }

    /** Descarga un Google Sheets como CSV. Acepta el link de edición o el de "Publicar en la web". */
    public static function readGoogleSheet(string $url): array
    {
        $csvUrl = self::googleCsvUrl($url);
        $ch = curl_init($csvUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if (!is_string($body) || $status >= 400 || str_contains($type, 'text/html')) {
            throw new InvalidArgumentException('No pudimos leer la planilla. Compartila como "Cualquier persona con el enlace puede ver" o usá Archivo → Compartir → Publicar en la web → CSV.');
        }
        return self::table(self::readCsv($body));
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

    /** Sugiere qué columna corresponde a cada campo según el encabezado. */
    public static function suggest(array $headers): array
    {
        $norm = fn ($s) => trim(preg_replace('/\s+/', ' ', strtolower(strtr((string) $s, 'áéíóúÁÉÍÓÚñÑ', 'aeiouAEIOUnN'))));
        $out = [];
        $used = [];
        foreach (self::FIELDS as $field => $words) {
            $out[$field] = null;
            foreach ($headers as $i => $h) {
                if (in_array($i, $used, true)) {
                    continue;
                }
                $hn = $norm($h);
                foreach ($words as $w) {
                    if ($hn === $norm($w) || str_contains($hn, $norm($w))) {
                        $out[$field] = $i;
                        $used[] = $i;
                        continue 3;
                    }
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
        $col = fn ($k) => isset($map[$k]) && $map[$k] !== '' && $map[$k] !== null ? (int) $map[$k] : null;
        [$d, $o, $r, $l, $dim, $camp] = [$col('date'), $col('orders'), $col('revenue'), $col('leads'), $col('dim'), $col('campaign')];
        $days = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $day = self::parseDate($row[$d] ?? null);
            if (!$day) {
                $skipped++;
                continue;
            }
            $key = mb_substr(trim((string) ($dim !== null ? ($row[$dim] ?? '') : '')), 0, 120);
            $campaign = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($camp !== null ? ($row[$camp] ?? '') : ''))), 0, 190);
            $agg = &$days[$day . '|' . $key . '|' . $campaign];
            $agg ??= ['day' => $day, 'dim' => $key, 'campaign' => $campaign, 'orders' => 0.0, 'revenue' => 0.0, 'leads' => null];
            $agg['orders'] += $o !== null ? (self::parseNumber($row[$o] ?? null) ?? 0) : 1;
            $agg['revenue'] += $r !== null ? (self::parseNumber($row[$r] ?? null) ?? 0) : 0;
            if ($l !== null) {
                $agg['leads'] = ($agg['leads'] ?? 0) + (self::parseNumber($row[$l] ?? null) ?? 0);
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

    /** Lector mínimo de XLSX (primera hoja) sin librerías externas. */
    private static function readXlsx(string $path): array
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
        // Primera hoja según el libro.
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb && $rels) {
            $wbx = simplexml_load_string($wb);
            $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $first = $wbx->xpath('//m:sheets/m:sheet')[0] ?? null;
            if ($first) {
                $rid = (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach (simplexml_load_string($rels)->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');
                        $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }
        $xml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($xml === false) {
            throw new InvalidArgumentException('No encontramos hojas en el Excel.');
        }
        $sheet = simplexml_load_string($xml);
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
