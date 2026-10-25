<?php
declare(strict_types=1);

namespace App;

/** Login de usuarios del panel + protección CSRF. */
final class Auth
{
    /** $login puede ser el usuario o el email. */
    public static function attempt(string $login, string $password): bool
    {
        $login = mb_strtolower(trim($login));
        $user = Db::one('SELECT * FROM users WHERE username = ? OR email = ?', [$login, $login]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            usleep(400000); // freno básico a fuerza bruta
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        Db::exec('UPDATE users SET last_login_at = ? WHERE id = ?', [Db::now(), $user['id']]);
        return true;
    }

    public static function user(): ?array
    {
        static $user = false;
        if ($user === false) {
            $user = empty($_SESSION['user_id'])
                ? null
                : Db::one('SELECT id, username, email, name, role, client_id FROM users WHERE id = ?', [$_SESSION['user_id']]);
            if ($user && $user['role'] === 'client') {
                try { // columna nueva: puede no existir hasta que corra la migración
                    $user['client_role'] = Db::one('SELECT client_role FROM users WHERE id = ?', [$user['id']])['client_role'] ?? null;
                } catch (\Throwable) {
                    $user['client_role'] = null;
                }
            }
        }
        return $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function createUser(string $username, string $name, string $password, string $role = 'admin', ?int $clientId = null, ?string $email = null): int
    {
        $username = mb_strtolower(trim($username));
        if (!preg_match('/^[a-z0-9._@+-]{3,190}$/', $username)) {
            throw new \InvalidArgumentException('El usuario sólo puede tener letras, números y . _ - @ (mínimo 3).');
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }
        if (Db::one('SELECT id FROM users WHERE username = ? OR email = ?', [$username, $username])) {
            throw new \InvalidArgumentException('Ese usuario ya existe.');
        }
        return Db::insert('users', [
            'username'      => $username,
            'email'         => $email ? mb_strtolower(trim($email)) : (filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : null),
            'name'          => trim($name) ?: $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => in_array($role, ['client', 'viewer', 'member'], true) ? $role : 'admin',
            'client_id'     => $role === 'client' ? $clientId : null,
            'created_at'    => Db::now(),
        ]);
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }
}
