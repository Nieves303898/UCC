-- ------------------------------------------------------------
-- Migración: Captación de WhatsApp desde la web (popup)
--
-- Agrega lo necesario para juntar contactos de WhatsApp directo
-- desde el sitio: pensado para campañas puntuales (por ejemplo,
-- el Día de la Madre), pero queda disponible para usar cuando
-- quieras de ahora en más.
--
-- Qué hace:
--   1) Crea la tabla whatsapp_leads, donde se guarda cada contacto
--      que deja su WhatsApp en el popup de la web (nombre opcional
--      + número). Se ve y se administra desde el panel, en la
--      nueva sección "WhatsApp (campañas)".
--   2) Agrega los textos del popup (título, texto, botón y el
--      mensaje con el que se abre WhatsApp) a la tabla settings,
--      editables desde el panel → Textos del sitio → "Popup de
--      WhatsApp". El popup queda APAGADO por defecto: hay que
--      prenderlo a propósito desde ahí cuando quieras usarlo.
--
-- Es segura para correr más de una vez: usa "CREATE TABLE IF NOT
-- EXISTS" e "INSERT IGNORE", así que si ya la corriste antes, no
-- vuelve a tocar nada ni pisa un texto que ya hayas personalizado.
--
-- No borra ni modifica ningún producto, pedido, texto ni nada de lo
-- que ya tenés cargado.
--
-- Cómo importarlo: en Hostinger, phpMyAdmin -> tu base de datos ->
-- pestaña "Importar" -> elegís este archivo -> Importar.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS whatsapp_leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160),
  whatsapp VARCHAR(40) NOT NULL,
  source VARCHAR(60) NOT NULL DEFAULT 'popup_web',
  contacted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('wa_popup_enabled', ''),
  ('wa_popup_title', '¡Regalo especial para el Día de la Madre! 🎁'),
  ('wa_popup_text', 'Dejanos tu WhatsApp y te mandamos un cupón especial + las novedades antes que nadie.'),
  ('wa_popup_button_text', 'Quiero mi regalo'),
  ('wa_popup_whatsapp_message', 'Hola! Quiero el regalo especial de Romantiza 🎁');
