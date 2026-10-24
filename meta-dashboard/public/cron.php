<?php
declare(strict_types=1);

/** Alternativa web al cron por CLI: https://tu-url/cron.php?key=CRON_KEY */
require __DIR__ . '/../app/bootstrap.php';

$key = (string) App\Config::get('cron_key', '');
if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}

set_time_limit(600);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(App\Services\Jobs::run(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
