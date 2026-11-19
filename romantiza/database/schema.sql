-- ============================================================
-- ROMANTIZA — Esquema de base de datos
-- Importar este archivo completo desde phpMyAdmin (o la herramienta
-- de base de datos de tu hosting) en la base de datos vacía que
-- hayas creado para el sitio.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Administradores del panel
-- role: 'superadmin' (control total, incluida gestión de usuarios)
--       'editor'      (solo puede editar textos, imágenes y productos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  role ENUM('superadmin','editor') NOT NULL DEFAULT 'editor',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Textos y configuración editable del sitio (clave / valor)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Categorías de producto: incluye tanto "líneas" (colecciones de
-- diseño propias de la marca, ej. Línea Mediterránea) como
-- "categorías" comunes de tipo de producto (ej. Mantelería,
-- Puntillas). Ambas vivan en la misma tabla porque un producto
-- solo tiene un category_id, pero is_linea distingue cuáles se
-- muestran en la sección editorial "Nuestras líneas" de la
-- portada y cuáles no.
-- image: foto de la línea para "Cada rincón, con su textil" en
-- portada (opcional — se sube desde el panel, Líneas y categorías).
-- Solo se usa para líneas; las categorías comunes no llevan foto.
-- is_linea: 1 = línea de diseño (aparece en la portada), 0 =
-- categoría común (solo se usa para filtrar en la tienda).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  image VARCHAR(255),
  is_linea TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Slides del carrusel de portada (2 a 5 recomendado). Se editan
-- desde el panel, sección Portada.
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Videos de Instagram para mostrar en la portada (sección "Videos").
-- Se pega el link del reel/publicación, no se sube el archivo.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS home_videos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  instagram_url VARCHAR(500) NOT NULL,
  caption VARCHAR(160),
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Productos
-- price: precio "desde" — se recalcula solo como el menor precio
-- entre las variantes del producto (ver tabla product_variants).
-- No se edita a mano: lo mantiene el panel al guardar variantes.
-- tiendanube_url: columna en desuso, se deja por compatibilidad con
-- versiones anteriores del sitio (ya no se usa: la compra ahora se
-- hace con el carrito propio, ver tablas orders/order_items).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  description TEXT,
  image VARCHAR(255),
  instagram_video_url VARCHAR(500),
  tiendanube_url VARCHAR(500),
  flag VARCHAR(40),
  featured TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Variantes de producto (medida, tamaño o presentación — cada una
-- con su propio precio). Todo producto necesita al menos una.
-- Ejemplo: "1,50 × 2,00 mts" a $67.400 y "1,50 × 2,50 mts" a $70.000.
-- stock: NULL = sin control de stock (se puede vender sin límite,
-- comportamiento de siempre). Un número = unidades disponibles; se
-- descuenta solo al confirmarse un pedido, y en 0 la variante se
-- muestra como "Sin stock" y no se puede comprar.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_variants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  label VARCHAR(120) NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock INT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Fotos adicionales de un producto (además de la foto de portada,
-- que sigue guardada en products.image). Se administran desde el
-- panel, Productos → editar → "Fotos adicionales (galería)": ahí se
-- pueden agregar varias, elegir cuál pasa a ser la portada, o
-- eliminarlas. Se muestran en la ficha del producto, todas con el
-- mismo tamaño (la tienda las recorta sola con CSS, sin importar el
-- tamaño o forma original de cada foto).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  image VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Contactos de WhatsApp captados desde el popup de la web (pensado
-- para campañas puntuales, como el Día de la Madre, pero queda
-- disponible para usar cuando quieras).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS whatsapp_leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160),
  whatsapp VARCHAR(40) NOT NULL,
  source VARCHAR(60) NOT NULL DEFAULT 'popup_web',
  contacted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Mensajes de contacto y suscripciones al newsletter
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('contacto','newsletter') NOT NULL,
  name VARCHAR(160),
  email VARCHAR(160) NOT NULL,
  message TEXT,
  read_flag TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Pedidos (carrito propio / checkout propio)
-- payment_method: 'mercadopago' (pago online real vía Checkout Pro si
--                 está configurado el Access Token; si no, se coordina
--                 el link de pago por WhatsApp), 'transferencia', 'efectivo'
-- payment_status: 'pendiente' hasta que se confirme el pago
-- mp_preference_id / mp_payment_id / mp_status: datos que devuelve
-- Mercado Pago para el pedido (quedan NULL si no se pagó con Mercado Pago
-- o si Mercado Pago todavía no está configurado)
-- shipping_method: 'envio' (a domicilio, costo se cotiza por zona)
--                   o 'retiro' (retiro en el local, sin costo de envío)
-- shipping_cost: NULL hasta que el admin cotiza el envío y lo carga
-- ------------------------------------------------------------
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
  mp_preference_id VARCHAR(60) NULL,
  mp_payment_id VARCHAR(60) NULL,
  mp_status VARCHAR(40) NULL,
  order_status ENUM('nuevo','en_proceso','enviado','entregado','cancelado') NOT NULL DEFAULT 'nuevo',
  shipping_method ENUM('envio','retiro') NOT NULL DEFAULT 'envio',
  shipping_cost DECIMAL(12,2) NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  admin_notes TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Datos iniciales: categorías (líneas de la marca)
-- ------------------------------------------------------------
INSERT INTO categories (slug, name, is_linea, sort_order) VALUES
  ('linea-jardin-romantiza', 'Línea Jardín Romantiza', 1, 1),
  ('linea-mediterranea', 'Línea Mediterránea', 1, 2),
  ('linea-tierra-algodon', 'Línea Tierra & Algodón', 1, 3);

-- ------------------------------------------------------------
-- Datos iniciales: textos editables del sitio
-- (el admin puede cambiar todo esto desde el panel, sección "Textos")
-- ------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('site_title', 'Romantiza — Deco textil para el hogar'),
  ('site_description', 'Romantiza: textiles de hogar artesanales — mantelería, almohadones, puntillas y más. Algodón y lino natural, estampado a mano. Envíos a todo el país desde Sáenz Peña, Chaco.'),
  ('hero_title', 'Todo lo que buscás para hacer de tu casa <em>tu lugar favorito</em>.'),
  ('hero_lead', 'Textiles de algodón y lino, estampados a mano y puntillas artesanales — la calidez que le faltaba a tu hogar, directo desde Sáenz Peña, Chaco.'),
  ('about_title', 'Hilos de ternura, <em>tejidos a mano</em>.'),
  ('about_lead', 'Romantiza nace en Sáenz Peña, Chaco, con una idea simple: que cada textil de tu casa tenga la calidez de lo hecho a mano.'),
  ('about_text_1', 'Seleccionamos telas naturales — algodón y lino, sobre todo — porque son las que mejor envejecen y mejor abrazan una casa. A partir de ahí llega el trabajo artesanal: estampados, puntillas y terminaciones pensadas a mano, una por una.'),
  ('about_text_2', 'Creemos en un hogar cálido y acogedor, con detalles que se notan de cerca. Por eso cada pieza Romantiza busca acompañarte por mucho tiempo, sin pasar de moda.'),
  ('contact_address', 'Sáenz Peña, Chaco — envíos a todo el país'),
  ('contact_phone', '3644 550258'),
  ('contact_whatsapp', '5493644550258'),
  ('contact_instagram', '@romantiza1974'),
  ('contact_instagram_url', 'https://www.instagram.com/romantiza1974/'),
  ('contact_hours', 'Lunes a viernes, 9 a 18 h'),
  ('tiendanube_store_url', ''),
  ('payment_transfer_info', 'Alias: romantiza.textiles\nCBU: (completar)\nTitular: (completar)\nEnviá el comprobante por WhatsApp para confirmar tu pedido.'),
  ('shipping_note', 'El costo de envío varía según la zona y se cotiza por WhatsApp una vez confirmado el pedido. También podés retirarlo en persona en Sáenz Peña, Chaco.'),
  ('pickup_address', 'Sáenz Peña, Chaco (te pasamos la dirección exacta por WhatsApp al coordinar el retiro).'),
  ('free_shipping_threshold', ''),
  ('trust_badge_1', 'Compra 100% segura'),
  ('trust_badge_2', 'Envío a todo el país'),
  ('trust_badge_3', 'Cambios sin cargo'),
  ('trust_badge_4', 'Pago protegido'),
  ('wa_popup_enabled', ''),
  ('wa_popup_title', '¡Regalo especial para el Día de la Madre! 🎁'),
  ('wa_popup_text', 'Dejanos tu WhatsApp y te mandamos un cupón especial + las novedades antes que nadie.'),
  ('wa_popup_button_text', 'Quiero mi regalo'),
  ('wa_popup_whatsapp_message', 'Hola! Quiero el regalo especial de Romantiza 🎁'),
  ('essence_image', ''),
  ('mp_access_token', ''),
  ('mp_public_key', ''),
  ('mp_webhook_secret', '');

-- ============================================================
-- IMPORTANTE: crear el primer superadmin
-- Por seguridad NO se incluye acá un usuario con contraseña fija.
-- Corré el archivo admin/instalar.php una sola vez desde el
-- navegador después de subir el sitio: te va a pedir que definas
-- el usuario y la contraseña del superadmin, y se borra solo al
-- terminar. Ver README.md, paso 4.
-- ============================================================
