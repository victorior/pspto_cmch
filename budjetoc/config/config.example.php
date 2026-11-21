<?php

return [
    'env' => 'local',
    /** Verificar columnas con SHOW COLUMNS. Local: true. Produccion: false. */
    'schema_checks' => true,
    // Path-only base_url keeps the current host (localhost vs 127.0.0.1).
    'base_url' => '/budjetoc/public',
    /**
     * Ruta absoluta a secrets fuera del DocumentRoot (produccion).
     * Ej.: /home/nanodata/config/cmchbudget-secrets.local.php
     * Alternativa: variable de entorno BUDJETOC_SECRETS.
     */
    'secrets_path' => '',
    'db' => [
        'host' => '127.0.0.1',
        /** Opcional. En XAMPP a veces es 3307 (ver mysql/bin/my.ini). */
        'port' => 3306,
        'name' => 'budjetoc',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    /** Clave para hash de contrasenas (cambiar en produccion). */
    'app_secret' => 'cambiar-esta-clave-en-produccion',
    /** Tenant de referencia para replicar catalogo (slug). Vacio = el que tenga mas divisiones. */
    'catalog_master_tenant_slug' => 'cmch',
    /** Contrasena inicial para usuarios importados desde BudgetFlow. */
    'legacy_user_import_password' => 'cambiar-en-local',
    /** Ruta al dump SQL legacy (opcional). */
    'legacy_sql_dump' => '',
    'legacy_oc_sql_dump' => '',
    /**
     * URL publica absoluta (opcional). Si vacia, se deduce del host actual.
     * Produccion ejemplo: https://presupuesto.colegio.cl/public
     */
    'public_url' => '',
    /** Login Google OAuth 2.0 (produccion: unico metodo de acceso). */
    'google_oauth' => [
        'enabled' => false,
        'client_id' => '',
        'client_secret' => '',
        /** Restringir a dominio Workspace, ej. colegio.cl (opcional). */
        'hosted_domain' => '',
    ],
    /** false en produccion cuando google_oauth.enabled = true. */
    'password_login_enabled' => true,
    /**
     * Limite de intentos de login (por email en disco + sesion). No bloquea por IP
     * para no afectar a todos los usuarios detras del mismo NAT escolar.
     */
    'login_rate_limit' => [
        'enabled' => true,
        'max_attempts' => 8,
        'window_seconds' => 900,
    ],
    /** Cabecera Content-Security-Policy (permisiva; desactivar si algo deja de cargar). */
    'csp_enabled' => true,
];
