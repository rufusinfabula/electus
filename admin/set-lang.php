<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
require ROOT . '/vendor/autoload.php';

$config = require ROOT . '/config/config.php';
session_name($config['app']['session_name'] ?? 'electus_session');
session_start();

$allowed = ['en', 'it', 'fr'];
$lang    = $_GET['lang'] ?? 'en';

if (in_array($lang, $allowed, true)) {
    $_SESSION['admin_lang'] = $lang;
}

http_response_code(200);
exit;
