-- ============================================================
-- ROMANTIZA — Carga del catálogo real (20 productos del listado
-- que nos pasó el cliente, organizados por línea de marca)
-- ============================================================
-- Corré esto DESPUÉS de database/migracion_variantes.sql (necesita
-- las tablas product_variants y las categorías por línea ya creadas).
--
-- Es seguro correrlo más de una vez: cada producto se identifica por
-- su "slug" único, así que si lo volvés a correr actualiza los datos
-- en lugar de duplicar productos. Las variantes (medidas/opciones)
-- se reemplazan por completo en cada corrida, así que si en el panel
-- ya modificaste a mano las variantes de alguno de estos productos,
-- correr este archivo de nuevo las pisa con estos valores originales.
--
-- Dos productos del listado original (Almohadón y Delantal, filas 14
-- y 15) venían con colores de DOS líneas distintas a la vez. Como el
-- sitio organiza todo por línea, cada uno se cargó como dos productos
-- separados (uno por línea, con su color correspondiente) en lugar de
-- inventar una variante de "color" que no existía en el listado.
--
-- Las fotos quedan pendientes: se suben después desde el panel,
-- editando cada producto (Productos → ícono de lápiz).
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- LÍNEA JARDÍN ROMANTIZA
-- ------------------------------------------------------------

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-jardin-romantiza'),
  'Mantel arena y rojo envejecido', 'mantel-jardin-arena-rojo-envejecido', 0,
  'Un mantel en tussor a rayas arena y rojo envejecido, con borlas hechas a mano en las cuatro puntas. Pensado para una mesa de 6 sillas: vestí la mesa de todos los días o la de una ocasión especial sin que se note la diferencia.',
  1, 1, 1)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '1,50 × 2,00 m — mesa de 6 sillas', 67400, 0, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-jardin-romantiza'),
  'Mantel rosado antiguo', 'mantel-jardin-rosado-antiguo', 0,
  'Tussor liviano en rosado antiguo, terminado con puntilla de algodón en todo el borde. Disponible en dos tamaños para mesas de 6 u 8 sillas, así elegís el que le queda justo a la tuya.',
  0, 1, 2)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '1,50 × 2,00 m — mesa de 6 sillas', 67400, 0, 1),
  (@pid, '1,50 × 2,50 m — mesa de 8 sillas', 70000, 1, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-jardin-romantiza'),
  'Mantel blanco antiguo', 'mantel-jardin-blanco-antiguo', 0,
  'Blanco antiguo con puntilla de algodón en los cuatro lados: el mantel que combina con cualquier vajilla y queda bien temporada tras temporada. Tela tussor de caída suave, para una mesa de 6 sillas.',
  0, 1, 3)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '1,50 × 2,00 m — mesa de 6 sillas', 67400, 0, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-jardin-romantiza'),
  'Mantel floreado durazno y rosa viejo', 'mantel-jardin-floreado', 0,
  'Fibrana floreada en tonos durazno, rosa viejo y arena — una estampa delicada que le da otro aire a la mesa sin gritar. Para una mesa de 6 sillas.',
  0, 1, 4)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '1,50 × 2,00 m — mesa de 6 sillas', 67400, 0, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-jardin-romantiza'),
  'Camino de mesa rosado y blanco antiguo', 'camino-mesa-jardin-rosado-blanco', 0,
  'Tussor y gasa blanco antiguo combinados en un camino de mesa liviano, en dos largos a elección. El detalle que completa el mantel, o que solo, sobre una mesa de madera, ya dice mucho.',
  0, 1, 5)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '1,80 × 0,40 m', 27200, 0, 1),
  (@pid, '1,50 × 0,40 m', 32900, 1, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-jardin-romantiza'),
  'Individuales rosado antiguo y floreado', 'individuales-jardin-rosado-floreado', 0,
  'Individuales en tussor y fibrana floreada, en rosado antiguo. Se venden por unidad o en set de 4, para armar la mesa completa de una sola vez.',
  0, 1, 6)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, 'Unidad — 0,35 × 0,45 cm', 5000, 0, 1),
  (@pid, 'Set × 4', 19500, 1, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-jardin-romantiza'),
  'Almohadón rosado antiguo y floreado', 'almohadon-jardin-rosado-floreado', 0,
  'Almohadones en tussor y fibrana floreada, disponibles en 40×40 o 50×50, con funda sola o con relleno incluido. Sumá uno o varios: en un sillón o una cama, cambian el ambiente al instante.',
  1, 1, 7)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '40×40 — funda sola', 24900, 0, 1),
  (@pid, '40×40 — con relleno', 32900, 1, 1),
  (@pid, '50×50 — funda sola', 26900, 2, 1),
  (@pid, '50×50 — con relleno', 37900, 3, 1);

-- ------------------------------------------------------------
-- LÍNEA MEDITERRÁNEA
-- ------------------------------------------------------------

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-mediterranea'),
  'Mantel azul desgastado', 'mantel-mediterranea-azul-desgastado', 0,
  'Tussor azul desgastado con puntilla de algodón y pequeñas estampas en las puntas. Un mantel con carácter, para una mesa de 6 sillas que pide algo distinto.',
  1, 1, 1)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '1,50 × 2,00 m — mesa de 6 sillas', 64900, 0, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-mediterranea'),
  'Mantel arena y azul desgastado', 'mantel-mediterranea-arena-azul', 0,
  'Rayas arena y azul desgastado en tussor, con puntilla de algodón en el borde. Fresco y versátil, para una mesa de 6 sillas todos los días.',
  0, 1, 2)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '1,50 × 2,00 m — mesa de 6 sillas', 59900, 0, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-mediterranea'),
  'Servilletas arena y azul desgastado', 'servilletas-mediterranea-arena-azul', 0,
  'Servilletas de tussor a rayas arena y azul desgastado, en presentaciones de 1, 2, 4 o 6 unidades. Combinalas con el mantel de la misma línea o usalas solas para un cambio rápido.',
  0, 1, 3)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, 'Unidad — 0,45 × 0,45 cm', 6900, 0, 1),
  (@pid, 'Set × 2', 12900, 1, 1),
  (@pid, 'Set × 4', 24900, 2, 1),
  (@pid, 'Set × 6', 35900, 3, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-mediterranea'),
  'Camino de mesa azul desgastado', 'camino-mesa-mediterranea-azul', 0,
  'Tussor azul desgastado con puntilla de algodón y estampas en las puntas, en dos largos a elección. El toque justo para una mesa que ya tiene su mantel, o para lucir la madera sola.',
  0, 1, 4)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '1,80 × 0,40 m', 31900, 0, 1),
  (@pid, '1,50 × 0,40 m', 37900, 1, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-mediterranea'),
  'Camino de mesa arena y azul desgastado', 'camino-mesa-mediterranea-arena-azul', 0,
  'Tussor a rayas arena y azul desgastado con puntilla de algodón. Un clásico que nunca queda mal, del tamaño justo para mesas más chicas.',
  0, 1, 5)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '1,50 × 0,45 m', 29900, 0, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-mediterranea'),
  'Repasador azul desgastado', 'repasador-mediterranea-azul', 0,
  'Repasador en tela nido azul desgastado, con volado a raya. Útil en la cocina, lindo también colgado a la vista.',
  0, 1, 6)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '0,35 × 0,50 cm', 12900, 0, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-mediterranea'),
  'Almohadón arena y azul desgastado', 'almohadon-mediterranea-arena-azul', 0,
  'Almohadones en tussor, en arena y azul desgastado, en 40×40 o 50×50, con funda sola o con relleno incluido. Para el sillón, la cama o donde te haga falta un poco más de color.',
  0, 1, 7)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '40×40 — funda sola', 24900, 0, 1),
  (@pid, '40×40 — con relleno', 32900, 1, 1),
  (@pid, '50×50 — funda sola', 26900, 2, 1),
  (@pid, '50×50 — con relleno', 37900, 3, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-mediterranea'),
  'Delantal con pechera arena y azul desgastado', 'delantal-mediterranea-arena-azul', 0,
  'Delantal con pechera en tussor a rayas arena y azul desgastado. Cómodo para cocinar y lo suficientemente lindo como para no sacártelo cuando llegan visitas.',
  0, 1, 8)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, 'Arena y azul desgastado', 32900, 0, 1);

-- ------------------------------------------------------------
-- LÍNEA TIERRA & ALGODÓN
-- ------------------------------------------------------------

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-tierra-algodon'),
  'Mantel marrón claro', 'mantel-tierra-marron-claro', 0,
  'Tussor marrón claro con puntilla de algodón y un pequeño estampado. Cálido y neutro, combina con casi cualquier vajilla. Para una mesa de 6 sillas.',
  1, 1, 1)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '1,50 × 2,00 m — mesa de 6 sillas', 64900, 0, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-tierra-algodon'),
  'Mantel verde oliva apagado', 'mantel-tierra-verde-oliva', 0,
  'Tussor verde oliva apagado con puntilla de algodón y estampa. Un color de estación que le suma profundidad a la mesa sin recargarla. Para una mesa de 6 sillas.',
  0, 1, 2)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '1,50 × 2,00 m — mesa de 6 sillas', 64900, 0, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-tierra-algodon'),
  'Camino de mesa marrón claro y verde musgo', 'camino-mesa-tierra-marron-verde', 0,
  'Gasa con puntilla y estampa, en marrón claro y verde musgo, en dos largos a elección. El complemento perfecto para sumar textura a la mesa sin cambiar todo.',
  0, 1, 3)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '1,80 × 0,40 m', 27200, 0, 1),
  (@pid, '1,50 × 0,40 m', 32900, 1, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-tierra-algodon'),
  'Servilletas marrón claro y verde oliva desgastado', 'servilletas-tierra-marron-verde', 0,
  'Servilletas de tussor con puntillas en los extremos, en marrón claro y verde oliva desgastado. En presentaciones de 1, 2, 4 o 6 unidades.',
  0, 1, 4)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, 'Unidad — 0,30 × 0,30 cm', 6900, 0, 1),
  (@pid, 'Set × 2', 12900, 1, 1),
  (@pid, 'Set × 4', 24900, 2, 1),
  (@pid, 'Set × 6', 35900, 3, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-tierra-algodon'),
  'Repasador marrón claro y verde oliva desgastado', 'repasador-tierra-marron-verde', 0,
  'Repasador en tela nido marrón y verde oliva, con volado. Práctico para todos los días, con un color que disimula bien las manchas.',
  0, 1, 5)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '0,35 × 0,50 cm', 12900, 0, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-tierra-algodon'),
  'Almohadón verde oliva desgastado', 'almohadon-tierra-verde-oliva', 0,
  'Almohadones en tussor verde oliva desgastado, en 40×40 o 50×50, con funda sola o con relleno incluido. Un color tierra que le da calidez a cualquier rincón.',
  0, 1, 6)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, '40×40 — funda sola', 24900, 0, 1),
  (@pid, '40×40 — con relleno', 32900, 1, 1),
  (@pid, '50×50 — funda sola', 26900, 2, 1),
  (@pid, '50×50 — con relleno', 37900, 3, 1);

INSERT INTO products (category_id, name, slug, price, description, featured, active, sort_order)
VALUES ((SELECT id FROM categories WHERE slug='linea-tierra-algodon'),
  'Delantal con pechera marrón y verde', 'delantal-tierra-marron-verde', 0,
  'Delantal con pechera en tussor a rayas, disponible en marrón claro o verde desgastado. Cómodo para cocinar todos los días.',
  0, 1, 7)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), description=VALUES(description), featured=VALUES(featured), sort_order=VALUES(sort_order), id=LAST_INSERT_ID(id);
SET @pid := LAST_INSERT_ID();
DELETE FROM product_variants WHERE product_id=@pid;
INSERT INTO product_variants (product_id, label, price, sort_order, active) VALUES
  (@pid, 'Marrón claro', 32900, 0, 1),
  (@pid, 'Verde desgastado', 32900, 1, 1);

-- ------------------------------------------------------------
-- Recalcula el precio "desde" de cada producto según sus variantes.
-- ------------------------------------------------------------
UPDATE products p
SET price = (SELECT MIN(v.price) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1)
WHERE p.id IN (SELECT product_id FROM product_variants);
