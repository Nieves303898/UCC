<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

/**
 * Recibe lo que se completó en el popup de WhatsApp (ver
 * whatsapp_popup_html() en includes/functions.php), lo guarda en el
 * panel si se puede, y manda directo a abrir una conversación de
 * WhatsApp con el negocio, con el mensaje ya escrito. Aunque algo
 * falle guardando el contacto, igual se abre WhatsApp: lo importante
 * es que la persona llegue a escribir, no se pierde esa parte nunca.
 */

$whatsappNumber = setting($pdo, 'contact_whatsapp', '');
$prefill = setting($pdo, 'wa_popup_whatsapp_message', '') ?: 'Hola! Quiero el regalo especial de Romantiza 🎁';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // "website" es un campo trampa para bots: a una persona no se le
    // muestra (ver CSS .wa-popup-hp), así que si viene completo,
    // asumimos que fue un robot y no guardamos nada.
    $honeypot = trim($_POST['website'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $digits = preg_replace('/[^0-9]/', '', trim($_POST['whatsapp'] ?? ''));

    if ($honeypot === '' && strlen($digits) >= 8 && strlen($digits) <= 15) {
        try {
            $stmt = $pdo->prepare('INSERT INTO whatsapp_leads (name, whatsapp, source) VALUES (?, ?, ?)');
            $stmt->execute([$name !== '' ? $name : null, $digits, 'popup_web']);
        } catch (PDOException $e) {
            // Falta correr migracion_captacion_whatsapp.sql: no pasa
            // nada, seguimos igual (no se guarda en el panel, pero la
            // persona llega a WhatsApp de todas formas).
        }
    }
}

if ($whatsappNumber !== '') {
    header('Location: https://wa.me/' . rawurlencode($whatsappNumber) . '?text=' . rawurlencode($prefill));
} else {
    header('Location: index.php');
}
exit;
