<?php
declare(strict_types=1);

namespace App;

/** Login de usuarios del panel + protección CSRF. */
final class Auth
{
    public static function attempt(string $email, string $password): bool
    {
        $user = Db::one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
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
                : Db::one('SELECT id, email, name, role FROM users WHERE id = ?', [$_SESSION['user_id']]);
        }
        return $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function createUser(string $email, string $name, string $password, string $role = 'admin'): int
    {
        return Db::insert('users', [
            'email'         => mb_strtolower(trim($email)),
            'name'          => trim($name),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
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
