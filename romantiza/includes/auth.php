<?php
if (!defined('ROMANTIZA_APP')) { http_response_code(403); exit('Acceso directo no permitido.'); }

/** Inicia la sesión con cookies seguras (una sola vez por request). */
function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('romantiza_admin_sid');
    session_start();
}

/** Token anti-CSRF para los formularios del panel. */
function csrf_token(): string {
    start_secure_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): bool {
    start_secure_session();
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/**
 * Intenta iniciar sesión. Devuelve el array del admin si las
 * credenciales son correctas, o null si no.
 */
function attempt_login(PDO $pdo, string $username, string $password): ?array {
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ? AND active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        // Nota: se compara igual aunque el usuario no exista, para no filtrar
        // por tiempo de respuesta si el usuario existe o no.
        password_verify($password, '$2y$10$invalidinvalidinvalidinvalidinvalidinva');
        return null;
    }
    start_secure_session();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['id'];

    $upd = $pdo->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?');
    $upd->execute([$admin['id']]);

    return $admin;
}

function logout(): void {
    start_secure_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Exige estar logueado. Si no, redirige a login.php.
 * Si lo está, devuelve el array del admin actual (recién leído de la
 * base, así un usuario desactivado pierde el acceso al instante).
 */
function require_login(PDO $pdo): array {
    start_secure_session();
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND active = 1 LIMIT 1');
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    if (!$admin) {
        logout();
        header('Location: login.php?desactivado=1');
        exit;
    }
    return $admin;
}

/** Exige rol superadmin. Cortar la ejecución con 403 si no lo es. */
function require_superadmin(array $admin): void {
    if ($admin['role'] !== 'superadmin') {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:3rem;text-align:center">';
        echo '<h1>Acceso restringido</h1><p>Esta sección es solo para el administrador principal.</p>';
        echo '<p><a href="index.php">&larr; Volver al panel</a></p>';
        exit;
    }
}
