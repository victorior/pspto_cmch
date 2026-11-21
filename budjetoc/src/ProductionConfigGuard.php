<?php

declare(strict_types=1);

/** Avisos de configuracion insegura en produccion (no bloquea la app). */
final class ProductionConfigGuard
{
    /** @param array<string, mixed> $config */
    public static function warnIfNeeded(array $config): void
    {
        $env = (string) ($config['env'] ?? 'local');
        if ($env !== 'production') {
            return;
        }

        $issues = self::collectIssues($config);
        if ($issues === []) {
            return;
        }

        $msg = 'Budjetoc [config produccion]: ' . implode(' | ', $issues);
        error_log($msg);

        if (PHP_SAPI !== 'cli' && self::shouldShowBanner()) {
            echo '<div style="background:#fef3c7;color:#92400e;padding:0.65rem 1rem;border-bottom:1px solid #fcd34d;font:14px/1.4 sans-serif;">'
                . '<strong>Configuracion de produccion:</strong> '
                . h(implode(' · ', $issues))
                . '</div>';
        }
    }

    /** @param array<string, mixed> $config
     * @return list<string>
     */
    private static function collectIssues(array $config): array
    {
        $issues = [];
        $secret = (string) ($config['app_secret'] ?? '');
        if ($secret === '' || strlen($secret) < 24 || str_contains(strtolower($secret), 'cambiar') || str_contains(strtolower($secret), 'local')) {
            $issues[] = 'app_secret debil o por defecto';
        }

        $legacyPass = (string) ($config['legacy_user_import_password'] ?? '');
        if ($legacyPass !== '' && !str_contains(strtolower($legacyPass), 'cambiar')) {
            $issues[] = 'legacy_user_import_password definida en produccion';
        }

        foreach (['legacy_sql_dump', 'legacy_oc_sql_dump'] as $key) {
            $path = (string) ($config[$key] ?? '');
            if ($path !== '' && is_file($path)) {
                $issues[] = $key . ' apunta a un dump en el servidor';
            }
        }

        $dbPass = (string) ($config['db']['pass'] ?? '');
        $dbUser = (string) ($config['db']['user'] ?? '');
        if ($dbUser === 'root' && $dbPass === '') {
            $issues[] = 'MySQL usa root sin clave';
        }

        if (!empty($config['schema_checks'])) {
            $issues[] = 'schema_checks activo en produccion (SHOW COLUMNS por request)';
        }

        $google = $config['google_oauth'] ?? [];
        if (!is_array($google) || empty($google['enabled'])) {
            $issues[] = 'google_oauth no habilitado en produccion';
        } elseif (trim((string) ($google['client_id'] ?? '')) === '' || trim((string) ($google['client_secret'] ?? '')) === '') {
            $issues[] = 'google_oauth sin client_id o client_secret';
        }

        if (!empty($google['enabled']) && ($config['password_login_enabled'] ?? false)) {
            $issues[] = 'password_login_enabled activo con Google OAuth';
        }

        return $issues;
    }

    private static function shouldShowBanner(): bool
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

        return str_contains($script, '/admin/');
    }
}
