-- ------------------------------------------------------------
-- Corrección de texto: "bordado" -> "estampado"
--
-- Romantiza no borda, estampa. Este script corrige los 3 textos
-- del sitio que traían la palabra "bordado" por defecto (se
-- cargaron así al instalar el sitio por primera vez).
--
-- Es seguro correrlo más de una vez, y también si ya instalaste
-- el sitio hace tiempo: cada UPDATE solo cambia el texto si
-- todavía dice exactamente el texto original de fábrica. Si en
-- algún momento editaste alguno de estos textos vos misma desde
-- el panel (Textos del sitio), ese cambio tuyo no se toca.
--
-- Cómo importarlo: en Hostinger, phpMyAdmin -> tu base de datos
-- -> pestaña "Importar" -> elegís este archivo -> Importar.
-- ------------------------------------------------------------

UPDATE settings SET setting_value = 'Romantiza: textiles de hogar artesanales — mantelería, almohadones, puntillas y más. Algodón y lino natural, estampado a mano. Envíos a todo el país desde Sáenz Peña, Chaco.'
WHERE setting_key = 'site_description'
  AND setting_value = 'Romantiza: textiles de hogar artesanales — mantelería, almohadones, puntillas y más. Algodón y lino natural, bordado a mano. Envíos a todo el país desde Sáenz Peña, Chaco.';

UPDATE settings SET setting_value = 'Textiles de algodón y lino, estampados a mano y puntillas artesanales — la calidez que le faltaba a tu hogar, directo desde Sáenz Peña, Chaco.'
WHERE setting_key = 'hero_lead'
  AND setting_value = 'Textiles de algodón y lino, bordados a mano y puntillas artesanales — la calidez que le faltaba a tu hogar, directo desde Sáenz Peña, Chaco.';

UPDATE settings SET setting_value = 'Seleccionamos telas naturales — algodón y lino, sobre todo — porque son las que mejor envejecen y mejor abrazan una casa. A partir de ahí llega el trabajo artesanal: estampados, puntillas y terminaciones pensadas a mano, una por una.'
WHERE setting_key = 'about_text_1'
  AND setting_value = 'Seleccionamos telas naturales — algodón y lino, sobre todo — porque son las que mejor envejecen y mejor abrazan una casa. A partir de ahí llega el trabajo artesanal: bordados, puntillas y terminaciones pensadas a mano, una por una.';
