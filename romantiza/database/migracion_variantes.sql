-- ============================================================
-- ROMANTIZA — Migración: Variantes de producto, envío/retiro
-- y categorías por línea de marca
-- ============================================================
-- Corré este archivo UNA VEZ sobre tu base de datos EN VIVO (la que
-- ya tiene productos, textos, administradores y pedidos cargados).
-- Es seguro: no borra nada de lo que ya tenés. Se puede correr más
-- de una vez sin problema.
--
-- Cómo aplicarlo en Hostinger:
--   1. hPanel → Bases de datos → tu base → "Administrar" (phpMyAdmin).
--   2. Elegí tu base de datos → pestaña "SQL".
--   3. Pegá TODO el contenido de este archivo y tocá "Continuar" / "Go".
--   4. Subí los archivos del sitio actualizados.
--   5. Muy importante: purgá el caché de tu hosting (hPanel → Caché →
--      "Vaciar caché") y hacé un refresco forzado del navegador
--      (Ctrl+Shift+R / Cmd+Shift+R) para ver los cambios.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---- Por si todavía no corriste la migración de e-commerce anterior:
-- crea orders/order_items ya con todas las columnas actuales incluidas.
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(20) NOT NULL UNIQUE,
  customer_name VARCHAR(160) NOT NULL,
  customer_phone VARCHAR(60) NOT NULL,
  customer_email VARCHAR(160),
  customer_address VARCHAR(255),
  customer_notes TEXT,
  payment_method ENUM('mercadopago','transferencia','efectivo') NOT NULL,
  payment_status ENUM('pendiente','confirmado','cancelado') NOT NULL DEFAULT 'pendiente',
  order_status ENUM('nuevo','en_proceso','enviado','entregado','cancelado') NOT NULL DEFAULT 'nuevo',
  shipping_method ENUM('envio','retiro') NOT NULL DEFAULT 'envio',
  shipping_cost DECIMAL(12,2) NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  admin_notes TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_variants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  label VARCHAR(120) NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NULL,
  variant_id INT NULL,
  product_name VARCHAR(160) NOT NULL,
  variant_label VARCHAR(120) NULL,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  quantity INT NOT NULL DEFAULT 1,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Si orders/order_items YA existían (de la migración anterior),
-- esto les agrega las columnas nuevas que todavía no tengan.
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS shipping_method ENUM('envio','retiro') NOT NULL DEFAULT 'envio' AFTER order_status,
  ADD COLUMN IF NOT EXISTS shipping_cost DECIMAL(12,2) NULL AFTER shipping_method;

ALTER TABLE order_items
  ADD COLUMN IF NOT EXISTS variant_id INT NULL AFTER product_id,
  ADD COLUMN IF NOT EXISTS variant_label VARCHAR(120) NULL AFTER product_name;

SET FOREIGN_KEY_CHECKS = 1;

-- ---- Categorías nuevas (líneas de la marca). Las categorías viejas
-- (Mantelería, Almohadones, Cocina, Puntillas) NO se borran — quedan
-- ahí sin usarse y el sitio no las muestra si no tienen productos.
-- Si en algún momento querés borrarlas del todo, primero recategorizá
-- a mano cualquier producto que todavía las use.
INSERT INTO categories (slug, name, sort_order) VALUES
  ('linea-jardin-romantiza', 'Línea Jardín Romantiza', 1),
  ('linea-mediterranea', 'Línea Mediterránea', 2),
  ('linea-tierra-algodon', 'Línea Tierra & Algodón', 3)
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order);

INSERT INTO settings (setting_key, setting_value) VALUES
  ('pickup_address', 'Sáenz Peña, Chaco (te pasamos la dirección exacta por WhatsApp al coordinar el retiro).')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

UPDATE settings SET setting_value = 'El costo de envío varía según la zona y se cotiza por WhatsApp una vez confirmado el pedido. También podés retirarlo en persona en Sáenz Peña, Chaco.'
WHERE setting_key = 'shipping_note' AND setting_value = 'Coordinamos el envío por WhatsApp una vez confirmado el pedido. Envíos a todo el país.';

-- ============================================================
-- Después de correr esto, cargá tus productos reales con
-- database/cargar_productos_romantiza.sql (ver ese archivo).
-- ============================================================
