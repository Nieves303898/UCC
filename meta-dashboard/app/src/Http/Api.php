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
use App\Meta\OAuth;
use App\Services\AccessDenied;
use App\Services\Ads;
use App\Services\Budgets;
use App\Services\SalesImport;
use App\Services\Assets;
use App\Services\AssetSync;
use App\Services\Clients;
use App\Services\Connections;
use App\Services\Instagram;
use App\Services\Pages;
use App\Services\Sales;
use App\Services\Scope;
use App\Services\Webhooks;
use InvalidArgumentException;

/** Endpoints JSON que consume el panel (public/api.php?action=...). */
final class Api
{
    /** Lo único que puede pedir un usuario cliente. Todo lo demás es sólo para admins. */
    private const CLIENT_ACTIONS = [
        'bootstrap', 'assets', 'overview', 'account', 'adsets', 'ads', 'timeseries', 'breakdown',
        'page', 'page_posts', 'ig', 'ig_media', 'ig_stories', 'ig_audience', 'activity', 'updates', 'password_change', 'sales',
    ];

    /** Usuarios internos de solo lectura (ej. gerencia): todo lo del cliente + vistas de agencia. */
    private const VIEWER_ACTIONS = ['clients', 'client_brand', 'budgets'];

    private const POST_ACTIONS = [
        'sync', 'connection_add_token', 'connection_delete', 'asset_toggle', 'cache_flush',
        'user_add', 'user_delete', 'user_reset_password', 'password_change',
        'client_save', 'client_delete', 'client_assets', 'update_save', 'update_delete',
        'sales_preview', 'sales_import', 'sales_sync', 'sales_entry', 'sales_source_delete',
        'budget_save', 'settings_save', 'campaign_target_save', 'client_rule_add',
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

        $role = Auth::user()['role'] ?? '';
        $permitted = $role === 'admin'
            || in_array($action, self::CLIENT_ACTIONS, true)
            || ($role === 'viewer' && in_array($action, self::VIEWER_ACTIONS, true));
        if (!$permitted) {
            self::json(['ok' => false, 'error' => 'No tenés permiso para esta acción.'], 403);
        }
        if (in_array($action, self::POST_ACTIONS, true) && (!$isPost || !Auth::checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
            self::json(['ok' => false, 'error' => 'Solicitud no válida (CSRF).'], 419);
        }

        try {
            $data = self::dispatch($action, $_GET, $body);
            self::json([
                'ok'   => true,
                'data' => $data,
                'meta' => [
                    'cached_at' => Cache::$meta['cached_at'] ? date('c', Cache::$meta['cached_at']) : null,
                    'stale'     => Cache::$meta['stale'],
                    'usage'     => Auth::isAdmin() ? self::usage() : null,
                ],
            ]);
        } catch (AccessDenied $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()], 403);
        } catch (GraphException $e) {
            $msg = $e->getMessage();
            if ($e->isAuthError()) {
                $msg = Auth::isAdmin()
                    ? 'Meta rechazó el token o faltan permisos: ' . $msg . ' — Reconectá la cuenta en Configuración.'
                    : 'La conexión con Meta necesita renovarse. Ya avisamos a la agencia.';
            } elseif ($e->isRateLimit()) {
                $msg = 'Meta limitó temporalmente las consultas. Probá en unos minutos.';
            }
            self::json(['ok' => false, 'error' => $msg, 'code' => $e->graphCode], 502);
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
        $client = Scope::clientId($q);
        $allowed = Scope::allowed($client);

        // Activos del cliente: valida antes de llamar a Meta.
        $adAccount = function (string $k = 'account') use ($id, $client) {
            $acc = $id($k);
            Scope::assertAsset('ad_account', $acc, $client);
            return $acc;
        };
        $inAccount = function () use ($id, $adAccount, $client) {
            $acc = $adAccount();
            Scope::assertInAccount($id(), $acc, $client);
            return [$id(), $acc, Scope::campaignFilter($client, $acc)];
        };
        $asset = function (string $type) use ($id, $client) {
            $v = $id();
            Scope::assertAsset($type, $v, $client);
            return $v;
        };

        switch ($action) {
            case 'bootstrap':
                $user = Auth::user();
                return [
                    'user'        => ['id' => (int) $user['id'], 'name' => $user['name'], 'username' => $user['username'], 'role' => $user['role']],
                    'client'      => $client ? Clients::brand($client) : null,
                    'counts'      => $allowed ? array_map('count', $allowed) : Assets::counts(),
                    'connections' => Auth::isAdmin() ? count(Connections::all()) : null,
                    'presets'     => DateRange::PRESETS,
                    'app_name'    => Config::get('app_name'),
                    'agency_name' => Config::get('agency_name', Config::get('app_name')),
                    'refresh_sec' => (int) Config::get('cache.realtime_ttl', 120),
                    'kinds'       => Clients::UPDATE_KINDS,
                    'statuses'    => Clients::UPDATE_STATUS,
                    'tax_rate'    => \App\Settings::taxRate(),
                ];

            case 'assets':
                $all = Auth::isAdmin() && !empty($q['all']);
                return [
                    'ad_accounts' => self::publicAssets(Assets::list('ad_account', $all, $allowed['ad_account'] ?? null)),
                    'pages'       => self::publicAssets(Assets::list('page', $all, $allowed['page'] ?? null)),
                    'ig_accounts' => self::publicAssets(Assets::list('ig_account', $all, $allowed['ig_account'] ?? null)),
                    'businesses'  => $allowed ? [] : Assets::list('business', $all),
                ];

            case 'overview':
                return Ads::overview($range(), $force, $allowed['ad_account'] ?? null, $client) + ['range' => $range()->toArray()];

            case 'account':
                $acc = $adAccount('id');
                $data = Ads::account($acc, $range(), $force, Scope::campaignFilter($client, $acc)) + ['range' => $range()->toArray()];
                if (!Auth::isAdmin()) {
                    unset($data['account']['payment'], $data['account']['balance']);
                }
                // Cuenta compartida: el cliente ve su marca, no la cuenta de la otra empresa.
                if ($data['sliced'] && $client !== null && Auth::user()['role'] === 'client') {
                    $data['account']['name'] = Clients::find($client)['name'];
                    unset($data['account']['business'], $data['account']['spend_cap'], $data['account']['amount_spent']);
                }
                return $data;

            case 'adsets':
                [$obj, $acc] = $inAccount();
                return Ads::adsets($obj, $acc, $range(), $force);
            case 'ads':
                [$obj, $acc] = $inAccount();
                return Ads::ads($obj, $acc, $range(), $force);
            case 'timeseries':
                [$obj, $acc, $filter] = $inAccount();
                return Ads::timeseries($obj, $acc, $range(), $q['objective'] ?? null, $force, $filter);
            case 'breakdown':
                [$obj, $acc, $filter] = $inAccount();
                return Ads::breakdown($obj, $acc, (string) ($q['type'] ?? 'age_gender'), $range(), $q['objective'] ?? null, $force, $filter);

            case 'page':
                return Pages::summary($asset('page'), $range(), $force) + ['range' => $range()->toArray()];
            case 'page_posts':
                return Pages::posts($asset('page'), $range(), $force);
            case 'ig':
                return Instagram::summary($asset('ig_account'), $range(), $force) + ['range' => $range()->toArray()];
            case 'ig_media':
                return Instagram::media($asset('ig_account'), $range(), $force);
            case 'ig_stories':
                return Instagram::stories($asset('ig_account'), $force);
            case 'ig_audience':
                return Instagram::audience($asset('ig_account'), $force);

            case 'activity':
                return Webhooks::recent((int) ($q['limit'] ?? 50), $allowed ? [...$allowed['page'], ...$allowed['ig_account']] : null);

            case 'updates':
                if ($client === null) {
                    throw new InvalidArgumentException('Indicá el cliente.');
                }
                return Clients::updates($client);

            case 'password_change':
                return self::changePassword($body);

            case 'sales':
                if ($client === null) {
                    throw new InvalidArgumentException('Indicá el cliente.');
                }
                return Sales::summary($client, $range());

            // ---------- ventas (admin) ----------
            case 'sales_preview':
                return Sales::preview($_FILES['file'] ?? null, isset($body['url']) && $body['url'] !== '' ? (string) $body['url'] : null);
            case 'sales_import':
                return Sales::import((int) ($body['client_id'] ?? 0), $body);
            case 'sales_sync':
                return Sales::sync((int) ($body['id'] ?? 0));
            case 'sales_entry':
                Sales::entry((int) ($body['client_id'] ?? 0), $body);
                return null;
            case 'sales_source_delete':
                Sales::deleteSource((int) ($body['id'] ?? 0));
                return null;

            // ---------- sólo admin ----------
            case 'settings':
                return [
                    'connections'   => Connections::all(),
                    'users'         => Db::all("SELECT id, username, email, name, role, last_login_at FROM users WHERE role IN ('admin', 'viewer') ORDER BY id"),
                    'tax_rate'      => \App\Settings::taxRate() * 100,
                    'oauth_ready'   => Config::get('meta.app_id') && Config::get('meta.app_secret'),
                    'redirect_uri'  => OAuth::redirectUri(),
                    'webhook_url'   => Config::url('webhook.php'),
                    'graph_version' => GraphClient::version(),
                    'log'           => Db::all('SELECT job, status, message, created_at FROM sync_log ORDER BY id DESC LIMIT 15'),
                ];
            case 'budgets':
                return Budgets::overview(null, $force);
            case 'budget_save':
                $key = !empty($body['client_id']) ? 'client:' . (int) $body['client_id'] : self::id((string) ($body['account'] ?? ''));
                Budgets::save($key, $body['month'] ?? null, $body['amount'] ?? null, $body['note'] ?? null);
                return null;
            case 'settings_save':
                $rate = SalesImport::parseNumber($body['tax_rate'] ?? null);
                if ($rate === null || $rate < 0 || $rate > 200) {
                    throw new InvalidArgumentException('Ingresá un porcentaje entre 0 y 200.');
                }
                \App\Settings::set('tax_rate', (string) $rate);
                Cache::flush(); // las métricas guardadas en caché llevan el impuesto anterior
                return null;
            case 'campaign_target_save':
                Sales::saveCampaignTarget(self::id((string) ($body['campaign_id'] ?? '')), $body['month'] ?? null, $body['target_orders'] ?? '', $body['target_revenue'] ?? '');
                return null;
            case 'campaign_groups':
                return Clients::detectGroups();
            case 'client_rule_add':
                Clients::addRule((int) ($body['client_id'] ?? 0), self::id((string) ($body['account'] ?? '')), (string) ($body['pattern'] ?? ''));
                return null;
            case 'client_brand':
                return Clients::brand((int) ($q['id'] ?? 0));
            case 'campaign_names':
                return Sales::campaignNames((int) ($q['id'] ?? 0));
            case 'clients':
                return Clients::all();
            case 'client':
                return Clients::detail((int) ($q['id'] ?? 0));
            case 'client_save':
                return ['id' => Clients::save($body)];
            case 'client_delete':
                Clients::delete((int) ($body['id'] ?? 0));
                return null;
            case 'client_assets':
                Clients::setAssets((int) ($body['id'] ?? 0), (array) ($body['assets'] ?? []), isset($body['rules']) ? (array) $body['rules'] : null);
                return null;
            case 'update_save':
                return ['id' => Clients::saveUpdate($body)];
            case 'update_delete':
                Clients::deleteUpdate((int) ($body['id'] ?? 0));
                return null;
            case 'sync':
                return isset($body['connection_id']) ? AssetSync::sync((int) $body['connection_id']) : AssetSync::syncAll();
            case 'connection_add_token':
                return self::addToken($body);
            case 'connection_delete':
                Connections::delete((int) ($body['id'] ?? 0));
                return null;
            case 'asset_toggle':
                Assets::setHidden((int) ($body['id'] ?? 0), !empty($body['hidden']));
                return null;
            case 'cache_flush':
                Cache::flush();
                return null;
            case 'user_add':
                $role = in_array($body['role'] ?? '', ['client', 'viewer'], true) ? $body['role'] : 'admin';
                $clientId = $role === 'client' ? Clients::find((int) ($body['client_id'] ?? 0))['id'] : null;
                return ['id' => Auth::createUser((string) ($body['username'] ?? ''), (string) ($body['name'] ?? ''), (string) ($body['password'] ?? ''), $role, $clientId)];
            case 'user_delete':
                if ((int) ($body['id'] ?? 0) === (int) Auth::user()['id']) {
                    throw new InvalidArgumentException('No podés borrar tu propio usuario.');
                }
                Db::exec('DELETE FROM users WHERE id = ?', [(int) ($body['id'] ?? 0)]);
                return null;
            case 'user_reset_password':
                if (strlen((string) ($body['password'] ?? '')) < 8) {
                    throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
                }
                Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string) $body['password'], PASSWORD_DEFAULT), (int) ($body['id'] ?? 0)]);
                return null;
        }
        throw new InvalidArgumentException('Acción desconocida');
    }

    /** Quita del listado datos que el cliente no necesita. */
    private static function publicAssets(array $list): array
    {
        if (Auth::isAdmin()) {
            return $list;
        }
        return array_map(function ($a) {
            unset($a['data']['balance'], $a['data']['spend_cap'], $a['connection_id']);
            return $a;
        }, $list);
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
                $token = OAuth::longLived($token)['access_token'];
            } catch (\Throwable) {
            }
        }
        $id = Connections::save($token, $source, trim((string) ($body['label'] ?? '')) ?: null);
        return ['id' => $id, 'sync' => AssetSync::sync($id)];
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
