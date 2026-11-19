-- ------------------------------------------------------------
-- Migración: envío gratis por monto + sellos de "Compra segura"
--
-- Agrega los textos nuevos que usa el sitio para estas dos funciones:
--   - Envío gratis a partir de un monto (panel → Textos del sitio →
--     Pagos y envíos → "Envío gratis a partir de qué monto"). Se
--     guarda vacío por defecto: la función queda DESACTIVADA hasta
--     que vos cargues un monto desde el panel.
--   - Sellos de "Compra segura" (panel → Textos del sitio → Compra
--     segura), que ahora se muestran en la ficha de cada producto y
--     en el checkout.
--
-- Es seguro correrlo más de una vez y en cualquier momento: usa
-- INSERT IGNORE, así que si alguna de estas claves ya existe (por
-- ejemplo porque ya la cargaste desde el panel) no la toca ni la
-- pisa. Solo agrega lo que falte.
--
-- Cómo importarlo: en Hostinger, phpMyAdmin -> tu base de datos ->
-- pestaña "Importar" -> elegís este archivo -> Importar.
-- ------------------------------------------------------------

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('free_shipping_threshold', ''),
  ('trust_badge_1', 'Compra 100% segura'),
  ('trust_badge_2', 'Envío a todo el país'),
  ('trust_badge_3', 'Cambios sin cargo'),
  ('trust_badge_4', 'Pago protegido');
