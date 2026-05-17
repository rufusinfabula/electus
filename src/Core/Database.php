<?php

declare(strict_types=1);

namespace Electus\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $cfg = require ROOT . '/config/config.php';
            $db  = $cfg['db'];
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";

            try {
                self::$instance = new PDO($dsn, $db['user'], $db['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                if ($cfg['app']['debug']) {
                    throw $e;
                }
                http_response_code(500);
                die('Database connection failed.');
            }
        }

        return self::$instance;
    }

    private function __construct() {}
    private function __clone() {}
}
