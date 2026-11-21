<?php



declare(strict_types=1);



/** Cabeceras HTTP de seguridad basicas (hosting compartido). */

final class SecurityHeaders

{

    public static function sendIfWeb(): void

    {

        if (PHP_SAPI === 'cli' || headers_sent()) {

            return;

        }

        header('X-Frame-Options: SAMEORIGIN');

        header('X-Content-Type-Options: nosniff');

        header('Referrer-Policy: strict-origin-when-cross-origin');

        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        self::sendContentSecurityPolicy();

        global $CONFIG;

        $isProd = is_array($CONFIG ?? null) && ($CONFIG['env'] ?? '') === 'production';

        if ($isProd && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {

            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

        }

    }



    /** CSP permisiva: la UI usa estilos/scripts inline y CDN de Bootstrap. */

    private static function sendContentSecurityPolicy(): void

    {

        global $CONFIG;

        $enabled = true;

        if (is_array($CONFIG ?? null) && array_key_exists('csp_enabled', $CONFIG)) {

            $enabled = (bool) $CONFIG['csp_enabled'];

        }

        if (!$enabled) {

            return;

        }



        $policy = implode('; ', [

            "default-src 'self'",

            "base-uri 'self'",

            "form-action 'self'",

            "frame-ancestors 'self'",

            "img-src 'self' data: https:",

            "font-src 'self' https://cdn.jsdelivr.net",

            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",

            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",

            "connect-src 'self'",

        ]);

        header('Content-Security-Policy: ' . $policy);

    }

}

