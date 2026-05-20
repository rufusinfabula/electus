<!DOCTYPE html>
<html lang="<?= \Electus\Core\Lang::current() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? __('app_name')) ?> — <?= __('app_name') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3/dist/css/uikit.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <?php
    $__adminTheme = \Electus\Models\Settings::get('admin_theme', \Electus\Core\Theme::DEFAULT_PRESET);
    $__adminPal   = \Electus\Core\Theme::PALETTES[$__adminTheme] ?? \Electus\Core\Theme::PALETTES[\Electus\Core\Theme::DEFAULT_PRESET];
    echo \Electus\Core\Theme::cssBlock($__adminPal);
    ?>
    <?php if (!empty($useQuill)): ?>
    <link href="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.snow.css" rel="stylesheet">
    <?php endif ?>
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
        <ul class="uk-nav uk-nav-default e-nav" uk-nav="multiple: true; targets: .uk-parent">
            <li class="uk-nav-header">Menu</li>

            <li <?= ($activeMenu ?? '') === 'dashboard' ? 'class="uk-active"' : '' ?>>
                <a href="/admin/"><span uk-icon="home"></span> <?= __('nav_dashboard') ?></a>
            </li>

            <li class="uk-nav-divider"></li>
            <li class="uk-nav-header"><?= __('nav_events') ?></li>

            <li <?= ($activeMenu ?? '') === 'events' ? 'class="uk-active"' : '' ?>>
                <a href="/admin/events.php"><span uk-icon="calendar"></span> <?= __('nav_events') ?></a>
            </li>

            <?php if (!empty($currentEventId)):
                if (empty($currentEventName)) {
                    $__stmt = \Electus\Core\Database::get()->prepare('SELECT name, cat_term FROM events WHERE id = ? LIMIT 1');
                    $__stmt->execute([$currentEventId]);
                    $__evRow = $__stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
                    $currentEventName = $__evRow['name'] ?? '';
                } else {
                    $__evRow = $event ?? [];
                }
                $__catTermP = \Electus\Core\CatTerm::label($__evRow, 'p');
                // Load all rounds for accordion
                $__stmt = \Electus\Core\Database::get()->prepare(
                    'SELECT id, round_number, label, status FROM event_rounds
                     WHERE event_id = ? ORDER BY round_number ASC'
                );
                $__stmt->execute([$currentEventId]);
                $__rounds = $__stmt->fetchAll();

                // Resolve sidebar round: prefer active, then first
                $__sidebarRoundId = (int) ($currentRoundId ?? 0);
                if (!$__sidebarRoundId && !empty($__rounds)) {
                    foreach ($__rounds as $__r) {
                        if ($__r['status'] === 'active') { $__sidebarRoundId = (int) $__r['id']; break; }
                    }
                    if (!$__sidebarRoundId) $__sidebarRoundId = (int) $__rounds[0]['id'];
                }
                $__activeMenu = $activeMenu ?? '';
            ?>

            <!-- Event block: collapsible accordion tree -->
            <li class="uk-parent uk-open e-nav-event-title">
                <a href="#"><?= htmlspecialchars($currentEventName) ?></a>
                <ul class="uk-nav-sub">

                    <li class="<?= $__activeMenu === 'rounds' ? 'uk-active' : '' ?>">
                        <a href="/admin/rounds.php?event_id=<?= $currentEventId ?>">
                            <span uk-icon="icon:grid;ratio:.8"></span> <?= __('nav_overview') ?>
                        </a>
                    </li>
                    <li class="<?= $__activeMenu === 'categories' ? 'uk-active' : '' ?>">
                        <a href="/admin/categories.php?event_id=<?= $currentEventId ?>">
                            <span uk-icon="icon:tag;ratio:.8"></span> <?= $__catTermP ?>
                        </a>
                    </li>
                    <li class="<?= $__activeMenu === 'voters' ? 'uk-active' : '' ?>">
                        <a href="/admin/voters.php?event_id=<?= $currentEventId ?>">
                            <span uk-icon="icon:users;ratio:.8"></span> <?= __('voters_title') ?>
                        </a>
                    </li>

                    <?php foreach ($__rounds as $__r):
                        $__rOpen      = ($__sidebarRoundId === (int) $__r['id']);
                        $__candActive = $__activeMenu === 'candidates' && $__rOpen;
                        $__resActive  = $__activeMenu === 'results'    && $__rOpen;
                        $__dotStyle   = match($__r['status']) {
                            'active'  => 'background:#27ae60',
                            'closed'  => 'background:#9a94b8',
                            default   => 'background:transparent;border:2px solid #c8c3e0;box-sizing:border-box',
                        };
                    ?>
                    <li class="uk-parent <?= $__rOpen ? 'uk-open' : '' ?> e-nav-round-item">
                        <a href="#">
                            <span class="e-nav-round-dot" style="<?= $__dotStyle ?>"
                                  title="<?= htmlspecialchars(__('event_status_' . $__r['status'])) ?>"></span>
                            <span class="e-nav-round-label">
                                <?= __('round_number') ?><?= $__r['round_number'] ?>
                                <?php if ($__r['label']): ?>
                                <span class="e-nav-round-sub"><?= htmlspecialchars($__r['label']) ?></span>
                                <?php endif ?>
                            </span>
                        </a>
                        <ul class="uk-nav-sub">
                            <li <?= $__candActive ? 'class="uk-active"' : '' ?>>
                                <a href="/admin/candidates.php?round_id=<?= $__r['id'] ?>">
                                    <span uk-icon="icon:list;ratio:.8"></span> <?= __('candidates_title') ?>
                                </a>
                            </li>
                            <li <?= $__resActive ? 'class="uk-active"' : '' ?>>
                                <a href="/admin/results.php?round_id=<?= $__r['id'] ?>">
                                    <span uk-icon="icon:thumbnails;ratio:.8"></span> <?= __('results_title') ?>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php endforeach ?>

                </ul>
            </li>

            <?php endif ?>

            <?php if (\Electus\Core\Auth::hasRole('superadmin')): ?>
            <li class="uk-nav-divider"></li>
            <li class="uk-nav-header"><?= __('nav_settings') ?></li>
            <li <?= ($activeMenu ?? '') === 'users' ? 'class="uk-active"' : '' ?>>
                <a href="/admin/users.php"><span uk-icon="icon:users;ratio:.85"></span> <?= __('users_title') ?></a>
            </li>
            <li <?= ($activeMenu ?? '') === 'settings' ? 'class="uk-active"' : '' ?>>
                <a href="/admin/settings.php"><span uk-icon="icon:paint-bucket;ratio:.85"></span> <?= __('nav_admin_theme') ?></a>
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
<?php if (!empty($useQuill)): ?>
<script src="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.js"></script>
<script>
document.querySelectorAll('.e-quill-editor').forEach(function(container) {
    var input = document.getElementById(container.dataset.target);
    var q = new Quill(container, {
        theme: 'snow',
        modules: { toolbar: [
            ['bold', 'italic', 'underline'],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['link'],
            ['clean']
        ]}
    });
    if (input && input.value) q.root.innerHTML = input.value;
    var form = container.closest('form');
    if (form) {
        form.addEventListener('submit', function() {
            if (input) input.value = q.root.innerHTML === '<p><br></p>' ? '' : q.root.innerHTML;
        });
    }
});
</script>
<?php endif ?>
</body>
</html>
