<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));

require ROOT . '/vendor/autoload.php';

// Redirect to installer if not yet installed
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

$sessionName = $config['app']['session_name'] ?? 'electus_session';
session_name($sessionName);
session_start();

// Initialize i18n for admin panel
$adminLang = $_SESSION['admin_lang'] ?? $config['app']['admin_lang'] ?? 'en';
\Electus\Core\Lang::init($adminLang);

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Flash;

// Allow login.php through without auth check
$currentFile = basename($_SERVER['PHP_SELF']);
if (!in_array($currentFile, ['login.php', 'logout.php'], true)) {
    Auth::requireLogin();
}
