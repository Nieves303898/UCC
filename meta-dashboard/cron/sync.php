<?php
declare(strict_types=1);

/**
 * Tarea diaria: sincroniza activos, guarda seguidores del día,
 * avisa tokens por vencer y limpia la caché.
 * cPanel → Cron Jobs:  15 3 * * *  /usr/local/bin/php /home/USUARIO/meta-dashboard/cron/sync.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

set_time_limit(0);
App\Schema::install();
echo json_encode(App\Services\Jobs::run(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
