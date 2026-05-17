<?php

declare(strict_types=1);

namespace Electus\Core;

use Electus\Core\Database;
use PDO;

class Auth
{
    public static function login(string $email, string $password): bool
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND status = "active" LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'    => $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ];
            return true;
        }

        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['user']['id']);
    }

    public static function requireLogin(string $redirectTo = '/admin/login.php'): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }

    public static function currentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function hasRole(string ...$roles): bool
    {
        $role = $_SESSION['user']['role'] ?? '';
        return in_array($role, $roles, true);
    }

    public static function requireRole(string ...$roles): void
    {
        if (!self::hasRole(...$roles)) {
            http_response_code(403);
            exit('Access denied.');
        }
    }

    public static function hasEventPermission(int $eventId): bool
    {
        if (self::hasRole('superadmin')) {
            return true;
        }

        $userId = $_SESSION['user']['id'] ?? 0;
        $pdo    = Database::get();
        $stmt   = $pdo->prepare('SELECT id FROM user_event_permissions WHERE user_id = ? AND event_id = ? LIMIT 1');
        $stmt->execute([$userId, $eventId]);
        return (bool) $stmt->fetch();
    }

    public static function requireEventPermission(int $eventId): void
    {
        if (!self::hasEventPermission($eventId)) {
            http_response_code(403);
            exit('Access denied.');
        }
    }

    public static function createUser(string $name, string $email, string $password, string $role = 'event_manager'): int
    {
        $pdo  = Database::get();
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $email, $hash, $role]);
        return (int) $pdo->lastInsertId();
    }
}
