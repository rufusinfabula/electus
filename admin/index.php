<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\Database;

$pdo  = Database::get();
$user = Auth::currentUser();

// Handle language switch
if (isset($_GET['set_lang'])) {
    $allowed = ['en', 'it', 'fr'];
    if (in_array($_GET['set_lang'], $allowed, true)) {
        $_SESSION['admin_lang'] = $_GET['set_lang'];
    }
    header('Location: /admin/');
    exit;
}

// Stats for dashboard
$totalEvents  = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
$activeEvents = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'active'")->fetchColumn();
$votesToday   = (int) $pdo->query("SELECT COUNT(*) FROM votes WHERE DATE(voted_at) = CURDATE()")->fetchColumn();

$recentEvents = $pdo->query(
    "SELECT e.*, u.name AS creator_name
     FROM events e
     JOIN users u ON u.id = e.created_by
     ORDER BY e.created_at DESC
     LIMIT 8"
)->fetchAll();

$pageTitle  = __('dashboard_title');
$activeMenu = 'dashboard';

ob_start();
?>
<h1 class="e-page-title"><?= __('dashboard_title') ?></h1>

<!-- Stats row -->
<div class="uk-grid-small uk-child-width-1-3@m uk-margin-bottom" uk-grid>
    <div>
        <div class="e-stat">
            <div class="e-stat-value"><?= $totalEvents ?></div>
            <div class="e-stat-label"><?= __('dashboard_events_total') ?></div>
        </div>
    </div>
    <div>
        <div class="e-stat">
            <div class="e-stat-value" style="color:#1a7a3c"><?= $activeEvents ?></div>
            <div class="e-stat-label"><?= __('dashboard_active') ?></div>
        </div>
    </div>
    <div>
        <div class="e-stat">
            <div class="e-stat-value"><?= $votesToday ?></div>
            <div class="e-stat-label"><?= __('dashboard_votes_today') ?></div>
        </div>
    </div>
</div>

<!-- Recent events -->
<div class="e-card">
    <div class="uk-flex uk-flex-between uk-flex-middle uk-margin-small-bottom">
        <h3 class="uk-margin-remove" style="font-size:1rem;font-weight:600;"><?= __('events_title') ?></h3>
        <a href="/admin/events.php" class="uk-button uk-button-primary uk-button-small"><?= __('event_new') ?></a>
    </div>

    <?php if (empty($recentEvents)): ?>
    <p class="uk-text-muted"><?= __('events_title') ?>: 0</p>
    <?php else: ?>
    <div class="e-table">
        <table class="uk-table uk-table-hover uk-table-divider uk-margin-remove">
            <thead>
                <tr>
                    <th><?= __('name') ?></th>
                    <th><?= __('event_access_mode') ?></th>
                    <th><?= __('event_status') ?></th>
                    <th><?= __('created_at') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentEvents as $event): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($event['name']) ?></strong></td>
                    <td><?= __('access_' . $event['access_mode']) ?></td>
                    <td>
                        <span class="e-badge e-badge-<?= $event['status'] ?>">
                            <?= __('event_status_' . $event['status']) ?>
                        </span>
                    </td>
                    <td><?= date('d/m/Y', strtotime($event['created_at'])) ?></td>
                    <td>
                        <a href="/admin/events-edit.php?id=<?= $event['id'] ?>"
                           class="uk-icon-link" uk-icon="pencil" title="<?= __('edit') ?>"></a>
                    </td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php endif ?>
</div>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
