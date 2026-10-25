<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $cfg = Config::get('db');
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (($cfg['driver'] ?? 'mysql') === 'sqlite') {
            self::$pdo = new PDO('sqlite:' . $cfg['sqlite_path'], null, null, $options);
            self::$pdo->exec('PRAGMA journal_mode=WAL');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['host'],
                (int) ($cfg['port'] ?? 3306),
                $cfg['database']
            );
            self::$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], $options);
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        return (string) Config::get('db.driver', 'mysql');
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::exec($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** UPDATE simple por igualdad: $where = ['id' => 5]. */
    public static function update(string $table, array $data, array $where): int
    {
        $set = implode(',', array_map(fn ($c) => "$c = ?", array_keys($data)));
        $cond = implode(' AND ', array_map(fn ($c) => "$c = ?", array_keys($where)));
        return self::exec("UPDATE $table SET $set WHERE $cond", [...array_values($data), ...array_values($where)]);
    }

    /** INSERT o UPDATE según la clave única $unique. */
    public static function upsert(string $table, array $data, array $unique): void
    {
        $cols = array_keys($data);
        $updates = array_diff($cols, $unique);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        if (self::driver() === 'sqlite') {
            $set = implode(',', array_map(fn ($c) => "$c=excluded.$c", $updates));
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT(%s) DO UPDATE SET %s',
                $table, implode(',', $cols), $placeholders, implode(',', $unique), $set
            );
        } else {
            $set = implode(',', array_map(fn ($c) => "$c=VALUES($c)", $updates));
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                $table, implode(',', $cols), $placeholders, $set
            );
        }
        self::exec($sql, array_values($data));
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
