-- ============================================================
-- Campaña Navidad 2026 — slides de portada
-- 1) Subir antes las 3 fotos a uploads/portada/:
--    navidad-1-capsula.jpg, navidad-2-mesa.jpg, navidad-3-regalos.jpg
-- 2) Correr este archivo en phpMyAdmin (pestaña SQL).
-- El slide actual NO se borra: queda pausado (active = 0). Para volver
-- a la portada de siempre después del 30/12, en el panel → Portada:
-- pausá los 3 de Navidad y activá de nuevo el original.
-- ============================================================
SET NAMES utf8mb4;

UPDATE hero_slides SET active = 0 WHERE image NOT LIKE 'navidad-%';

INSERT INTO hero_slides (image, title, subtitle, link_url, link_text, sort_order, active) VALUES
('navidad-1-capsula.jpg', 'Cápsula Ilusión Navideña',
 'Manteles, caminos e individuales en rojo y verde para la mesa del 24. Estampados y terminados a mano en Sáenz Peña.',
 'tienda.php?categoria=capsula-ilusion-navidena-romantiza', 'Ver la cápsula', 1, 1),
('navidad-2-mesa.jpg', 'La mesa que junta a todos',
 'Individuales, caminos y servilletas para armar la cena de Nochebuena. Combinalos con lo que ya tenés en casa.',
 'tienda.php?categoria=capsula-ilusion-navidena-romantiza', 'Armar mi mesa', 2, 1),
('navidad-3-regalos.jpg', 'Regalos hechos a mano',
 'Paneras, almohadones y caminos para regalar esta Navidad. Te los mandamos a cualquier punto del país.',
 'tienda.php?categoria=capsula-ilusion-navidena-romantiza', 'Elegir un regalo', 3, 1);
