<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Cache;
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

    public static function assertAsset(string $type, string $id, ?int $clientId): void
    {
        if ($clientId === null) {
            return;
        }
        if (!in_array($id, Clients::allowed($clientId)[$type] ?? [], true)) {
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
