<?php
declare(strict_types=1);

namespace App;

/** Crea las tablas. Es idempotente (CREATE TABLE IF NOT EXISTS). */
final class Schema
{
    public static function install(): void
    {
        $mysql = Db::driver() !== 'sqlite';
        $id = $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $tables = [
            "CREATE TABLE IF NOT EXISTS users (
                id $id,
                email VARCHAR(190) NOT NULL UNIQUE,
                name VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'admin',
                created_at DATETIME NOT NULL,
                last_login_at DATETIME NULL
            )$suffix",

            "CREATE TABLE IF NOT EXISTS meta_connections (
                id $id,
                label VARCHAR(190) NOT NULL,
                meta_user_id VARCHAR(64) NULL,
                meta_user_name VARCHAR(190) NULL,
                access_token_enc TEXT NOT NULL,
                token_source VARCHAR(20) NOT NULL DEFAULT 'oauth',
                expires_at DATETIME NULL,
                scopes TEXT NULL,
                last_sync_at DATETIME NULL,
                last_error TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )$suffix",

            // type: business | ad_account | page | ig_account
            "CREATE TABLE IF NOT EXISTS assets (
                id $id,
                connection_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                external_id VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                parent_id VARCHAR(64) NULL,
                business_id VARCHAR(64) NULL,
                data_json $text NULL,
                access_token_enc TEXT NULL,
                is_hidden TINYINT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                UNIQUE (type, external_id)
            )$suffix",

            "CREATE TABLE IF NOT EXISTS api_cache (
                cache_key VARCHAR(64) NOT NULL PRIMARY KEY,
                payload $text NOT NULL,
                created_at INT NOT NULL,
                expires_at INT NOT NULL
            )$suffix",

            // Fotos diarias (seguidores, etc.) que Meta no guarda como historial.
            "CREATE TABLE IF NOT EXISTS daily_snapshots (
                id $id,
                asset_type VARCHAR(20) NOT NULL,
                external_id VARCHAR(64) NOT NULL,
                snap_date DATE NOT NULL,
                metrics_json $text NOT NULL,
                UNIQUE (asset_type, external_id, snap_date)
            )$suffix",

            "CREATE TABLE IF NOT EXISTS webhook_events (
                id $id,
                object VARCHAR(40) NOT NULL,
                object_id VARCHAR(64) NULL,
                field VARCHAR(60) NULL,
                payload $text NOT NULL,
                received_at DATETIME NOT NULL
            )$suffix",

            "CREATE TABLE IF NOT EXISTS sync_log (
                id $id,
                job VARCHAR(60) NOT NULL,
                status VARCHAR(20) NOT NULL,
                message TEXT NULL,
                created_at DATETIME NOT NULL
            )$suffix",
        ];

        foreach ($tables as $sql) {
            Db::pdo()->exec($sql);
        }
    }

    public static function isInstalled(): bool
    {
        try {
            Db::one('SELECT id FROM users LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
