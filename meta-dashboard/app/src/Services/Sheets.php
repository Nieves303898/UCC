<?php
declare(strict_types=1);

namespace App\Services;

use App\Db;
use InvalidArgumentException;

/**
 * Planillas de Google de cualquier tipo (operación, cobranzas, turnos, leads…)
 * vinculadas a un cliente o marca, para verlas dentro del panel casi en tiempo real.
 */
final class Sheets
{
    private const REFRESH_MINUTES = 5;

    public static function list(int $clientId, bool $withData = true): array
    {
        $rows = Db::all('SELECT * FROM client_sheets WHERE client_id = ? ORDER BY name', [$clientId]);
        $limit = date('Y-m-d H:i:s', time() - self::REFRESH_MINUTES * 60);
        foreach ($rows as &$r) {
            if ($withData && ($r['last_sync_at'] === null || $r['last_sync_at'] < $limit)) {
                try {
                    self::sync((int) $r['id']);
                } catch (\Throwable) {
                    // se muestra el último dato guardado con el error
                }
                $r = Db::one('SELECT * FROM client_sheets WHERE id = ?', [$r['id']]) ?: $r;
            }
            $r['id'] = (int) $r['id'];
            $r['tabs'] = $withData && $r['data_json'] ? json_decode($r['data_json'], true) : null;
            unset($r['data_json']);
        }
        unset($r);
        return $rows;
    }

    public static function add(int $clientId, string $name, string $url): int
    {
        Clients::find($clientId);
        $url = trim($url);
        SalesImport::googleCsvUrl($url); // valida que sea de Google Sheets
        $name = trim($name) ?: 'Planilla';
        $id = Db::insert('client_sheets', ['client_id' => $clientId, 'name' => mb_substr($name, 0, 190), 'url' => mb_substr($url, 0, 500), 'created_at' => Db::now()]);
        try {
            self::sync($id);
        } catch (\Throwable $e) {
            Db::exec('DELETE FROM client_sheets WHERE id = ?', [$id]);
            throw $e;
        }
        return $id;
    }

    public static function sync(int $id): void
    {
        $s = Db::one('SELECT * FROM client_sheets WHERE id = ?', [$id]);
        if (!$s) {
            throw new InvalidArgumentException('Planilla inexistente.');
        }
        try {
            $tabs = SalesImport::readGoogleRaw((string) $s['url']);
            Db::exec('UPDATE client_sheets SET data_json = ?, last_sync_at = ?, last_error = NULL WHERE id = ?', [json_encode($tabs, JSON_UNESCAPED_UNICODE), Db::now(), $id]);
        } catch (\Throwable $e) {
            Db::exec('UPDATE client_sheets SET last_error = ?, last_sync_at = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), Db::now(), $id]);
            throw $e;
        }
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM client_sheets WHERE id = ?', [$id]);
    }

    public static function owner(int $id): int
    {
        $s = Db::one('SELECT client_id FROM client_sheets WHERE id = ?', [$id]);
        if (!$s) {
            throw new InvalidArgumentException('Planilla inexistente.');
        }
        return (int) $s['client_id'];
    }
}
