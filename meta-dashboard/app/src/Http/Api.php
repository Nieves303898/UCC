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
use App\Services\AdLibrary;
use App\Services\Ads;
use App\Services\Budgets;
use App\Services\SalesImport;
use App\Services\Assets;
use App\Services\AssetSync;
use App\Services\Clients;
use App\Services\Connections;
use App\Services\Instagram;
use App\Services\Pages;
use App\Services\Planner;
use App\Services\Reports;
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
        'page', 'page_posts', 'ig', 'ig_media', 'ig_stories', 'ig_audience', 'activity', 'updates', 'password_change', 'sales', 'top_ads',
    ];

    /** Usuarios internos de solo lectura (ej. gerencia): todo lo del cliente + vistas de agencia. */
    private const VIEWER_ACTIONS = ['clients', 'client_brand', 'budgets', 'budget_plan', 'report', 'adlib'];

    /** Equipo: además edita lo operativo (presupuestos, objetivos, ventas, trabajo) de lo que tiene asignado. */
    private const MEMBER_ACTIONS = [
        'client', 'campaign_names', 'budget_save', 'campaign_target_save', 'update_save', 'update_delete', 'report_note_save',
        'sales_preview', 'sales_import', 'sales_sync', 'sales_entry', 'sales_source_delete', 'brand_goal_save',
        'adlib_niche_save', 'adlib_niche_delete', 'adlib_competitor_add', 'adlib_competitor_delete', 'adlib_save', 'adlib_delete', 'adlib_upload',
    ];

    private const POST_ACTIONS = [
        'sync', 'connection_add_token', 'connection_delete', 'asset_toggle', 'cache_flush',
        'user_add', 'user_delete', 'user_reset_password', 'password_change',
        'client_save', 'client_delete', 'client_assets', 'update_save', 'update_delete',
        'sales_preview', 'sales_import', 'sales_sync', 'sales_entry', 'sales_source_delete',
        'budget_save', 'settings_save', 'campaign_target_save', 'client_rule_add', 'user_scope_save',
        'report_note_save', 'logo_upload', 'report_brand_save', 'brand_goal_save', 'brand_map_save', 'client_kind_save',
        'adlib_niche_save', 'adlib_niche_delete', 'adlib_competitor_add', 'adlib_competitor_delete', 'adlib_save', 'adlib_delete', 'adlib_upload',
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
            || (in_array($role, ['viewer', 'member'], true) && in_array($action, self::VIEWER_ACTIONS, true))
            || ($role === 'member' && in_array($action, self::MEMBER_ACTIONS, true));
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
        [$client, $allowed, $internal] = Scope::resolve($q);
        // Para acciones de equipo sobre un cliente / cuenta en particular.
        $ownClient = function (int $cid) use ($internal) {
            Scope::assertClient($cid, $internal);
            return $cid;
        };
        $ownAccount = function (string $acc) use ($internal) {
            Scope::assertAsset('ad_account', $acc, $internal);
            return $acc;
        };

        // Activos permitidos: valida antes de llamar a Meta.
        $adAccount = function (string $k = 'account') use ($id, $allowed) {
            $acc = $id($k);
            Scope::assertAsset('ad_account', $acc, $allowed);
            return $acc;
        };
        $inAccount = function () use ($id, $adAccount, $client) {
            $acc = $adAccount();
            Scope::assertInAccount($id(), $acc, $client);
            return [$id(), $acc, Scope::campaignFilter($client, $acc)];
        };
        $asset = function (string $type) use ($id, $allowed) {
            $v = $id();
            Scope::assertAsset($type, $v, $allowed);
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
                    'report'      => ['company' => \App\Settings::get('report_company', Config::get('agency_name', '')), 'logo' => \App\Settings::get('report_logo')],
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
            case 'top_ads':
                $accs = !empty($q['account']) ? [$adAccount()] : array_map(fn ($a) => $a['external_id'], Assets::list('ad_account', false, $allowed['ad_account'] ?? null));
                $filters = [];
                foreach ($accs as $acc) {
                    $filters[$acc] = Scope::campaignFilter($client, $acc);
                }
                $top = Ads::topAds($filters, $range(), (string) ($q['metric'] ?? 'results'), (int) ($q['limit'] ?? 12), $force);
                if ($client !== null) { // en cuentas compartidas el cliente no ve el nombre de la cuenta de la empresa
                    $brand = Clients::find($client)['name'];
                    foreach ($top['ads'] as &$ad) {
                        if ($filters[$ad['account']['id']] ?? null) {
                            $ad['account']['name'] = $brand;
                        }
                    }
                    unset($ad);
                }
                return $top + ['range' => $range()->toArray()];
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
                return Sales::import($ownClient((int) ($body['client_id'] ?? 0)), $body);
            case 'sales_sync':
                $ownClient(Sales::sourceClient((int) ($body['id'] ?? 0)));
                return Sales::sync((int) ($body['id'] ?? 0));
            case 'sales_entry':
                Sales::entry($ownClient((int) ($body['client_id'] ?? 0)), $body);
                return null;
            case 'sales_source_delete':
                $ownClient(Sales::sourceClient((int) ($body['id'] ?? 0)));
                Sales::deleteSource((int) ($body['id'] ?? 0));
                return null;

            // ---------- sólo admin ----------
            case 'settings':
                return [
                    'connections'   => Connections::all(),
                    'users'         => array_map(fn ($u) => $u + ['scope' => Db::all('SELECT kind, ref FROM user_scopes WHERE user_id = ?', [$u['id']])],
                        Db::all("SELECT id, username, email, name, role, last_login_at FROM users WHERE role IN ('admin', 'viewer', 'member') ORDER BY id")),
                    'businesses'    => Assets::list('business', true),
                    'clients'       => Db::all('SELECT id, name, color FROM clients ORDER BY name'),
                    'tax_rate'      => \App\Settings::taxRate() * 100,
                    'oauth_ready'   => Config::get('meta.app_id') && Config::get('meta.app_secret'),
                    'redirect_uri'  => OAuth::redirectUri(),
                    'webhook_url'   => Config::url('webhook.php'),
                    'graph_version' => GraphClient::version(),
                    'log'           => Db::all('SELECT job, status, message, created_at FROM sync_log ORDER BY id DESC LIMIT 15'),
                ];
            case 'budgets':
                return Budgets::overview($internal['ad_account'] ?? null, $force, Clients::brandIds($internal['clients'] ?? null)) + ['has_brands' => Clients::hasBrands()];
            case 'budget_save':
                $key = !empty($body['client_id']) ? 'client:' . $ownClient((int) $body['client_id']) : $ownAccount(self::id((string) ($body['account'] ?? '')));
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
            case 'budget_plan':
                // Sólo las marcas (si todavía no se definieron, todos los clientes).
                $ids = Clients::brandIds($internal['clients'] ?? null);
                // Filtro por cliente (ej. sólo las marcas de PayLab Gestión).
                $parents = [];
                foreach (Db::all("SELECT DISTINCT p.id, p.name FROM clients m JOIN clients p ON p.id = m.parent_id WHERE m.kind = 'marca' ORDER BY p.name") as $p) {
                    $parents[] = ['id' => (int) $p['id'], 'name' => $p['name']];
                }
                if (!empty($q['parent'])) {
                    $children = array_map('intval', array_column(Db::all('SELECT id FROM clients WHERE parent_id = ?', [(int) $q['parent']]), 'id'));
                    $ids = array_values(array_intersect($ids, $children));
                }
                $loose = [];
                if (Clients::hasBrands() && $internal === null) {
                    // Cuentas compartidas entre marcas: campañas activas que todavía no tienen marca (ej. nuevas).
                    try {
                        foreach (Budgets::overview(null, false, $ids)['accounts'] as $a) {
                            $tagged = array_filter($a['campaigns'], fn ($c) => $c['brands']);
                            if (!$tagged || count($tagged) === count($a['campaigns'])) {
                                continue;
                            }
                            foreach ($a['campaigns'] as $c) {
                                if (!$c['brands'] && $c['active']) {
                                    $loose[] = ['account' => $a['name'], 'name' => $c['name']];
                                }
                            }
                        }
                    } catch (\Throwable) {
                    }
                }
                return Planner::plan($ids, (string) ($q['month'] ?? 'next'), (string) ($q['basis'] ?? 'avg3'))
                    + ['has_brands' => Clients::hasBrands(), 'can_split' => Auth::isAdmin(), 'unassigned' => $loose, 'parents' => $parents];
            case 'brand_map':
                return Clients::brandMap();
            case 'brand_map_campaigns':
                return Clients::brandMapCampaigns($id('account'));
            case 'brand_map_save':
                foreach ((array) ($body['brands'] ?? []) as $b) {
                    Clients::setParent((int) ($b['id'] ?? 0), $b['parent_id'] ?? null);
                }
                Clients::saveBrandMap((array) ($body['accounts'] ?? []));
                return null;
            case 'client_kind_save':
                Clients::setKind((int) ($body['id'] ?? 0), (string) ($body['kind'] ?? 'cliente'));
                return null;
            case 'brand_goal_save':
                Planner::saveGoal($ownClient((int) ($body['client_id'] ?? 0)), $body['month'] ?? null, $body['target_orders'] ?? '', $body['target_revenue'] ?? '');
                return null;
            case 'adlib':
                return AdLibrary::all($internal['clients'] ?? null);
            case 'adlib_niche_save':
                return ['id' => AdLibrary::saveNiche($body)];
            case 'adlib_niche_delete':
                AdLibrary::deleteNiche((int) ($body['id'] ?? 0));
                return null;
            case 'adlib_competitor_add':
                AdLibrary::addCompetitor((int) ($body['niche_id'] ?? 0), (string) ($body['name'] ?? ''), (string) ($body['ref'] ?? ''));
                return null;
            case 'adlib_competitor_delete':
                AdLibrary::deleteCompetitor((int) ($body['id'] ?? 0));
                return null;
            case 'adlib_save':
                return ['id' => AdLibrary::save($body, $internal['clients'] ?? null)];
            case 'adlib_delete':
                AdLibrary::delete((int) ($body['id'] ?? 0));
                return null;
            case 'adlib_upload':
                return ['url' => self::storeImage($_FILES['file'] ?? null, 'ref', 4)];
            case 'campaign_target_save':
                if ($internal !== null) {
                    $acc = $ownAccount(self::id((string) ($body['account'] ?? '')));
                    Scope::assertInAccount(self::id((string) ($body['campaign_id'] ?? '')), $acc);
                }
                Sales::saveCampaignTarget(self::id((string) ($body['campaign_id'] ?? '')), $body['month'] ?? null, $body['target_orders'] ?? '', $body['target_revenue'] ?? '');
                return null;
            case 'campaign_groups':
                return Clients::detectGroups();
            case 'client_rule_add':
                Clients::addRule((int) ($body['client_id'] ?? 0), self::id((string) ($body['account'] ?? '')), (string) ($body['pattern'] ?? ''));
                return null;
            case 'client_brand':
                return Clients::brand($ownClient((int) ($q['id'] ?? 0)));
            case 'campaign_names':
                return Sales::campaignNames($ownClient((int) ($q['id'] ?? 0)));
            case 'clients':
                $list = Clients::all();
                return $internal === null ? $list : array_values(array_filter($list, fn ($c) => in_array($c['id'], $internal['clients'], true)));
            case 'client':
                return Clients::detail($ownClient((int) ($q['id'] ?? 0)));
            case 'client_save':
                return ['id' => Clients::save($body)];
            case 'client_delete':
                Clients::delete((int) ($body['id'] ?? 0));
                return null;
            case 'client_assets':
                Clients::setAssets((int) ($body['id'] ?? 0), (array) ($body['assets'] ?? []), isset($body['rules']) ? (array) $body['rules'] : null);
                return null;
            case 'update_save':
                $ownClient((int) ($body['client_id'] ?? 0));
                return ['id' => Clients::saveUpdate($body)];
            case 'update_delete':
                $u = Db::one('SELECT client_id FROM client_updates WHERE id = ?', [(int) ($body['id'] ?? 0)]);
                $ownClient((int) ($u['client_id'] ?? 0));
                Clients::deleteUpdate((int) ($body['id'] ?? 0));
                return null;
            case 'report':
                $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($q['clients'] ?? '')))));
                if ($internal !== null) {
                    $ids = array_values(array_intersect($ids, $internal['clients']));
                }
                if (!$ids) {
                    throw new InvalidArgumentException('Elegí al menos una marca.');
                }
                return ($q['type'] ?? '') === 'projection'
                    ? Reports::projection($ids, $q['as_of'] ?? null)
                    : Reports::progress($ids, (string) ($q['period'] ?? 'semanal'), $q['until'] ?? null);
            case 'report_note_save':
                Reports::saveNote((string) ($body['key'] ?? ''), isset($body['value']) ? (string) $body['value'] : null);
                return null;
            case 'report_brand_save':
                \App\Settings::set('report_company', mb_substr(trim((string) ($body['company'] ?? '')), 0, 120));
                return null;
            case 'logo_upload':
                return ['url' => self::uploadLogo($_FILES['file'] ?? null, (string) ($_POST['target'] ?? 'report'))];
            case 'user_scope_save':
                return self::saveUserScope($body);
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
                $role = in_array($body['role'] ?? '', ['client', 'viewer', 'member'], true) ? $body['role'] : 'admin';
                $clientId = $role === 'client' ? Clients::find((int) ($body['client_id'] ?? 0))['id'] : null;
                return ['id' => Auth::createUser((string) ($body['username'] ?? ''), (string) ($body['name'] ?? ''), (string) ($body['password'] ?? ''), $role, $clientId)];
            case 'user_delete':
                if ((int) ($body['id'] ?? 0) === (int) Auth::user()['id']) {
                    throw new InvalidArgumentException('No podés borrar tu propio usuario.');
                }
                Db::exec('DELETE FROM users WHERE id = ?', [(int) ($body['id'] ?? 0)]);
                Db::exec('DELETE FROM user_scopes WHERE user_id = ?', [(int) ($body['id'] ?? 0)]);
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

    /** Logo del reporte de agencia o de un cliente. Sólo PNG, JPG o WebP (máx. 2 MB). */
    /** Guarda una imagen subida (PNG/JPG/WebP) en public/uploads y devuelve su URL. */
    private static function storeImage(?array $file, string $prefix, int $maxMb): string
    {
        if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || $file['size'] > $maxMb * 1024 * 1024) {
            throw new InvalidArgumentException("Subí una imagen de hasta $maxMb MB.");
        }
        $info = @getimagesize($file['tmp_name']);
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if (!$ext) {
            throw new InvalidArgumentException('La imagen tiene que ser PNG, JPG o WebP.');
        }
        $dir = APP_ROOT . '/public/uploads';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new InvalidArgumentException('No se pudo crear la carpeta public/uploads.');
        }
        $name = $prefix . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
            throw new InvalidArgumentException('No se pudo guardar el archivo.');
        }
        return Config::url('uploads/' . $name);
    }

    private static function uploadLogo(?array $file, string $target): string
    {
        if ($target !== 'report') {
            Clients::find((int) $target);
        }
        $url = self::storeImage($file, 'logo', 2);
        if ($target === 'report') {
            \App\Settings::set('report_logo', $url);
        } else {
            Db::exec('UPDATE clients SET logo_url = ? WHERE id = ?', [$url, (int) $target]);
        }
        return $url;
    }

    /** Asigna a un usuario interno qué portfolios, cuentas, páginas, Instagram y clientes ve. */
    private static function saveUserScope(array $body): void
    {
        $userId = (int) ($body['user_id'] ?? 0);
        $user = Db::one('SELECT role FROM users WHERE id = ?', [$userId]);
        if (!$user || !in_array($user['role'], ['viewer', 'member'], true)) {
            throw new InvalidArgumentException('Sólo se asignan accesos a usuarios de equipo o solo lectura.');
        }
        $role = in_array($body['role'] ?? '', ['viewer', 'member'], true) ? $body['role'] : $user['role'];
        Db::exec('UPDATE users SET role = ? WHERE id = ?', [$role, $userId]);
        Db::exec('DELETE FROM user_scopes WHERE user_id = ?', [$userId]);
        $types = ['business' => 'business', 'ad_account' => 'ad_account', 'page' => 'page', 'ig_account' => 'ig_account'];
        foreach ((array) ($body['scope'] ?? []) as $item) {
            $kind = (string) ($item['kind'] ?? '');
            $ref = (string) ($item['ref'] ?? '');
            if ($kind === 'client') {
                if (!Db::one('SELECT id FROM clients WHERE id = ?', [(int) $ref])) {
                    continue;
                }
                $ref = (string) (int) $ref;
            } elseif (!isset($types[$kind]) || !Db::one('SELECT id FROM assets WHERE type = ? AND external_id = ?', [$types[$kind], $ref])) {
                continue;
            }
            Db::insert('user_scopes', ['user_id' => $userId, 'kind' => $kind, 'ref' => $ref]);
        }
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
