<?php

/**
 * Plantilla de produccion (nanodata.cl/cmchbudget).
 * Copiar a config/config.php en el servidor.
 * Credenciales y secretos: secrets_path (fuera de public_html).
 */
return [
    'env' => 'production',
    'schema_checks' => false,
    'base_url' => '/cmchbudget',
    'public_url' => 'https://nanodata.cl/cmchbudget',
    /** Absoluto en el servidor; no subir secretos dentro de public_html. */
    'secrets_path' => '/home/nanodata/config/cmchbudget-secrets.local.php',
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'nanodata_cmchbudgetOC',
        'user' => 'nanodata_cmchbudgetOC_usr',
        'pass' => '', // sobrescrito por secrets_path
        'charset' => 'utf8mb4',
    ],
    'app_secret' => '', // sobrescrito por secrets_path
    'catalog_master_tenant_slug' => 'cmch',
    'legacy_user_import_password' => '',
    'legacy_sql_dump' => '',
    'legacy_oc_sql_dump' => '',
    'google_oauth' => [
        'enabled' => true,
        'client_id' => '', // sobrescrito por secrets_path
        'client_secret' => '', // sobrescrito por secrets_path
        'hosted_domain' => '',
    ],
    'password_login_enabled' => false,
    'login_rate_limit' => [
        'enabled' => true,
        'max_attempts' => 8,
        'window_seconds' => 900,
    ],
    'csp_enabled' => true,
];
