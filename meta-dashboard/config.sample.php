<?php
/**
 * Copiá este archivo como config.php y completá los valores.
 * config.php NUNCA debe quedar dentro de la carpeta pública.
 */
return [
    'app_name' => 'Meta Hub',
    // URL pública donde vive /public (sin barra final). Ej: https://metricas.tudominio.com
    'app_url'  => 'https://metricas.tudominio.com',
    // Clave para cifrar tokens. Generala con:  php -r "echo base64_encode(random_bytes(32));"
    'app_key'  => '',
    'timezone' => 'America/Argentina/Buenos_Aires',
    'debug'    => false,

    'db' => [
        'driver'   => 'mysql',          // mysql | sqlite
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'cpaneluser_metahub',
        'username' => 'cpaneluser_metahub',
        'password' => '',
        'sqlite_path' => APP_ROOT . '/storage/database.sqlite',
    ],

    'meta' => [
        'app_id'        => '',
        'app_secret'    => '',
        'graph_version' => 'v24.0',
        // Opcional: ID de configuración de "Facebook Login for Business".
        // Si lo completás, se usa en lugar de los scopes de abajo.
        'config_id'     => '',
        'scopes' => [
            'ads_read',
            'business_management',
            'pages_show_list',
            'pages_read_engagement',
            'read_insights',
            'instagram_basic',
            'instagram_manage_insights',
        ],
        // Token que vos inventás para verificar el webhook en el panel de Meta.
        'webhook_verify_token' => '',
    ],

    // Segundos de caché de cada tipo de consulta.
    'cache' => [
        'realtime_ttl'  => 120,   // rangos que incluyen "hoy"
        'default_ttl'   => 900,   // rangos históricos
        'structure_ttl' => 1800,  // listados de campañas, posteos, etc.
    ],

    // Si tu hosting no permite cron por CLI, podés llamar a
    // https://tu-url/cron.php?key=ESTA_CLAVE desde un servicio externo.
    'cron_key' => '',
];
