-- ============================================================
-- ROMANTIZA — Migración: Líneas y categorías por separado
-- ------------------------------------------------------------
-- Este archivo es solo para sitios que YA estaban instalados
-- antes de poder distinguir "líneas" (colecciones de diseño
-- propias, ej. Línea Mediterránea) de "categorías" comunes
-- (ej. Mantelería, Puntillas). Seguro de correr más de una vez.
--
-- Cómo importarlo en phpMyAdmin:
--   1. Entrá a phpMyAdmin desde hPanel (Hostinger) → Bases de datos.
--   2. Abrí tu base de datos.
--   3. Pestaña "Importar" (no "SQL").
--   4. "Seleccionar archivo" → elegí este archivo
--      (migracion_lineas_categorias.sql).
--   5. Botón "Continuar" / "Importar" abajo de todo.
--
-- Qué hace: agrega una columna is_linea a la tabla categories y
-- marca como línea a las 3 líneas originales de la marca (Jardín
-- Romantiza, Mediterránea, Tierra & Algodón). Cualquier otra
-- categoría que ya tuvieras cargada (por ejemplo Mantelería o
-- Puntillas) queda marcada como categoría común automáticamente,
-- así que deja de aparecer mezclada en la sección "Nuestras
-- líneas" de la portada.
--
-- Si te olvidás de correr esta migración, el sitio sigue
-- funcionando igual, pero la sección "Nuestras líneas" de la
-- portada va a seguir mostrando todas las categorías juntas
-- (líneas y categorías comunes) en vez de solo las líneas.
--
-- Después de correr esto, entrá al panel → Líneas y categorías
-- para revisar que todo esté en el grupo correcto, y desde ahí
-- también vas a poder agregar nuevas líneas o categorías cuando
-- quieras.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE categories ADD COLUMN IF NOT EXISTS is_linea TINYINT(1) NOT NULL DEFAULT 0 AFTER image;

UPDATE categories SET is_linea = 1
  WHERE slug IN ('linea-jardin-romantiza', 'linea-mediterranea', 'linea-tierra-algodon');
