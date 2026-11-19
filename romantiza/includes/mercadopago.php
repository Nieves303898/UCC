<?php
if (!defined('ROMANTIZA_APP')) { http_response_code(403); exit('Acceso directo no permitido.'); }

// Base de la API de Mercado Pago. Se define como constante (en vez de
// escribir la URL a mano en cada función) únicamente para poder
// apuntarla a un servidor de prueba durante testing; en el sitio real
// nunca hace falta definir MP_API_BASE, así que siempre usa la de Mercado Pago.
if (!defined('MP_API_BASE')) { define('MP_API_BASE', 'https://api.mercadopago.com'); }

/**
 * Integración con Mercado Pago (Checkout Pro).
 *
 * Cómo funciona, en resumen:
 * 1. En checkout.php, si el comprador elige "Mercado Pago" y el sitio
 *    tiene un Access Token cargado (panel → Textos → Pagos y envíos),
 *    creamos una "preferencia" de pago (mp_create_preference) y
 *    mandamos al comprador a la página de pago de Mercado Pago.
 * 2. Mercado Pago le cobra la tarjeta/dinero en cuenta al comprador
 *    en SU propia página (nunca vemos ni guardamos datos de tarjetas).
 * 3. Mercado Pago avisa el resultado de dos formas, para que nunca se
 *    pierda un pago: (a) redirige de vuelta al comprador a
 *    pedido-confirmado.php, y (b) manda un aviso aparte al servidor
 *    (mp_webhook.php) apenas cambia el estado del pago. Guardamos el
 *    estado en la tabla orders con cualquiera de las dos vías.
 *
 * Si el Access Token no está cargado, o Mercado Pago no responde,
 * el checkout NUNCA se rompe: sigue el flujo de siempre (se registra
 * el pedido y se coordina el pago por WhatsApp).
 */

/** Access Token cargado en el panel, o null si todavía no se configuró. */
function mp_access_token(PDO $pdo): ?string {
    $token = trim(setting($pdo, 'mp_access_token'));
    return $token !== '' ? $token : null;
}

/** True si el sitio ya tiene un Access Token de Mercado Pago cargado. */
function mp_configured(PDO $pdo): bool {
    return mp_access_token($pdo) !== null;
}

/** True si el Access Token cargado es de prueba (empieza con TEST-). */
function mp_is_test_token(string $token): bool {
    return stripos($token, 'TEST-') === 0;
}

/** URL base del sitio (esquema + dominio), para armar back_urls y notification_url. */
function mp_site_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * Crea una preferencia de pago en Mercado Pago para un pedido ya
 * guardado en la base (orders/order_items). Devuelve:
 *   ['ok' => true,  'redirect_url' => 'https://...']  si salió bien
 *   ['ok' => false, 'error' => 'motivo (para logs, no para el comprador)']
 *
 * $order: array con al menos 'id', 'order_number'.
 * $cartItems: mismo formato que cart_items() (product/variant/qty/line_total).
 * $customer: mismo formato que $formData de checkout.php (name/email/phone).
 */
function mp_create_preference(PDO $pdo, array $order, array $cartItems, array $customer): array {
    $token = mp_access_token($pdo);
    if (!$token) {
        return ['ok' => false, 'error' => 'Mercado Pago no está configurado (falta el Access Token).'];
    }
    if (!$cartItems) {
        return ['ok' => false, 'error' => 'El pedido no tiene productos.'];
    }

    $items = [];
    foreach ($cartItems as $item) {
        $title = (string)$item['product']['name'];
        if (!empty($item['variant']['label'])) {
            $title .= ' — ' . $item['variant']['label'];
        }
        $items[] = [
            'title' => mb_substr($title, 0, 250),
            'quantity' => max(1, (int)$item['qty']),
            'unit_price' => round((float)$item['variant']['price'], 2),
            'currency_id' => 'ARS',
        ];
    }

    $base = mp_site_base_url();
    $returnUrl = $base . '/pedido-confirmado.php';

    $payer = array_filter([
        'name' => trim((string)($customer['name'] ?? '')) ?: null,
        'email' => trim((string)($customer['email'] ?? '')) ?: null,
    ]);

    $payload = [
        'items' => $items,
        'back_urls' => [
            'success' => $returnUrl,
            'pending' => $returnUrl,
            'failure' => $returnUrl,
        ],
        'external_reference' => $order['order_number'],
        'notification_url' => $base . '/mp_webhook.php',
        'statement_descriptor' => 'ROMANTIZA',
    ];
    if ($payer) { $payload['payer'] = $payer; }
    // auto_return (vuelta automática al sitio tras aprobar el pago) solo
    // es válido para Mercado Pago si la URL es https pública. En pruebas
    // locales (http o localhost) lo omitimos: igual funciona, el
    // comprador solo tiene que tocar el link para volver al sitio.
    if (stripos($base, 'https://') === 0) {
        $payload['auto_return'] = 'approved';
    }

    $ch = curl_init(MP_API_BASE . '/checkout/preferences');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $curlErr !== '') {
        return ['ok' => false, 'error' => 'No se pudo conectar con Mercado Pago: ' . $curlErr];
    }
    $data = json_decode($resp, true);
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        $msg = is_array($data) ? ($data['message'] ?? json_encode($data)) : ('respuesta inválida, código ' . $code);
        return ['ok' => false, 'error' => 'Mercado Pago respondió con un error: ' . $msg];
    }

    $redirectUrl = mp_is_test_token($token)
        ? ($data['sandbox_init_point'] ?? $data['init_point'] ?? null)
        : ($data['init_point'] ?? null);
    if (!$redirectUrl) {
        return ['ok' => false, 'error' => 'Mercado Pago no devolvió un link de pago.'];
    }

    if (!empty($data['id']) && !empty($order['id'])) {
        $pdo->prepare('UPDATE orders SET mp_preference_id = ? WHERE id = ?')->execute([$data['id'], $order['id']]);
    }

    return ['ok' => true, 'redirect_url' => $redirectUrl];
}

/** Consulta un pago puntual en la API de Mercado Pago. Devuelve null si falla. */
function mp_get_payment(PDO $pdo, string $paymentId): ?array {
    $token = mp_access_token($pdo);
    if (!$token || $paymentId === '') return null;

    $ch = curl_init(MP_API_BASE . '/v1/payments/' . rawurlencode($paymentId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $curlErr !== '' || $code < 200 || $code >= 300) return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

/** Traduce el estado de un pago de Mercado Pago a nuestros 3 estados internos. */
function mp_map_status(string $mpStatus): string {
    $map = [
        'approved' => 'confirmado',
        'accredited' => 'confirmado',
        'pending' => 'pendiente',
        'in_process' => 'pendiente',
        'authorized' => 'pendiente',
        'in_mediation' => 'pendiente',
        'rejected' => 'cancelado',
        'cancelled' => 'cancelado',
        'refunded' => 'cancelado',
        'charged_back' => 'cancelado',
    ];
    return $map[$mpStatus] ?? 'pendiente';
}

/**
 * Actualiza el pedido correspondiente (buscado por external_reference,
 * que guardamos como el número de pedido) con el resultado de un pago
 * de Mercado Pago. No hace nada si no encuentra el pedido.
 */
function mp_update_order_from_payment(PDO $pdo, array $payment): void {
    $orderNumber = $payment['external_reference'] ?? null;
    if (!$orderNumber) return;

    $stmt = $pdo->prepare('SELECT id FROM orders WHERE order_number = ? LIMIT 1');
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();
    if (!$order) return;

    $rawStatus = (string)($payment['status'] ?? '');
    $paymentStatus = mp_map_status($rawStatus);
    $paymentId = (string)($payment['id'] ?? '');

    $upd = $pdo->prepare('UPDATE orders SET payment_status = ?, mp_status = ?, mp_payment_id = ? WHERE id = ?');
    $upd->execute([$paymentStatus, $rawStatus, $paymentId, $order['id']]);
}

/**
 * Verifica la firma de un webhook de Mercado Pago (header X-Signature),
 * usando la "Clave secreta" que Mercado Pago muestra al configurar la
 * notificación webhook en el panel de developers. Si no se cargó una
 * clave secreta en el sitio, esta función no se llama (ver mp_webhook.php)
 * y las notificaciones se procesan igual, sin verificar firma.
 */
function mp_verify_webhook_signature(string $xSignature, string $xRequestId, string $dataId, string $secret): bool {
    if ($xSignature === '' || $dataId === '') return false;

    $parts = [];
    foreach (explode(',', $xSignature) as $piece) {
        $kv = explode('=', $piece, 2);
        if (count($kv) === 2) { $parts[trim($kv[0])] = trim($kv[1]); }
    }
    $ts = $parts['ts'] ?? null;
    $v1 = $parts['v1'] ?? null;
    if (!$ts || !$v1) return false;

    $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $xRequestId . ';ts:' . $ts . ';';
    $computed = hash_hmac('sha256', $manifest, $secret);
    return hash_equals($computed, $v1);
}
