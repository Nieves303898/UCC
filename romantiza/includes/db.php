<?php
/**
 * Conexión a la base de datos (PDO + MySQL).
 * No se accede a este archivo directamente desde el navegador.
 */
if (!defined('ROMANTIZA_APP')) { http_response_code(403); exit('Acceso directo no permitido.'); }

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('Falta el archivo includes/config.php. Copiá includes/config.sample.php a includes/config.php y completá los datos de tu base de datos (ver README.md).');
}
require_once $configFile;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('No se pudo conectar a la base de datos. Revisá los datos en includes/config.php. (' . htmlspecialchars($e->getMessage()) . ')');
}
