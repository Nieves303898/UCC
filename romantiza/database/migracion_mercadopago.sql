-- ============================================================
-- ROMANTIZA — Migración: Mercado Pago
-- ------------------------------------------------------------
-- Este archivo es solo para sitios que YA estaban instalados
-- antes de agregar el cobro online con Mercado Pago.
-- Es seguro correrlo más de una vez (no rompe nada si ya lo
-- corriste antes).
--
-- Cómo importarlo en phpMyAdmin:
--   1. Entrá a phpMyAdmin desde hPanel (Hostinger) → Bases de datos.
--   2. Abrí tu base de datos (la que usa el sitio).
--   3. Pestaña "Importar" (no "SQL").
--   4. "Seleccionar archivo" → elegí este archivo (migracion_mercadopago.sql).
--   5. Botón "Continuar" / "Importar" abajo de todo.
--   6. Si dice "La importación se ha realizado con éxito", listo.
--
-- Si te olvidás de correr esta migración, el sitio sigue funcionando
-- igual que antes: la opción "Mercado Pago" en el checkout sigue
-- mostrando el mensaje de "te contactamos por WhatsApp con el link
-- de pago" en vez de cobrar automáticamente, en lugar de romperse.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS mp_preference_id VARCHAR(60) NULL AFTER payment_status;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS mp_payment_id VARCHAR(60) NULL AFTER mp_preference_id;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS mp_status VARCHAR(40) NULL AFTER mp_payment_id;

INSERT INTO settings (setting_key, setting_value) VALUES ('mp_access_token', '') ON DUPLICATE KEY UPDATE setting_key = setting_key;
INSERT INTO settings (setting_key, setting_value) VALUES ('mp_public_key', '') ON DUPLICATE KEY UPDATE setting_key = setting_key;
INSERT INTO settings (setting_key, setting_value) VALUES ('mp_webhook_secret', '') ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ------------------------------------------------------------
-- Después de correr esto, andá al panel del sitio (admin/) →
-- "Textos" → sección "Pagos y envíos" y cargá el Access Token de
-- Mercado Pago (ver la guía paso a paso que te pasamos aparte).
-- ------------------------------------------------------------
