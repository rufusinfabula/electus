<?php

declare(strict_types=1);

namespace Electus\Core;

class Lang
{
    private static string $current = 'en';
    private static array $strings  = [];
    private static array $loaded   = [];

    public static function init(string $lang): void
    {
        self::$current = $lang;
        self::load($lang);
    }

    private static function load(string $lang): void
    {
        if (isset(self::$loaded[$lang])) {
            return;
        }

        $file = ROOT . '/lang/' . $lang . '.php';
        if (file_exists($file)) {
            self::$strings = array_merge(self::$strings, require $file);
        }

        self::$loaded[$lang] = true;
    }

    public static function get(string $key, array $params = []): string
    {
        $string = self::$strings[$key] ?? $key;

        foreach ($params as $placeholder => $value) {
            $string = str_replace(':' . $placeholder, (string) $value, $string);
        }

        return $string;
    }

    public static function current(): string
    {
        return self::$current;
    }

    public static function available(): array
    {
        return ['en', 'it', 'fr'];
    }
}

// Global shorthand
function __(string $key, array $params = []): string
{
    return Lang::get($key, $params);
}
