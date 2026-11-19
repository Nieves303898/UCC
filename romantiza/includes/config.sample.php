<?php
/**
 * CONFIGURACIÓN DEL SITIO — ROMANTIZA
 *
 * 1) Renombrá este archivo a "config.php" (sin el .sample).
 * 2) Completá los 4 datos de conexión con los que te dio tu hosting
 *    al crear la base de datos MySQL.
 * 3) Nunca compartas este archivo ni lo subas a un repositorio público:
 *    tiene las credenciales de la base de datos.
 */

// Datos de conexión a la base de datos (te los da tu hosting al crear
// la base MySQL desde cPanel — Bases de datos MySQL).
define('DB_HOST', 'localhost');
define('DB_NAME', 'nombre_de_tu_base');
define('DB_USER', 'usuario_de_tu_base');
define('DB_PASS', 'contraseña_de_tu_base');

// URL completa del sitio, SIN barra al final. Ej: https://www.romantiza.com.ar
define('SITE_URL', 'https://www.romantiza.com.ar');

// Zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');
