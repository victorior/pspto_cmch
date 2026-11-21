<?php

declare(strict_types=1);

/**
 * Limita intentos de login por email (principal) y sesion (secundario).
 * No bloquea por IP agresivamente: en colegios muchos usuarios comparten NAT.
 */
final class LoginRateLimiter
{
    private const DEFAULT_MAX_ATTEMPTS = 8;
    private const DEFAULT_WINDOW_SECONDS = 900;

    public static function checkAllowed(string $scope, ?string $email = null): bool
    {
        if (!self::isEnabled()) {
            return true;
        }

        if (!self::bucketOpen(self::readSessionBucket($scope))) {
            return false;
        }

        $normalizedEmail = self::normalizeEmail($email);
        if ($normalizedEmail !== null && !self::bucketOpen(self::readEmailBucket($scope, $normalizedEmail))) {
            return false;
        }

        return true;
    }

    public static function recordFailure(string $scope, ?string $email = null): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::incrementSessionBucket($scope);
        $normalizedEmail = self::normalizeEmail($email);
        if ($normalizedEmail !== null) {
            self::incrementEmailBucket($scope, $normalizedEmail);
        }
    }

    public static function clear(string $scope, ?string $email = null): void
    {
        unset($_SESSION[self::sessionKey($scope)]);

        $normalizedEmail = self::normalizeEmail($email);
        if ($normalizedEmail !== null) {
            self::deleteFileBucket(self::filePath($scope, 'email', $normalizedEmail));
        }
    }

    public static function lockoutMessage(): string
    {
        $mins = (int) ceil(self::windowSeconds() / 60);

        return 'Demasiados intentos fallidos. Espere ' . $mins . ' minutos e intente de nuevo.';
    }

    private static function isEnabled(): bool
    {
        global $CONFIG;
        if (!is_array($CONFIG ?? null)) {
            return true;
        }
        $cfg = $CONFIG['login_rate_limit'] ?? null;
        if (is_array($cfg) && array_key_exists('enabled', $cfg)) {
            return (bool) $cfg['enabled'];
        }

        return true;
    }

    private static function maxAttempts(): int
    {
        global $CONFIG;
        $cfg = is_array($CONFIG ?? null) ? ($CONFIG['login_rate_limit'] ?? null) : null;
        if (is_array($cfg) && isset($cfg['max_attempts']) && (int) $cfg['max_attempts'] > 0) {
            return (int) $cfg['max_attempts'];
        }

        return self::DEFAULT_MAX_ATTEMPTS;
    }

    private static function windowSeconds(): int
    {
        global $CONFIG;
        $cfg = is_array($CONFIG ?? null) ? ($CONFIG['login_rate_limit'] ?? null) : null;
        if (is_array($cfg) && isset($cfg['window_seconds']) && (int) $cfg['window_seconds'] > 0) {
            return (int) $cfg['window_seconds'];
        }

        return self::DEFAULT_WINDOW_SECONDS;
    }

    /** @return array{count: int, until: int} */
    private static function readSessionBucket(string $scope): array
    {
        $key = self::sessionKey($scope);
        $bucket = $_SESSION[$key] ?? ['count' => 0, 'until' => 0];

        return is_array($bucket) ? self::normalizeBucket($bucket) : ['count' => 0, 'until' => 0];
    }

    private static function incrementSessionBucket(string $scope): void
    {
        $key = self::sessionKey($scope);
        $bucket = self::readSessionBucket($scope);
        $_SESSION[$key] = self::incrementBucket($bucket);
    }

    /** @return array{count: int, until: int} */
    private static function readEmailBucket(string $scope, string $email): array
    {
        $path = self::filePath($scope, 'email', $email);
        $raw = self::readFileBucket($path);
        if ($raw === null) {
            return ['count' => 0, 'until' => 0];
        }

        return self::normalizeBucket($raw);
    }

    private static function incrementEmailBucket(string $scope, string $email): void
    {
        $path = self::filePath($scope, 'email', $email);
        $bucket = self::readEmailBucket($scope, $email);
        self::writeFileBucket($path, self::incrementBucket($bucket));
    }

    /** @param array{count?: int, until?: int} $bucket */
    private static function incrementBucket(array $bucket): array
    {
        $count = (int) ($bucket['count'] ?? 0) + 1;
        if ($count >= self::maxAttempts()) {
            return ['count' => $count, 'until' => time() + self::windowSeconds()];
        }

        return ['count' => $count, 'until' => 0];
    }

    /** @param array{count?: int, until?: int} $bucket */
    private static function bucketOpen(array $bucket): bool
    {
        $until = (int) ($bucket['until'] ?? 0);
        if ($until > time()) {
            return false;
        }

        return (int) ($bucket['count'] ?? 0) < self::maxAttempts();
    }

    /** @param array{count?: int, until?: int} $bucket */
    private static function normalizeBucket(array $bucket): array
    {
        $until = (int) ($bucket['until'] ?? 0);
        if ($until > 0 && $until <= time()) {
            return ['count' => 0, 'until' => 0];
        }

        return [
            'count' => (int) ($bucket['count'] ?? 0),
            'until' => $until,
        ];
    }

    private static function normalizeEmail(?string $email): ?string
    {
        $email = trim(strtolower((string) $email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    private static function sessionKey(string $scope): string
    {
        return 'budjetoc_login_rl_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($scope));
    }

    private static function storageDir(): string
    {
        $dir = dirname(__DIR__) . '/var/login_rate_limit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    private static function filePath(string $scope, string $kind, string $identifier): string
    {
        $scopeSafe = preg_replace('/[^a-z0-9_]/', '_', strtolower($scope)) ?? 'login';
        $hash = hash('sha256', $kind . '|' . $identifier);

        return self::storageDir() . '/' . $scopeSafe . '_' . $hash . '.json';
    }

    /** @return array{count: int, until: int}|null */
    private static function readFileBucket(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        /** @var array{count?: int, until?: int}|null $data */
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return self::normalizeBucket($data);
    }

    /** @param array{count: int, until: int} $bucket */
    private static function writeFileBucket(string $path, array $bucket): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $payload = json_encode($bucket, JSON_THROW_ON_ERROR);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }
        @rename($tmp, $path);
    }

    private static function deleteFileBucket(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
