<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
require ROOT . '/vendor/autoload.php';

$config = require ROOT . '/config/config.php';
session_name($config['app']['session_name'] ?? 'electus_session');
session_start();

\Electus\Core\Auth::logout();
header('Location: /admin/login.php');
exit;
