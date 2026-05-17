<?php

declare(strict_types=1);

if (!function_exists('__')) {
    function __(string $key, array $params = []): string
    {
        return \Electus\Core\Lang::get($key, $params);
    }
}
