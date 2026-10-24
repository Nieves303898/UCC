<?php
declare(strict_types=1);

/**
 * Webhook de Meta. Configuralo en developers.facebook.com → tu app → Webhooks
 * con esta URL y el "webhook_verify_token" de config.php.
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Config;
use App\Services\Webhooks;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verify = (string) Config::get('meta.webhook_verify_token', '');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && $verify !== '' && hash_equals($verify, (string) ($_GET['hub_verify_token'] ?? ''))) {
        header('Content-Type: text/plain');
        echo (string) ($_GET['hub_challenge'] ?? '');
        exit;
    }
    http_response_code(403);
    exit;
}

$raw = (string) file_get_contents('php://input');
if (!Webhooks::verifySignature($raw, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null)) {
    http_response_code(401);
    exit;
}

$payload = json_decode($raw, true);
if (is_array($payload)) {
    Webhooks::store($payload);
}
http_response_code(200);
echo 'OK';
