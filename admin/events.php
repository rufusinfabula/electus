<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Models\Event;

// Handle set_lang redirect
if (isset($_GET['set_lang'])) {
    $allowed = ['en', 'it', 'fr'];
    if (in_array($_GET['set_lang'], $allowed, true)) {
        $_SESSION['admin_lang'] = $_GET['set_lang'];
    }
    header('Location: /admin/events.php');
    exit;
}

// Delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    Csrf::check();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id && Auth::hasRole('superadmin', 'event_manager')) {
        Event::delete($id);
        Flash::success(__('event_deleted'));
    }
    header('Location: /admin/events.php');
    exit;
}

$events     = Event::all();
$pageTitle  = __('events_title');
$activeMenu = 'events';

ob_start();
?>
<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom">
    <h1 class="e-page-title uk-margin-remove"><?= __('events_title') ?></h1>
    <a href="/admin/events-edit.php" class="uk-button uk-button-primary">
        <span uk-icon="plus-circle"></span> <?= __('event_new') ?>
    </a>
</div>

<?php if (empty($events)): ?>
<div class="e-card uk-text-center" style="padding:60px">
    <span uk-icon="icon:calendar;ratio:2" style="color:#c8c3e0"></span>
    <p style="color:#9a94b8;margin-top:12px"><?= __('events_title') ?>: 0</p>
    <a href="/admin/events-edit.php" class="uk-button uk-button-primary uk-margin-top"><?= __('event_new') ?></a>
</div>
<?php else: ?>
<div class="e-table">
    <table class="uk-table uk-table-hover uk-table-divider uk-margin-remove">
        <thead>
            <tr>
                <th><?= __('name') ?></th>
                <th><?= __('event_access_mode') ?></th>
                <th><?= __('event_status') ?></th>
                <th><?= __('event_results_timing') ?></th>
                <th><?= __('created_at') ?></th>
                <th style="width:120px"><?= __('actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($events as $event): ?>
            <tr>
                <td>
                    <strong>
                        <a href="/admin/events-edit.php?id=<?= $event['id'] ?>" style="color:var(--e-text)">
                            <?= htmlspecialchars($event['name']) ?>
                        </a>
                    </strong>
                    <br><small style="color:#9a94b8">/<?= htmlspecialchars($event['slug']) ?></small>
                </td>
                <td><?= __('access_' . $event['access_mode']) ?></td>
                <td>
                    <span class="e-badge e-badge-<?= $event['status'] ?>">
                        <?= __('event_status_' . $event['status']) ?>
                    </span>
                </td>
                <td><?= __('results_timing_' . $event['results_timing']) ?></td>
                <td><?= date('d/m/Y', strtotime($event['created_at'])) ?></td>
                <td>
                    <div class="uk-flex uk-flex-middle" style="gap:8px">
                        <a href="/admin/rounds.php?event_id=<?= $event['id'] ?>"
                           uk-icon="list" uk-tooltip="<?= __('rounds_title') ?>"
                           class="uk-icon-link"></a>
                        <a href="/admin/events-edit.php?id=<?= $event['id'] ?>"
                           uk-icon="pencil" uk-tooltip="<?= __('edit') ?>"
                           class="uk-icon-link"></a>
                        <?php if (Auth::hasRole('superadmin')): ?>
                        <form method="post" style="display:inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="_action" value="delete">
                            <input type="hidden" name="id" value="<?= $event['id'] ?>">
                            <button type="submit" class="uk-icon-link"
                                    uk-icon="trash"
                                    uk-tooltip="<?= __('delete') ?>"
                                    data-confirm="<?= htmlspecialchars(__('confirm_delete')) ?>"
                                    style="color:#e74c3c;background:none;border:none;cursor:pointer;padding:0"></button>
                        </form>
                        <?php endif ?>
                    </div>
                </td>
            </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
