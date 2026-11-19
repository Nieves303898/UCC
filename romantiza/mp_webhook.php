<?php
/**
 * Mercado Pago manda un aviso a esta URL apenas cambia el estado de un
 * pago (aprobado, rechazado, etc). No lo visita ningún visitante: es
 * "máquina a máquina", configurado como notification_url al crear la
 * preferencia de pago (ver includes/mercadopago.php).
 *
 * Siempre respondemos código 200 (incluso ante un error interno) para
 * que Mercado Pago no reintente en loop una notificación que ya no
 * vamos a poder procesar; los errores quedan igual en el log de PHP
 * del hosting por si hay que revisarlos.
 */
define('ROMANTIZA_APP', true);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/mercadopago.php';

header('Content-Type: application/json');

try {
    // "data.id" llega en la URL como data.id=... y PHP lo expone en
    // $_GET como 'data_id' (los puntos en nombres de parámetros se
    // convierten en guiones bajos). Cubrimos también el formato viejo
    // de notificación (topic=payment&id=...).
    $dataId = $_GET['data_id'] ?? $_GET['id'] ?? null;
    $type = $_GET['type'] ?? $_GET['topic'] ?? '';

    if (!$dataId) {
        $raw = file_get_contents('php://input');
        $body = json_decode((string)$raw, true);
        if (is_array($body)) {
            $dataId = $body['data']['id'] ?? $dataId;
            $type = $type ?: ($body['type'] ?? '');
        }
    }

    if ($type !== 'payment' || !$dataId) {
        // No es una notificación de pago (puede ser de tipo "merchant_order",
        // que no necesitamos): la reconocemos igual con un 200 y no hacemos nada.
        http_response_code(200);
        echo json_encode(['ok' => true, 'skipped' => true]);
        exit;
    }

    $secret = trim(setting($pdo, 'mp_webhook_secret'));
    if ($secret !== '') {
        $xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        $xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        if (!mp_verify_webhook_signature($xSignature, $xRequestId, (string)$dataId, $secret)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'firma inválida']);
            exit;
        }
    }

    $payment = mp_get_payment($pdo, (string)$dataId);
    if ($payment) {
        mp_update_order_from_payment($pdo, $payment);
    }

    http_response_code(200);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('mp_webhook.php: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['ok' => false]);
}
