<!DOCTYPE html>
<html lang="<?= \Electus\Core\Lang::current() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? __('app_name')) ?> — <?= __('app_name') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3/dist/css/uikit.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>

<!-- Top navbar -->
<nav class="uk-navbar-container uk-navbar-transparent e-navbar" uk-navbar>
    <div class="uk-navbar-left uk-margin-left">
        <a class="uk-navbar-item uk-logo e-logo" href="/admin/">
            <span class="e-logo-mark">E</span>
            <span class="e-logo-text">Electus</span>
        </a>
    </div>
    <div class="uk-navbar-right uk-margin-right">
        <ul class="uk-navbar-nav">
            <!-- Language switcher -->
            <li>
                <a href="#"><span uk-icon="world"></span> <?= strtoupper(\Electus\Core\Lang::current()) ?></a>
                <div class="uk-navbar-dropdown uk-navbar-dropdown-small">
                    <ul class="uk-nav uk-navbar-dropdown-nav">
                        <?php foreach (\Electus\Core\Lang::available() as $lang): ?>
                        <li <?= $lang === \Electus\Core\Lang::current() ? 'class="uk-active"' : '' ?>>
                            <a href="?set_lang=<?= $lang ?>"><?= strtoupper($lang) ?></a>
                        </li>
                        <?php endforeach ?>
                    </ul>
                </div>
            </li>
            <li>
                <a href="#">
                    <span uk-icon="user"></span>
                    <?= htmlspecialchars(\Electus\Core\Auth::currentUser()['name'] ?? '') ?>
                </a>
            </li>
            <li>
                <a href="/admin/logout.php" title="<?= __('logout') ?>">
                    <span uk-icon="sign-out"></span>
                </a>
            </li>
        </ul>
    </div>
</nav>

<div class="uk-grid-collapse uk-child-width-auto" uk-grid>

    <!-- Sidebar -->
    <div class="e-sidebar">
        <ul class="uk-nav uk-nav-default e-nav">
            <li class="uk-nav-header">Menu</li>

            <li <?= ($activeMenu ?? '') === 'dashboard' ? 'class="uk-active"' : '' ?>>
                <a href="/admin/"><span uk-icon="home"></span> <?= __('nav_dashboard') ?></a>
            </li>

            <li class="uk-nav-divider"></li>
            <li class="uk-nav-header"><?= __('nav_events') ?></li>

            <li <?= ($activeMenu ?? '') === 'events' ? 'class="uk-active"' : '' ?>>
                <a href="/admin/events.php"><span uk-icon="calendar"></span> <?= __('nav_events') ?></a>
            </li>

            <?php if (!empty($currentEventId)): ?>
            <li class="uk-nav-sub-item <?= ($activeMenu ?? '') === 'rounds' ? 'uk-active' : '' ?>">
                <a href="/admin/rounds.php?event_id=<?= $currentEventId ?>">
                    <span uk-icon="list"></span> <?= __('rounds_title') ?>
                </a>
            </li>
            <li class="uk-nav-sub-item <?= ($activeMenu ?? '') === 'categories' ? 'uk-active' : '' ?>">
                <a href="/admin/categories.php?event_id=<?= $currentEventId ?>">
                    <span uk-icon="tag"></span> <?= __('categories_title') ?>
                </a>
            </li>
            <li class="uk-nav-sub-item <?= ($activeMenu ?? '') === 'voters' ? 'uk-active' : '' ?>">
                <a href="/admin/voters.php?event_id=<?= $currentEventId ?>">
                    <span uk-icon="users"></span> <?= __('voters_title') ?>
                </a>
            </li>
            <li class="uk-nav-sub-item <?= ($activeMenu ?? '') === 'results' ? 'uk-active' : '' ?>">
                <a href="/admin/rounds.php?event_id=<?= $currentEventId ?>#results">
                    <span uk-icon="bar-chart"></span> <?= __('results_title') ?>
                </a>
            </li>
            <?php endif ?>

            <?php if (\Electus\Core\Auth::hasRole('superadmin')): ?>
            <li class="uk-nav-divider"></li>
            <li class="uk-nav-header"><?= __('nav_settings') ?></li>
            <li <?= ($activeMenu ?? '') === 'users' ? 'class="uk-active"' : '' ?>>
                <a href="/admin/users.php"><span uk-icon="cog"></span> <?= __('users_title') ?></a>
            </li>
            <?php endif ?>
        </ul>
    </div>

    <!-- Main content -->
    <div class="uk-width-expand">
        <div class="e-content">

            <?php
            // Flash messages
            foreach (\Electus\Core\Flash::get() as $msg):
            ?>
            <div class="uk-alert-<?= htmlspecialchars($msg['type']) ?>" uk-alert>
                <a class="uk-alert-close" uk-close></a>
                <p><?= htmlspecialchars($msg['message']) ?></p>
            </div>
            <?php endforeach ?>

            <?= $content ?? '' ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit-icons.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script src="/assets/js/admin.js"></script>
</body>
</html>
