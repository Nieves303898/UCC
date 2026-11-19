-- ============================================================
-- ROMANTIZA — Migración: slide de portada, foto de esencia,
-- fotos de las líneas y videos de Instagram
-- ============================================================
-- Corré este archivo UNA VEZ sobre tu base de datos EN VIVO.
-- Es seguro: no borra nada de lo que ya tenés. Se puede correr más
-- de una vez sin problema.
--
-- Cómo aplicarlo en Hostinger:
--   1. hPanel → Bases de datos → tu base → "Administrar" (phpMyAdmin).
--   2. Elegí tu base de datos → pestaña "Importar".
--   3. "Seleccionar archivo" → elegí este archivo → "Continuar" / "Go".
--   4. Subí los archivos del sitio actualizados.
--   5. Purgá el caché de tu hosting (hPanel → Caché → "Vaciar caché")
--      y hacé un refresco forzado del navegador (Ctrl+Shift+R /
--      Cmd+Shift+R) para ver los cambios.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS hero_slides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  image VARCHAR(255) NOT NULL,
  title VARCHAR(160),
  subtitle VARCHAR(255),
  link_url VARCHAR(255),
  link_text VARCHAR(60),
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS home_videos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  instagram_url VARCHAR(500) NOT NULL,
  caption VARCHAR(160),
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE categories
  ADD COLUMN IF NOT EXISTS image VARCHAR(255) NULL AFTER name;

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS instagram_video_url VARCHAR(500) NULL AFTER description;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('essence_image', '')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============================================================
-- Después de correr esto, entrá al panel:
--  - "Portada": para cargar los 2-3 slides de la portada, la foto
--    de "Nuestra esencia" y los videos de Instagram del home.
--  - "Líneas": para cargar la foto de cada línea de productos.
--  - "Productos" → editar cada uno: para el video de Instagram
--    de ese producto en particular (opcional).
-- ============================================================
