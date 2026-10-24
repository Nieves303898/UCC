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

    /** Verifica que una campaña / conjunto / anuncio pertenezca a la cuenta indicada. */
    public static function assertInAccount(string $objectId, string $accountId): void
    {
        if ($objectId === $accountId) {
            return;
        }
        try {
            $owner = Cache::remember("owner:$objectId", 86400 * 30, fn () => Assets::client('ad_account', $accountId)->get($objectId, ['fields' => 'account_id']));
        } catch (GraphException) {
            throw new AccessDenied('Ese objeto no pertenece a la cuenta.');
        }
        if ('act_' . ($owner['account_id'] ?? '') !== $accountId) {
            throw new AccessDenied('Ese objeto no pertenece a la cuenta.');
        }
    }
}
