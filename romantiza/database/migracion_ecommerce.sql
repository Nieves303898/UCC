-- ============================================================
-- ROMANTIZA — Migración: Carrito de compras propio / Pedidos
-- ============================================================
-- Este archivo es SEGURO para correr sobre tu base de datos EN VIVO
-- (la que ya tiene tus productos, categorías, textos y administradores
-- cargados). Solo agrega las tablas y configuraciones nuevas que
-- necesita el carrito de compras — no borra ni modifica nada de lo
-- que ya tenés.
--
-- Cómo aplicarlo en Hostinger:
--   1. Entrá a hPanel → Bases de datos → tu base → "Administrar" (phpMyAdmin).
--   2. Elegí tu base de datos en la columna izquierda.
--   3. Arriba, pestaña "SQL".
--   4. Pegá TODO el contenido de este archivo y tocá "Continuar" / "Go".
--   5. Listo — ya podés subir los archivos nuevos del sitio.
--
-- Se puede correr más de una vez sin problema (usa IF NOT EXISTS e
-- INSERT ... ON DUPLICATE KEY UPDATE en todos lados).
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  admin_notes TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NULL,
  product_name VARCHAR(160) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  quantity INT NOT NULL DEFAULT 1,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('payment_transfer_info', 'Alias: romantiza.textiles\nCBU: (completar)\nTitular: (completar)\nEnviá el comprobante por WhatsApp para confirmar tu pedido.'),
  ('shipping_note', 'Coordinamos el envío por WhatsApp una vez confirmado el pedido. Envíos a todo el país.')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============================================================
-- Después de correr esto, entrá al panel → Textos → sección
-- "Pagos y envíos" y completá el alias/CBU real para transferencias.
-- ============================================================
