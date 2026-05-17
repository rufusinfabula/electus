<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
require ROOT . '/vendor/autoload.php';

$config = require ROOT . '/config/config.php';
date_default_timezone_set($config['app']['timezone']);
session_name($config['app']['session_name'] ?? 'electus_session');
session_start();

$lang = $_SESSION['admin_lang'] ?? $config['app']['admin_lang'] ?? 'en';
\Electus\Core\Lang::init($lang);

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Flash;

if (Auth::isLoggedIn()) {
    header('Location: /admin/');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (Auth::login($email, $password)) {
        $user = Auth::currentUser();
        Flash::success(__('welcome_back', ['name' => $user['name']]));
        header('Location: /admin/');
        exit;
    }

    $error = __('invalid_credentials');
}
?>
<!DOCTYPE html>
<html lang="<?= \Electus\Core\Lang::current() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('login_title') ?> — Electus</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3/dist/css/uikit.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        body { background: var(--e-bg); display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .e-login-box { background: #fff; border-radius: 16px; padding: 40px 48px; width: 100%; max-width: 420px; box-shadow: 0 4px 24px rgba(76,61,158,.1); border: 1px solid #ece9f5; }
        .e-login-logo { text-align: center; margin-bottom: 32px; }
    </style>
</head>
<body>
    <div class="e-login-box">
        <div class="e-login-logo">
            <div class="e-logo-mark" style="width:48px;height:48px;font-size:1.5rem;display:inline-flex;align-items:center;justify-content:center;background:var(--e-primary);color:#fff;border-radius:12px;font-weight:800;">E</div>
            <h2 style="margin:12px 0 0;font-size:1.3rem;font-weight:700;color:var(--e-text);">Electus</h2>
        </div>

        <?php if ($error): ?>
        <div class="uk-alert-danger" uk-alert>
            <p><?= htmlspecialchars($error) ?></p>
        </div>
        <?php endif ?>

        <form method="post" action="">
            <?= Csrf::field() ?>
            <div class="uk-margin">
                <label class="uk-form-label"><?= __('email') ?></label>
                <div class="uk-form-controls">
                    <input class="uk-input" type="email" name="email"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           required autofocus>
                </div>
            </div>
            <div class="uk-margin">
                <label class="uk-form-label"><?= __('password') ?></label>
                <div class="uk-form-controls">
                    <input class="uk-input" type="password" name="password" required>
                </div>
            </div>
            <div class="uk-margin-top">
                <button class="uk-button uk-button-primary uk-width-1-1" type="submit">
                    <?= __('login') ?>
                </button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit-icons.min.js"></script>
</body>
</html>
