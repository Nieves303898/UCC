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
            // role: admin (agencia, ve todo) | client (sólo ve lo asignado a su client_id)
            "CREATE TABLE IF NOT EXISTS users (
                id $id,
                username VARCHAR(190) NOT NULL UNIQUE,
                email VARCHAR(190) NULL,
                name VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'admin',
                client_id INT NULL,
                created_at DATETIME NOT NULL,
                last_login_at DATETIME NULL
            )$suffix",

            "CREATE TABLE IF NOT EXISTS clients (
                id $id,
                name VARCHAR(190) NOT NULL,
                color VARCHAR(20) NOT NULL DEFAULT '#0e7c66',
                logo_url VARCHAR(500) NULL,
                notes TEXT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'ARS',
                sales_label VARCHAR(60) NOT NULL DEFAULT 'Ventas',
                leads_label VARCHAR(60) NOT NULL DEFAULT 'Consultas',
                dim_label VARCHAR(60) NOT NULL DEFAULT 'Sucursal',
                target_orders DECIMAL(14,2) NULL,
                target_revenue DECIMAL(16,2) NULL,
                created_at DATETIME NOT NULL
            )$suffix",

            "CREATE TABLE IF NOT EXISTS client_assets (
                client_id INT NOT NULL,
                asset_type VARCHAR(20) NOT NULL,
                external_id VARCHAR(64) NOT NULL,
                UNIQUE (client_id, asset_type, external_id)
            )$suffix",

            // Lo que la agencia está haciendo para el cliente (lo ve en su portal).
            "CREATE TABLE IF NOT EXISTS client_updates (
                id $id,
                client_id INT NOT NULL,
                kind VARCHAR(30) NOT NULL DEFAULT 'otro',
                status VARCHAR(20) NOT NULL DEFAULT 'en_curso',
                title VARCHAR(255) NOT NULL,
                body TEXT NULL,
                link VARCHAR(500) NULL,
                due_date DATE NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
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
            // Ventas propias del cliente (Excel, Google Sheets o carga manual).
            "CREATE TABLE IF NOT EXISTS sales_sources (
                id $id,
                client_id INT NOT NULL,
                name VARCHAR(190) NOT NULL,
                kind VARCHAR(20) NOT NULL,
                url TEXT NULL,
                mapping_json TEXT NULL,
                rows_count INT NOT NULL DEFAULT 0,
                last_sync_at DATETIME NULL,
                last_error TEXT NULL,
                created_at DATETIME NOT NULL
            )$suffix",

            "CREATE TABLE IF NOT EXISTS sales_daily (
                id $id,
                client_id INT NOT NULL,
                source_id INT NOT NULL,
                day DATE NOT NULL,
                dim VARCHAR(120) NOT NULL DEFAULT '',
                orders DECIMAL(14,2) NOT NULL DEFAULT 0,
                revenue DECIMAL(16,2) NOT NULL DEFAULT 0,
                leads DECIMAL(14,2) NULL,
                UNIQUE (source_id, day, dim)
            )$suffix",

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
