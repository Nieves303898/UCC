<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Auth;
use App\Config;
use App\Db;
use App\Meta\OAuth;
use App\Schema;
use App\Services\AssetSync;
use App\Services\Connections;

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');

function view(string $name, array $vars = []): never
{
    extract($vars);
    require APP_ROOT . '/app/views/' . $name . '.php';
    exit;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$route = (string) ($_GET['r'] ?? '');

// Primera vez: crear tablas y usuario administrador.
$installed = Schema::isInstalled() && Db::one('SELECT id FROM users LIMIT 1');
if (!$installed) {
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
                throw new RuntimeException('Sesión vencida, recargá la página.');
            }
            App\Crypto::encrypt('test'); // valida app_key
            Schema::install();
            if (!Db::one('SELECT id FROM users LIMIT 1')) {
                Auth::createUser((string) ($_POST['login'] ?? ''), (string) ($_POST['name'] ?? ''), (string) ($_POST['password'] ?? ''));
            }
            Auth::attempt((string) $_POST['login'], (string) $_POST['password']);
            redirect('index.php#/settings');
        } catch (Throwable $ex) {
            $error = $ex->getMessage();
        }
    }
    view('auth', ['mode' => 'install', 'error' => $error]);
}

switch ($route) {
    case 'login':
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (Auth::checkCsrf($_POST['csrf'] ?? null) && Auth::attempt((string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                redirect('index.php');
            }
            $error = 'Usuario o contraseña incorrectos.';
        }
        view('auth', ['mode' => 'login', 'error' => $error]);

    case 'logout':
        Auth::logout();
        redirect('index.php?r=login');

    case 'oauth-start':
        if (!Auth::user() || Auth::user()['role'] !== 'admin') {
            redirect('index.php?r=login');
        }
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
        redirect(OAuth::loginUrl($_SESSION['oauth_state']));

    case 'oauth-callback':
        if (!Auth::user()) {
            redirect('index.php?r=login');
        }
        $state = (string) ($_GET['state'] ?? '');
        $expected = $_SESSION['oauth_state'] ?? '';
        unset($_SESSION['oauth_state']);
        if (!$expected || !hash_equals($expected, $state)) {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'La conexión con Meta no es válida (state). Intentá de nuevo.'];
            redirect('index.php#/settings');
        }
        if (!empty($_GET['error'])) {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'Cancelaste o Meta rechazó la conexión: ' . ($_GET['error_description'] ?? $_GET['error'])];
            redirect('index.php#/settings');
        }
        try {
            $token = OAuth::exchangeCode((string) ($_GET['code'] ?? ''));
            $id = Connections::save($token['access_token'], 'oauth', null, isset($token['expires_in']) ? (int) $token['expires_in'] : null);
            $sync = AssetSync::sync($id);
            $c = $sync['counts'];
            $_SESSION['flash'] = ['type' => 'success', 'text' => sprintf(
                'Conectado. Encontramos %d portfolios, %d cuentas publicitarias, %d páginas y %d cuentas de Instagram.',
                $c['business'], $c['ad_account'], $c['page'], $c['ig_account']
            )];
        } catch (Throwable $ex) {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'No se pudo conectar: ' . $ex->getMessage()];
        }
        redirect('index.php#/settings');

    default:
        if (!Auth::user()) {
            redirect('index.php?r=login');
        }
        Schema::install(); // asegura tablas nuevas tras actualizar
        try {
            \App\Services\Sales::dedupeAll(); // una misma planilla conectada varias veces cuenta una sola vez
        } catch (\Throwable) {
        }
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        view('app', ['flash' => $flash]);
}
