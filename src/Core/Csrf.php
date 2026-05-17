<?php

declare(strict_types=1);

namespace Electus\Core;

class Csrf
{
    private const TOKEN_KEY = '_csrf_token';

    public static function generate(): string
    {
        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::TOKEN_KEY];
    }

    public static function verify(string $token): bool
    {
        $stored = $_SESSION[self::TOKEN_KEY] ?? '';
        return hash_equals($stored, $token);
    }

    public static function field(): string
    {
        $token = self::generate();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
    }

    public static function check(): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        if (!self::verify($token)) {
            http_response_code(403);
            exit('Invalid CSRF token.');
        }
    }
}
