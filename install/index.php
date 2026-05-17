<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));

// Block reinstallation
if (file_exists(ROOT . '/config/config.php') && file_exists(__DIR__ . '/.installed')) {
    http_response_code(403);
    die('Electus is already installed. Delete /install/.installed to run the installer again.');
}

require ROOT . '/vendor/autoload.php';
session_start();

// ── Step logic ────────────────────────────────────────────────────────────────
$step   = (int) ($_SESSION['install_step'] ?? 1);
$errors = [];
$data   = $_SESSION['install_data'] ?? [];

function checkRequirements(): array
{
    $checks = [];
    $checks['php_version']  = version_compare(PHP_VERSION, '8.3.0', '>=');
    $checks['pdo_mysql']    = extension_loaded('pdo_mysql');
    $checks['mbstring']     = extension_loaded('mbstring');
    $checks['openssl']      = extension_loaded('openssl');
    $checks['json']         = extension_loaded('json');
    $checks['config_writable'] = is_writable(ROOT . '/config');
    return $checks;
}

function testDbConnection(array $db): bool
{
    try {
        $dsn = "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4";
        new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return true;
    } catch (PDOException) {
        return false;
    }
}

function createDatabase(array $db): PDO
{
    $dsn = "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$db['name']}`");
    return $pdo;
}

function runSchema(PDO $pdo): void
{
    $sql = file_get_contents(ROOT . '/sql/schema.sql');
    foreach (explode(';', $sql) as $statement) {
        $stmt = trim($statement);
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
}

function writeConfig(array $db, array $app, array $mail): void
{
    $template = "<?php\n\nreturn [\n"
        . "    'db' => [\n"
        . "        'host'    => " . var_export($db['host'], true) . ",\n"
        . "        'port'    => " . (int) $db['port'] . ",\n"
        . "        'name'    => " . var_export($db['name'], true) . ",\n"
        . "        'user'    => " . var_export($db['user'], true) . ",\n"
        . "        'pass'    => " . var_export($db['pass'], true) . ",\n"
        . "        'charset' => 'utf8mb4',\n"
        . "    ],\n"
        . "    'app' => [\n"
        . "        'name'         => " . var_export($app['name'], true) . ",\n"
        . "        'url'          => " . var_export(rtrim($app['url'], '/'), true) . ",\n"
        . "        'timezone'     => " . var_export($app['timezone'], true) . ",\n"
        . "        'debug'        => false,\n"
        . "        'session_name' => 'electus_session',\n"
        . "        'admin_lang'   => " . var_export($app['admin_lang'], true) . ",\n"
        . "        'public_lang'  => " . var_export($app['public_lang'], true) . ",\n"
        . "    ],\n"
        . "    'mail' => [\n"
        . "        'host'       => " . var_export($mail['host'], true) . ",\n"
        . "        'port'       => " . (int) $mail['port'] . ",\n"
        . "        'username'   => " . var_export($mail['username'], true) . ",\n"
        . "        'password'   => " . var_export($mail['password'], true) . ",\n"
        . "        'encryption' => " . var_export($mail['encryption'], true) . ",\n"
        . "        'from_email' => " . var_export($mail['from_email'], true) . ",\n"
        . "        'from_name'  => " . var_export($mail['from_name'], true) . ",\n"
        . "    ],\n"
        . "];\n";

    file_put_contents(ROOT . '/config/config.php', $template);
}

// ── POST handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($step === 1) {
        $reqs = checkRequirements();
        if (!in_array(false, $reqs, true)) {
            $_SESSION['install_step'] = 2;
        }
        header('Location: /install/');
        exit;
    }

    if ($step === 2) {
        $db = [
            'host' => trim($_POST['db_host'] ?? '127.0.0.1'),
            'port' => (int) ($_POST['db_port'] ?? 3306),
            'name' => trim($_POST['db_name'] ?? ''),
            'user' => trim($_POST['db_user'] ?? ''),
            'pass' => $_POST['db_pass'] ?? '',
        ];
        if (!$db['name'] || !$db['user']) {
            $errors[] = 'Database name and user are required.';
        } elseif (!testDbConnection($db)) {
            $errors[] = 'Could not connect to the database. Check your credentials.';
        } else {
            $_SESSION['install_data']['db'] = $db;
            $_SESSION['install_step'] = 3;
            header('Location: /install/');
            exit;
        }
    }

    if ($step === 3) {
        $app = [
            'name'        => trim($_POST['app_name'] ?? 'Electus'),
            'url'         => trim($_POST['app_url'] ?? ''),
            'timezone'    => trim($_POST['timezone'] ?? 'Europe/Rome'),
            'admin_lang'  => in_array($_POST['admin_lang'] ?? '', ['en','it','fr']) ? $_POST['admin_lang'] : 'en',
            'public_lang' => in_array($_POST['public_lang'] ?? '', ['en','it','fr']) ? $_POST['public_lang'] : 'en',
        ];
        $mail = [
            'host'       => trim($_POST['mail_host'] ?? 'localhost'),
            'port'       => (int) ($_POST['mail_port'] ?? 587),
            'username'   => trim($_POST['mail_user'] ?? ''),
            'password'   => $_POST['mail_pass'] ?? '',
            'encryption' => in_array($_POST['mail_enc'] ?? '', ['tls','ssl','']) ? ($_POST['mail_enc'] ?? 'tls') : 'tls',
            'from_email' => trim($_POST['mail_from'] ?? ''),
            'from_name'  => trim($_POST['mail_from_name'] ?? 'Electus'),
        ];
        $_SESSION['install_data']['app']  = $app;
        $_SESSION['install_data']['mail'] = $mail;
        $_SESSION['install_step'] = 4;
        header('Location: /install/');
        exit;
    }

    if ($step === 4) {
        $adminName  = trim($_POST['admin_name'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPass  = $_POST['admin_pass'] ?? '';
        $adminPass2 = $_POST['admin_pass2'] ?? '';

        if (!$adminName || !$adminEmail || !$adminPass) {
            $errors[] = 'All admin fields are required.';
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid admin email address.';
        } elseif (strlen($adminPass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif ($adminPass !== $adminPass2) {
            $errors[] = 'Passwords do not match.';
        } else {
            $db   = $_SESSION['install_data']['db'];
            $app  = $_SESSION['install_data']['app'];
            $mail = $_SESSION['install_data']['mail'];

            try {
                $pdo = createDatabase($db);
                runSchema($pdo);
                writeConfig($db, $app, $mail);

                $hash = password_hash($adminPass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
                $stmt->execute([$adminName, $adminEmail, $hash, 'superadmin']);

                file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));

                $_SESSION['install_step'] = 5;
                $_SESSION['install_data'] = [];
                header('Location: /install/');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Installation failed: ' . $e->getMessage();
            }
        }
    }
}

// Refresh step from session after potential redirects
$step = (int) ($_SESSION['install_step'] ?? 1);
$reqs = checkRequirements();

// ── Helpers ────────────────────────────────────────────────────────────────────
function stepClass(int $current, int $target): string
{
    if ($current > $target) return 'uk-step-done';
    if ($current === $target) return 'uk-step-active';
    return '';
}

$steps = ['Requirements', 'Database', 'Settings', 'Admin account', 'Done'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Electus — Installation</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3/dist/css/uikit.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        body { background: var(--e-bg); }
        .e-install-wrap { max-width: 600px; margin: 40px auto; padding: 0 20px; }
        .e-install-header { text-align: center; margin-bottom: 32px; }
        .e-install-card { background: #fff; border-radius: 16px; padding: 36px 40px; box-shadow: 0 4px 24px rgba(76,61,158,.08); border: 1px solid #ece9f5; }
        .e-steps { display: flex; justify-content: center; gap: 0; margin-bottom: 32px; }
        .e-step { display: flex; flex-direction: column; align-items: center; flex: 1; position: relative; }
        .e-step:not(:last-child)::after { content: ''; position: absolute; top: 14px; left: 50%; width: 100%; height: 2px; background: #ece9f5; z-index: 0; }
        .e-step-dot { width: 28px; height: 28px; border-radius: 50%; background: #ece9f5; color: #9a94b8; display: flex; align-items: center; justify-content: center; font-size: .75rem; font-weight: 700; position: relative; z-index: 1; }
        .e-step.uk-step-active .e-step-dot { background: var(--e-primary); color: #fff; }
        .e-step.uk-step-done .e-step-dot { background: #27ae60; color: #fff; }
        .e-step-label { font-size: .7rem; color: #9a94b8; margin-top: 6px; text-align: center; }
        .e-step.uk-step-active .e-step-label { color: var(--e-primary); font-weight: 600; }
        .e-req-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0eeff; font-size: .9rem; }
        .e-req-ok { color: #27ae60; font-weight: 700; }
        .e-req-fail { color: #e74c3c; font-weight: 700; }
    </style>
</head>
<body>
<div class="e-install-wrap">

    <div class="e-install-header">
        <div class="e-logo-mark" style="width:52px;height:52px;font-size:1.6rem;display:inline-flex;align-items:center;justify-content:center;background:var(--e-primary);color:#fff;border-radius:14px;font-weight:800;">E</div>
        <h1 style="margin:12px 0 4px;font-size:1.5rem;font-weight:800;color:var(--e-text);">Electus</h1>
        <p style="color:#9a94b8;margin:0;">Installation wizard</p>
    </div>

    <!-- Steps indicator -->
    <div class="e-steps">
        <?php foreach ($steps as $i => $label): ?>
        <div class="e-step <?= stepClass($step, $i + 1) ?>">
            <div class="e-step-dot">
                <?php if ($step > $i + 1): ?>✓<?php else: ?><?= $i + 1 ?><?php endif ?>
            </div>
            <div class="e-step-label"><?= htmlspecialchars($label) ?></div>
        </div>
        <?php endforeach ?>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="uk-alert-danger uk-margin-bottom" uk-alert>
        <?php foreach ($errors as $err): ?>
        <p><?= htmlspecialchars($err) ?></p>
        <?php endforeach ?>
    </div>
    <?php endif ?>

    <div class="e-install-card">

    <?php if ($step === 1): // Requirements ?>
        <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:20px;">System requirements</h2>
        <?php foreach ($reqs as $key => $ok): ?>
        <div class="e-req-row">
            <span><?= htmlspecialchars(str_replace('_', ' ', $key)) ?></span>
            <span class="<?= $ok ? 'e-req-ok' : 'e-req-fail' ?>"><?= $ok ? '✓ OK' : '✗ Missing' ?></span>
        </div>
        <?php endforeach ?>

        <form method="post" class="uk-margin-top">
            <button class="uk-button uk-button-primary uk-width-1-1"
                    <?= in_array(false, $reqs, true) ? 'disabled' : '' ?>>
                Continue &rarr;
            </button>
        </form>

    <?php elseif ($step === 2): // Database ?>
        <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:20px;">Database connection</h2>
        <form method="post">
            <div class="uk-margin">
                <label class="uk-form-label">Database host</label>
                <input class="uk-input" type="text" name="db_host" value="127.0.0.1" required>
            </div>
            <div class="uk-grid-small" uk-grid>
                <div class="uk-width-expand">
                    <label class="uk-form-label">Database name</label>
                    <input class="uk-input" type="text" name="db_name" placeholder="electus" required>
                </div>
                <div class="uk-width-auto" style="width:90px">
                    <label class="uk-form-label">Port</label>
                    <input class="uk-input" type="number" name="db_port" value="3306">
                </div>
            </div>
            <div class="uk-margin">
                <label class="uk-form-label">Database user</label>
                <input class="uk-input" type="text" name="db_user" required>
            </div>
            <div class="uk-margin">
                <label class="uk-form-label">Database password</label>
                <input class="uk-input" type="password" name="db_pass">
            </div>
            <button class="uk-button uk-button-primary uk-width-1-1 uk-margin-top">Continue &rarr;</button>
        </form>

    <?php elseif ($step === 3): // Settings ?>
        <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:20px;">Site settings</h2>
        <form method="post">
            <div class="uk-margin">
                <label class="uk-form-label">Site name</label>
                <input class="uk-input" type="text" name="app_name" value="Electus">
            </div>
            <div class="uk-margin">
                <label class="uk-form-label">Site URL</label>
                <input class="uk-input" type="url" name="app_url" placeholder="https://example.com" required>
            </div>
            <div class="uk-margin">
                <label class="uk-form-label">Timezone</label>
                <select class="uk-select" name="timezone">
                    <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?>
                    <option value="<?= $tz ?>" <?= $tz === 'Europe/Rome' ? 'selected' : '' ?>><?= $tz ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="uk-grid-small" uk-grid>
                <div class="uk-width-1-2">
                    <label class="uk-form-label">Admin panel language</label>
                    <select class="uk-select" name="admin_lang">
                        <option value="en">English</option>
                        <option value="it">Italiano</option>
                        <option value="fr">Français</option>
                    </select>
                </div>
                <div class="uk-width-1-2">
                    <label class="uk-form-label">Public interface language</label>
                    <select class="uk-select" name="public_lang">
                        <option value="en">English</option>
                        <option value="it">Italiano</option>
                        <option value="fr">Français</option>
                    </select>
                </div>
            </div>
            <hr>
            <p style="font-size:.85rem;color:#9a94b8;margin-bottom:12px;">Email (SMTP) — optional, needed for token delivery</p>
            <div class="uk-grid-small" uk-grid>
                <div class="uk-width-expand">
                    <label class="uk-form-label">SMTP host</label>
                    <input class="uk-input" type="text" name="mail_host" value="localhost">
                </div>
                <div class="uk-width-auto" style="width:90px">
                    <label class="uk-form-label">Port</label>
                    <input class="uk-input" type="number" name="mail_port" value="587">
                </div>
            </div>
            <div class="uk-margin">
                <label class="uk-form-label">From address</label>
                <input class="uk-input" type="email" name="mail_from" placeholder="noreply@example.com">
            </div>
            <div class="uk-grid-small" uk-grid>
                <div class="uk-width-1-2">
                    <label class="uk-form-label">SMTP username</label>
                    <input class="uk-input" type="text" name="mail_user">
                </div>
                <div class="uk-width-1-2">
                    <label class="uk-form-label">SMTP password</label>
                    <input class="uk-input" type="password" name="mail_pass">
                </div>
            </div>
            <button class="uk-button uk-button-primary uk-width-1-1 uk-margin-top">Continue &rarr;</button>
        </form>

    <?php elseif ($step === 4): // Admin account ?>
        <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:20px;">Create admin account</h2>
        <form method="post">
            <div class="uk-margin">
                <label class="uk-form-label">Full name</label>
                <input class="uk-input" type="text" name="admin_name" required>
            </div>
            <div class="uk-margin">
                <label class="uk-form-label">Email</label>
                <input class="uk-input" type="email" name="admin_email" required>
            </div>
            <div class="uk-margin">
                <label class="uk-form-label">Password <span style="font-size:.8em;color:#9a94b8">(min 8 chars)</span></label>
                <input class="uk-input" type="password" name="admin_pass" required minlength="8">
            </div>
            <div class="uk-margin">
                <label class="uk-form-label">Confirm password</label>
                <input class="uk-input" type="password" name="admin_pass2" required>
            </div>
            <button class="uk-button uk-button-primary uk-width-1-1 uk-margin-top">Install Electus</button>
        </form>

    <?php else: // Done ?>
        <div style="text-align:center;padding:20px 0">
            <div style="font-size:3rem;margin-bottom:16px;">🎉</div>
            <h2 style="font-size:1.2rem;font-weight:700;color:#27ae60;">Electus installed successfully!</h2>
            <p style="color:#6b6494;margin:12px 0 24px;">
                You can now log in to the admin panel.<br>
                <strong>For security, please delete or restrict access to the <code>/install/</code> folder.</strong>
            </p>
            <a href="/admin/" class="uk-button uk-button-primary">Go to admin panel &rarr;</a>
        </div>
    <?php endif ?>

    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit.min.js"></script>
</body>
</html>
