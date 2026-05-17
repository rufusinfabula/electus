<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));

require ROOT . '/vendor/autoload.php';

if (!file_exists(ROOT . '/config/config.php')) {
    header('Location: /install/');
    exit;
}

$config = require ROOT . '/config/config.php';

date_default_timezone_set($config['app']['timezone']);

if ($config['app']['debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

session_name($config['app']['session_name'] ?? 'electus_session');
session_start();

$publicLang = $_SESSION['public_lang'] ?? $config['app']['public_lang'] ?? 'en';
\Electus\Core\Lang::init($publicLang);
