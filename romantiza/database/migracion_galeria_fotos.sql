-- ------------------------------------------------------------
-- Migración: galería de fotos por producto
--
-- Agrega la tabla "product_images", que guarda las fotos adicionales
-- de cada producto (además de la foto de portada, que sigue estando
-- en products.image como siempre). Con esto, desde el panel →
-- Productos → editar un producto, aparece una sección nueva "Fotos
-- adicionales (galería)" para:
--   - Cargar varias fotos a la vez.
--   - Elegir cuál de todas se usa como portada (la que se ve en la
--     tienda). La que deja de ser portada no se borra: pasa a la
--     galería.
--   - Eliminar una foto de la galería.
-- En la ficha del producto, todas las fotos se muestran del mismo
-- tamaño automáticamente, sin importar el tamaño o forma original de
-- cada una.
--
-- Es seguro correrlo más de una vez: usa "CREATE TABLE IF NOT
-- EXISTS", así que si ya la creaste antes, no hace nada.
--
-- No borra ni modifica ningún producto, foto de portada, pedido ni
-- nada de lo que ya tenés cargado — solo agrega esta tabla nueva,
-- vacía.
--
-- Cómo importarlo: en Hostinger, phpMyAdmin -> tu base de datos ->
-- pestaña "Importar" -> elegís este archivo -> Importar.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS product_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  image VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
