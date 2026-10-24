<?php
declare(strict_types=1);

namespace App\Http;

use App\Auth;
use App\Cache;
use App\Config;
use App\DateRange;
use App\Db;
use App\Meta\GraphClient;
use App\Meta\GraphException;
use App\Services\Ads;
use App\Services\Assets;
use App\Services\AssetSync;
use App\Services\Connections;
use App\Services\Instagram;
use App\Services\Pages;
use App\Services\Webhooks;
use InvalidArgumentException;

/** Endpoints JSON que consume el panel (public/api.php?action=...). */
final class Api
{
    private const POST_ACTIONS = [
        'sync', 'connection_add_token', 'connection_delete', 'asset_toggle', 'cache_flush',
        'user_add', 'user_delete', 'password_change',
    ];

    private const ADMIN_ACTIONS = [
        'connection_add_token', 'connection_delete', 'asset_toggle', 'cache_flush', 'user_add', 'user_delete',
    ];

    public static function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (!Auth::user()) {
            self::json(['ok' => false, 'error' => 'Sesión vencida. Volvé a iniciar sesión.', 'auth' => false], 401);
        }

        $action = (string) ($_GET['action'] ?? '');
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        $body = $isPost ? (json_decode((string) file_get_contents('php://input'), true) ?: $_POST) : [];

        if (in_array($action, self::POST_ACTIONS, true)) {
            if (!$isPost || !Auth::checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
                self::json(['ok' => false, 'error' => 'Solicitud no válida (CSRF).'], 419);
            }
            if (in_array($action, self::ADMIN_ACTIONS, true) && (Auth::user()['role'] ?? '') !== 'admin') {
                self::json(['ok' => false, 'error' => 'Sólo un administrador puede hacer esto.'], 403);
            }
        }

        try {
            $data = self::dispatch($action, $_GET, $body);
            self::json([
                'ok'   => true,
                'data' => $data,
                'meta' => [
                    'cached_at' => Cache::$meta['cached_at'] ? date('c', Cache::$meta['cached_at']) : null,
                    'stale'     => Cache::$meta['stale'],
                    'usage'     => self::usage(),
                ],
            ]);
        } catch (GraphException $e) {
            $msg = $e->getMessage();
            if ($e->isAuthError()) {
                $msg = 'Meta rechazó el token o faltan permisos: ' . $msg . ' — Reconectá la cuenta en Configuración.';
            } elseif ($e->isRateLimit()) {
                $msg = 'Meta limitó temporalmente las consultas (rate limit). Probá en unos minutos.';
            }
            self::json(['ok' => false, 'error' => $msg, 'code' => $e->graphCode, 'fbtrace' => $e->fbtraceId], 502);
        } catch (InvalidArgumentException $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            error_log('[metahub] ' . $e);
            self::json(['ok' => false, 'error' => Config::get('debug') ? $e->getMessage() : 'Error interno. Revisá el log del servidor.'], 500);
        }
    }

    private static function dispatch(string $action, array $q, array $body): mixed
    {
        $force = !empty($q['refresh']);
        $range = fn () => DateRange::fromRequest($q);
        $id = fn (string $k = 'id') => self::id($q[$k] ?? '');

        return match ($action) {
            'bootstrap' => [
                'user'        => Auth::user(),
                'counts'      => Assets::counts(),
                'connections' => count(Connections::all()),
                'presets'     => DateRange::PRESETS,
                'app_name'    => Config::get('app_name'),
                'refresh_sec' => (int) Config::get('cache.realtime_ttl', 120),
            ],
            'assets' => [
                'businesses'  => Assets::list('business', !empty($q['all'])),
                'ad_accounts' => Assets::list('ad_account', !empty($q['all'])),
                'pages'       => Assets::list('page', !empty($q['all'])),
                'ig_accounts' => Assets::list('ig_account', !empty($q['all'])),
            ],
            'overview'    => Ads::overview($range(), $force) + ['range' => $range()->toArray()],
            'account'     => Ads::account($id(), $range(), $force) + ['range' => $range()->toArray()],
            'adsets'      => Ads::adsets($id(), $id('account'), $range(), $force),
            'ads'         => Ads::ads($id(), $id('account'), $range(), $force),
            'timeseries'  => Ads::timeseries($id(), $id('account'), $range(), $q['objective'] ?? null, $force),
            'breakdown'   => Ads::breakdown($id(), $id('account'), (string) ($q['type'] ?? 'age_gender'), $range(), $q['objective'] ?? null, $force),
            'page'        => Pages::summary($id(), $range(), $force) + ['range' => $range()->toArray()],
            'page_posts'  => Pages::posts($id(), $range(), $force),
            'ig'          => Instagram::summary($id(), $range(), $force) + ['range' => $range()->toArray()],
            'ig_media'    => Instagram::media($id(), $range(), $force),
            'ig_stories'  => Instagram::stories($id(), $force),
            'ig_audience' => Instagram::audience($id(), $force),
            'activity'    => Webhooks::recent((int) ($q['limit'] ?? 50)),
            'settings'    => [
                'connections'  => Connections::all(),
                'users'        => Db::all('SELECT id, email, name, role, last_login_at FROM users ORDER BY id'),
                'oauth_ready'  => Config::get('meta.app_id') && Config::get('meta.app_secret'),
                'redirect_uri' => \App\Meta\OAuth::redirectUri(),
                'webhook_url'  => Config::url('webhook.php'),
                'graph_version' => GraphClient::version(),
                'log'          => Db::all('SELECT job, status, message, created_at FROM sync_log ORDER BY id DESC LIMIT 15'),
            ],
            'sync' => isset($body['connection_id'])
                ? AssetSync::sync((int) $body['connection_id'])
                : AssetSync::syncAll(),
            'connection_add_token' => self::addToken($body),
            'connection_delete'    => Connections::delete((int) ($body['id'] ?? 0)),
            'asset_toggle'         => Assets::setHidden((int) ($body['id'] ?? 0), !empty($body['hidden'])),
            'cache_flush'          => Cache::flush(),
            'user_add'             => self::addUser($body),
            'user_delete'          => self::deleteUser((int) ($body['id'] ?? 0)),
            'password_change'      => self::changePassword($body),
            default => throw new InvalidArgumentException('Acción desconocida'),
        };
    }

    private static function addToken(array $body): array
    {
        $token = trim((string) ($body['token'] ?? ''));
        if (strlen($token) < 20) {
            throw new InvalidArgumentException('Pegá un token de acceso válido.');
        }
        $source = ($body['source'] ?? '') === 'system_user' ? 'system_user' : 'manual';
        if ($source === 'manual' && Config::get('meta.app_secret')) {
            // Si es un token corto de usuario lo extendemos a 60 días.
            try {
                $token = \App\Meta\OAuth::longLived($token)['access_token'];
            } catch (\Throwable) {
            }
        }
        $id = Connections::save($token, $source, trim((string) ($body['label'] ?? '')) ?: null);
        return ['id' => $id, 'sync' => AssetSync::sync($id)];
    }

    private static function addUser(array $body): array
    {
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new InvalidArgumentException('Email inválido o contraseña menor a 8 caracteres.');
        }
        if (Db::one('SELECT id FROM users WHERE email = ?', [mb_strtolower($email)])) {
            throw new InvalidArgumentException('Ya existe un usuario con ese email.');
        }
        $role = ($body['role'] ?? '') === 'viewer' ? 'viewer' : 'admin';
        return ['id' => Auth::createUser($email, (string) ($body['name'] ?? $email), $password, $role)];
    }

    private static function deleteUser(int $id): void
    {
        if ($id === (int) Auth::user()['id']) {
            throw new InvalidArgumentException('No podés borrar tu propio usuario.');
        }
        Db::exec('DELETE FROM users WHERE id = ?', [$id]);
    }

    private static function changePassword(array $body): void
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [Auth::user()['id']]);
        if (!password_verify((string) ($body['current'] ?? ''), $user['password_hash'])) {
            throw new InvalidArgumentException('La contraseña actual no es correcta.');
        }
        if (strlen((string) ($body['new'] ?? '')) < 8) {
            throw new InvalidArgumentException('La nueva contraseña debe tener al menos 8 caracteres.');
        }
        Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string) $body['new'], PASSWORD_DEFAULT), $user['id']]);
    }

    private static function id(string $value): string
    {
        if (!preg_match('/^(act_)?[0-9_]{3,64}$/', $value)) {
            throw new InvalidArgumentException('ID inválido');
        }
        return $value;
    }

    /** Porcentaje de uso de la API informado por Meta (el más alto). */
    private static function usage(): ?int
    {
        $max = null;
        array_walk_recursive(GraphClient::$lastUsage, function ($v, $k) use (&$max) {
            if (in_array($k, ['call_count', 'total_cputime', 'total_time', 'acc_id_util_pct'], true) && is_numeric($v)) {
                $max = max($max ?? 0, (int) $v);
            }
        });
        return $max;
    }

    private static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
