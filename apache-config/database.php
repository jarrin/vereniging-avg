<?php

class Database
{
    private static $pdo = null;

    public static function getConnection(): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $host = getenv('DB_HOST') ?: 'db';
        $db   = getenv('DB_NAME') ?: 'vereniging_avg';
        $user = getenv('DB_USER') ?: 'user';
        $pass = getenv('DB_PASSWORD') ?: 'password';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            self::$pdo = new \PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            die("DB connection failed: " . $e->getMessage());
        }

        return self::$pdo;
    }
}
