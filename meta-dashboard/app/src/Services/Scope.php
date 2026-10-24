<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Cache;
use App\Db;
use App\Meta\GraphException;
use RuntimeException;

/**
 * Qué puede ver el usuario actual.
 * - Cliente: sólo los activos asignados a su client_id (forzado, ignora parámetros).
 * - Admin: todo, o lo de un cliente si pide ?client=ID (vista previa del portal).
 */
final class Scope
{
    public static function clientId(array $q = []): ?int
    {
        $u = Auth::user();
        if (($u['role'] ?? '') === 'client') {
            if (empty($u['client_id'])) {
                throw new RuntimeException('Tu usuario no tiene un cliente asignado.');
            }
            return (int) $u['client_id'];
        }
        return !empty($q['client']) ? (int) $q['client'] : null;
    }

    /** null = sin restricción. */
    public static function allowed(?int $clientId): ?array
    {
        return $clientId === null ? null : Clients::allowed($clientId);
    }

    /**
     * Resuelve qué puede ver quien hace la request.
     * @return array{0: ?int, 1: ?array, 2: ?array} [cliente activo, activos permitidos, alcance interno]
     */
    public static function resolve(array $q): array
    {
        $u = Auth::user();
        if (($u['role'] ?? '') === 'client') {
            $cid = self::clientId($q);
            return [$cid, Clients::allowed($cid), null];
        }
        $internal = self::internal();
        $cid = !empty($q['client']) ? (int) $q['client'] : null;
        if ($cid !== null) {
            self::assertClient($cid, $internal);
            return [$cid, Clients::allowed($cid), $internal];
        }
        return [null, $internal ? ['ad_account' => $internal['ad_account'], 'page' => $internal['page'], 'ig_account' => $internal['ig_account']] : null, $internal];
    }

    /**
     * Alcance de un usuario interno (equipo / solo lectura) según lo que le asignó el admin.
     * null = sin restricciones (admin, o usuario sin asignaciones).
     */
    public static function internal(?int $userId = null): ?array
    {
        static $cache = [];
        $u = Auth::user();
        $userId ??= (int) ($u['id'] ?? 0);
        if ($userId === (int) ($u['id'] ?? 0) && ($u['role'] ?? '') === 'admin') {
            return null;
        }
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }
        $rows = Db::all('SELECT kind, ref FROM user_scopes WHERE user_id = ?', [$userId]);
        if (!$rows) {
            return $cache[$userId] = null;
        }
        $set = ['business' => [], 'ad_account' => [], 'page' => [], 'ig_account' => [], 'clients' => []];
        foreach ($rows as $r) {
            $r['kind'] === 'client' ? $set['clients'][] = (int) $r['ref'] : $set[$r['kind']][] = $r['ref'];
        }
        // Un portfolio comercial incluye todas sus cuentas publicitarias.
        if ($set['business']) {
            $ph = implode(',', array_fill(0, count($set['business']), '?'));
            foreach (Db::all("SELECT external_id FROM assets WHERE type = 'ad_account' AND business_id IN ($ph)", $set['business']) as $a) {
                $set['ad_account'][] = $a['external_id'];
            }
        }
        // Un cliente incluye sus activos.
        foreach ($set['clients'] as $cid) {
            foreach (Clients::allowed($cid) as $type => $ids) {
                $set[$type] = array_merge($set[$type], $ids);
            }
        }
        // Una página incluye su Instagram vinculado.
        if ($set['page']) {
            $ph = implode(',', array_fill(0, count($set['page']), '?'));
            foreach (Db::all("SELECT external_id FROM assets WHERE type = 'ig_account' AND parent_id IN ($ph)", $set['page']) as $a) {
                $set['ig_account'][] = $a['external_id'];
            }
        }
        foreach (['ad_account', 'page', 'ig_account', 'clients'] as $k) {
            $set[$k] = array_values(array_unique($set[$k]));
        }
        return $cache[$userId] = $set;
    }

    public static function assertClient(int $clientId, ?array $internal): void
    {
        if ($internal !== null && !in_array($clientId, $internal['clients'], true)) {
            throw new AccessDenied('No tenés asignado este cliente.');
        }
    }

    /** $allowed = activos permitidos (null = todos). */
    public static function assertAsset(string $type, string $id, ?array $allowed): void
    {
        if ($allowed === null) {
            return;
        }
        if (!in_array($id, $allowed[$type] ?? [], true)) {
            throw new AccessDenied('No tenés acceso a este recurso.');
        }
    }

    /**
     * Filtro de campañas para una cuenta compartida entre marcas.
     * null = el cliente ve la cuenta completa (o es admin sin cliente elegido).
     * @return null|\Closure(array{id:string,name:string}):bool
     */
    public static function campaignFilter(?int $clientId, string $accountId): ?\Closure
    {
        if ($clientId === null) {
            return null;
        }
        $patterns = Clients::rules($clientId)[$accountId] ?? null;
        if ($patterns === null) {
            return null;
        }
        return fn (array $c) => Clients::matches($patterns, (string) $c['id'], (string) ($c['name'] ?? ''));
    }

    /**
     * Verifica que una campaña / conjunto / anuncio pertenezca a la cuenta y,
     * si la cuenta es compartida, a una campaña del cliente.
     */
    public static function assertInAccount(string $objectId, string $accountId, ?int $clientId = null): void
    {
        $filter = self::campaignFilter($clientId, $accountId);
        if ($objectId === $accountId) {
            return; // la cuenta en sí: los servicios aplican el filtro de campañas
        }
        $client = Assets::client('ad_account', $accountId);
        try {
            $owner = Cache::remember("owner2:$objectId", 86400 * 30, function () use ($client, $objectId) {
                try {
                    $o = $client->get($objectId, ['fields' => 'account_id,campaign{id,name}']);
                    return ['account_id' => $o['account_id'] ?? null, 'campaign' => $o['campaign'] ?? null];
                } catch (GraphException $e) {
                    if (!$e->isInvalidParam()) {
                        throw $e;
                    }
                    // Es una campaña: no tiene el campo "campaign".
                    $o = $client->get($objectId, ['fields' => 'account_id,name']);
                    return ['account_id' => $o['account_id'] ?? null, 'campaign' => ['id' => $objectId, 'name' => $o['name'] ?? '']];
                }
            });
        } catch (GraphException) {
            throw new AccessDenied('Ese objeto no pertenece a la cuenta.');
        }
        if ('act_' . ($owner['account_id'] ?? '') !== $accountId) {
            throw new AccessDenied('Ese objeto no pertenece a la cuenta.');
        }
        if ($filter && (!$owner['campaign'] || !$filter($owner['campaign']))) {
            throw new AccessDenied('Esa campaña no corresponde a tu marca.');
        }
    }
}
