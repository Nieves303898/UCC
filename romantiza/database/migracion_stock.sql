-- ============================================================
-- ROMANTIZA — Migración: Stock por medida/opción
-- ------------------------------------------------------------
-- Este archivo es solo para sitios que YA estaban instalados
-- antes de agregar el control de stock. Seguro de correr más de
-- una vez.
--
-- Cómo importarlo en phpMyAdmin:
--   1. Entrá a phpMyAdmin desde hPanel (Hostinger) → Bases de datos.
--   2. Abrí tu base de datos.
--   3. Pestaña "Importar" (no "SQL").
--   4. "Seleccionar archivo" → elegí este archivo (migracion_stock.sql).
--   5. Botón "Continuar" / "Importar" abajo de todo.
--
-- Si te olvidás de correr esta migración, el sitio sigue funcionando
-- igual: las medidas/opciones se siguen vendiendo sin límite de stock,
-- como pasaba antes, en lugar de romperse.
--
-- Después de correr esto, todas las medidas/opciones que ya tenías
-- cargadas quedan con stock "ilimitado" (no se les puso ningún
-- número). Para empezar a controlar el stock de un producto, entrá a
-- Productos → editalo → cargá un número en el campo Stock de cada
-- medida. Dejarlo vacío mantiene esa medida sin límite.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE product_variants ADD COLUMN IF NOT EXISTS stock INT NULL AFTER price;
