<?php

declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(array $config): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $db = $config['db'];
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $db['host'],
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );
        if (!empty($db['port'])) {
            $dsn .= ';port=' . (int) $db['port'];
        }
        self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]);
        self::$pdo->exec(
            'SET CHARACTER SET utf8mb4'
            . ', character_set_connection=utf8mb4'
            . ', character_set_results=utf8mb4'
            . ', collation_connection=utf8mb4_unicode_ci'
        );

        return self::$pdo;
    }
}
