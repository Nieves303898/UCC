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
            $c['assets'] = (int) Db::one('SELECT COUNT(*) AS n FROM client_assets WHERE client_id = ?', [$c['id']])['n'];
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
            'users'   => Db::all('SELECT id, username, name, last_login_at, created_at FROM users WHERE client_id = ? ORDER BY id', [$id]),
            'updates' => self::updates($id),
        ];
    }

    /** Datos públicos del cliente para su propio portal. */
    public static function brand(int $id): array
    {
        $c = self::find($id);
        return ['id' => $c['id'], 'name' => $c['name'], 'color' => $c['color'], 'logo_url' => $c['logo_url']];
    }

    public static function save(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('El cliente necesita un nombre.');
        }
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($data['color'] ?? '')) ? $data['color'] : '#0e7c66';
        $logo = filter_var($data['logo_url'] ?? '', FILTER_VALIDATE_URL) && str_starts_with((string) $data['logo_url'], 'https://') ? $data['logo_url'] : null;
        $row = ['name' => mb_substr($name, 0, 190), 'color' => $color, 'logo_url' => $logo, 'notes' => $data['notes'] ?? null];
        if (!empty($data['id'])) {
            self::find((int) $data['id']);
            Db::exec('UPDATE clients SET name = ?, color = ?, logo_url = ?, notes = ? WHERE id = ?', [...array_values($row), (int) $data['id']]);
            return (int) $data['id'];
        }
        return Db::insert('clients', $row + ['created_at' => Db::now()]);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM client_assets WHERE client_id = ?', [$id]);
        Db::exec('DELETE FROM client_updates WHERE client_id = ?', [$id]);
        Db::exec('DELETE FROM users WHERE client_id = ?', [$id]);
        Db::exec('DELETE FROM clients WHERE id = ?', [$id]);
    }

    /** Reemplaza los activos asignados. $assets = [['type' => 'ad_account', 'id' => 'act_1'], ...] */
    public static function setAssets(int $id, array $assets): void
    {
        self::find($id);
        $valid = ['ad_account', 'page', 'ig_account'];
        Db::exec('DELETE FROM client_assets WHERE client_id = ?', [$id]);
        foreach ($assets as $a) {
            if (!in_array($a['type'] ?? '', $valid, true) || !Db::one('SELECT id FROM assets WHERE type = ? AND external_id = ?', [$a['type'], (string) $a['id']])) {
                continue;
            }
            Db::insert('client_assets', ['client_id' => $id, 'asset_type' => $a['type'], 'external_id' => (string) $a['id']]);
        }
    }

    /** IDs permitidos por tipo para un cliente. */
    public static function allowed(int $clientId): array
    {
        $out = ['ad_account' => [], 'page' => [], 'ig_account' => []];
        foreach (Db::all('SELECT asset_type, external_id FROM client_assets WHERE client_id = ?', [$clientId]) as $r) {
            $out[$r['asset_type']][] = $r['external_id'];
        }
        return $out;
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
