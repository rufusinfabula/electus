<?php

declare(strict_types=1);

namespace Electus\Models;

use Electus\Core\Database;

class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $stmt = Database::get()->prepare('SELECT value FROM app_settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetchColumn();
        return $row !== false ? $row : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        Database::get()->prepare(
            'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()'
        )->execute([$key, $value]);
    }
}
