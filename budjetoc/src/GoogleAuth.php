<?php

declare(strict_types=1);

/** OAuth 2.0 con Google (login centralizado). */
final class GoogleAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public static function isEnabled(): bool
    {
        $cfg = self::config();

        return !empty($cfg['enabled'])
            && trim((string) ($cfg['client_id'] ?? '')) !== ''
            && trim((string) ($cfg['client_secret'] ?? '')) !== '';
    }

    public static function passwordLoginEnabled(): bool
    {
        global $CONFIG;
        if (!self::isEnabled()) {
            return true;
        }
        if (array_key_exists('password_login_enabled', $CONFIG)) {
            return (bool) $CONFIG['password_login_enabled'];
        }

        return ($CONFIG['env'] ?? 'local') === 'local';
    }

    public static function redirectUri(): string
    {
        return absolute_url('auth/google/callback.php');
    }

    public static function startUrl(): string
    {
        return url_path('auth/google/start.php');
    }

    /** Inicia redirect a Google (sesion admin debe estar activa para guardar state). */
    public static function redirectToGoogle(): void
    {
        if (!self::isEnabled()) {
            redirect_to('index.php?err=google_disabled');
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;

        $params = [
            'client_id' => self::clientId(),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ];

        $hosted = trim((string) (self::config()['hosted_domain'] ?? ''));
        if ($hosted !== '') {
            $params['hd'] = $hosted;
        }

        header('Location: ' . self::AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        exit;
    }

    /**
     * Intercambia code por perfil Google.
     *
     * @return array{sub: string, email: string, name: string, picture: string|null}|null
     */
    public static function fetchUserProfile(string $code): ?array
    {
        $token = self::exchangeCode($code);
        if ($token === null) {
            return null;
        }

        return self::fetchUserinfo($token);
    }

    public static function validateOAuthState(string $state): bool
    {
        $expected = (string) ($_SESSION['google_oauth_state'] ?? '');
        unset($_SESSION['google_oauth_state']);

        return $expected !== '' && $state !== '' && hash_equals($expected, $state);
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        global $CONFIG;
        $cfg = $CONFIG['google_oauth'] ?? [];

        return is_array($cfg) ? $cfg : [];
    }

    private static function clientId(): string
    {
        return trim((string) (self::config()['client_id'] ?? ''));
    }

    private static function clientSecret(): string
    {
        return trim((string) (self::config()['client_secret'] ?? ''));
    }

    private static function exchangeCode(string $code): ?string
    {
        $body = http_build_query([
            'code' => $code,
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ], '', '&', PHP_QUERY_RFC3986);

        $raw = self::httpPost(self::TOKEN_URL, $body, ['Content-Type: application/x-www-form-urlencoded']);
        if ($raw === null) {
            return null;
        }

        /** @var array<string, mixed>|null $json */
        $json = json_decode($raw, true);
        $access = $json['access_token'] ?? null;

        return is_string($access) && $access !== '' ? $access : null;
    }

    /**
     * @return array{sub: string, email: string, name: string, picture: string|null}|null
     */
    private static function fetchUserinfo(string $accessToken): ?array
    {
        $raw = self::httpGet(self::USERINFO_URL, ['Authorization: Bearer ' . $accessToken]);
        if ($raw === null) {
            return null;
        }

        /** @var array<string, mixed>|null $json */
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }

        $sub = trim((string) ($json['sub'] ?? ''));
        $email = trim(strtolower((string) ($json['email'] ?? '')));
        $verified = $json['email_verified'] ?? true;
        if ($sub === '' || $email === '' || $verified === false) {
            return null;
        }

        $hosted = trim((string) (self::config()['hosted_domain'] ?? ''));
        if ($hosted !== '') {
            $domain = substr($email, strrpos($email, '@') + 1);
            if (strcasecmp($domain, $hosted) !== 0) {
                return null;
            }
        }

        $name = trim((string) ($json['name'] ?? ''));
        $picture = $json['picture'] ?? null;

        return [
            'sub' => $sub,
            'email' => $email,
            'name' => $name,
            'picture' => is_string($picture) && $picture !== '' ? $picture : null,
        ];
    }

    /** @param list<string> $headers */
    private static function httpGet(string $url, array $headers = []): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return ($raw !== false && $code >= 200 && $code < 300) ? (string) $raw : null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw)) {
            return null;
        }
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }

        return ($code >= 200 && $code < 300) ? $raw : null;
    }

    /** @param list<string> $headers */
    private static function httpPost(string $url, string $body, array $headers = []): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return ($raw !== false && $code >= 200 && $code < 300) ? (string) $raw : null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", array_merge($headers, ['Content-Length: ' . strlen($body)])),
                'content' => $body,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw)) {
            return null;
        }
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }

        return ($code >= 200 && $code < 300) ? $raw : null;
    }
}
