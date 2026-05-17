<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Models\Category;
use Electus\Models\Event;

$eventId = (int) ($_GET['event_id'] ?? 0);
if (!$eventId) { header('Location: /admin/events.php'); exit; }

$event = Event::find($eventId);
if (!$event) { Flash::error('Event not found.'); header('Location: /admin/events.php'); exit; }

Auth::requireEventPermission($eventId);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        $name  = trim($_POST['name'] ?? '');
        $order = (int) ($_POST['sort_order'] ?? 0);
        if ($name) {
            Category::create($eventId, $name, $order);
            Flash::success(__('category_saved'));
        }
    }

    if ($action === 'update') {
        $catId = (int) ($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $order = (int) ($_POST['sort_order'] ?? 0);
        if ($catId && $name) {
            Category::update($catId, $name, $order);
            Flash::success(__('category_saved'));
        }
    }

    if ($action === 'delete') {
        $catId = (int) ($_POST['id'] ?? 0);
        if ($catId) {
            Category::delete($catId);
            Flash::success(__('category_deleted'));
        }
    }

    header('Location: /admin/categories.php?event_id=' . $eventId);
    exit;
}

$categories     = Category::forEvent($eventId);
$pageTitle      = __('categories_title');
$activeMenu     = 'categories';
$currentEventId = $eventId;

ob_start();
?>
<div class="uk-flex uk-flex-middle" style="gap:12px;margin-bottom:24px">
    <a href="/admin/events-edit.php?id=<?= $eventId ?>" uk-icon="arrow-left" class="uk-icon-link"></a>
    <div>
        <p style="margin:0;font-size:.8rem;color:#9a94b8"><?= htmlspecialchars($event['name']) ?></p>
        <h1 class="e-page-title uk-margin-remove"><?= __('categories_title') ?></h1>
    </div>
</div>

<div class="uk-grid-medium" uk-grid>

    <!-- Category list -->
    <div class="uk-width-2-3@m">
        <?php if (empty($categories)): ?>
        <div class="e-card uk-text-center" style="padding:40px">
            <p style="color:#9a94b8"><?= __('categories_title') ?>: 0</p>
        </div>
        <?php else: ?>
        <div class="e-table">
            <table class="uk-table uk-table-hover uk-table-divider uk-margin-remove">
                <thead>
                    <tr>
                        <th><?= __('name') ?></th>
                        <th style="width:80px"><?= __('sort_order') ?></th>
                        <th style="width:100px"><?= __('actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td><?= htmlspecialchars($cat['name']) ?></td>
                        <td><?= $cat['sort_order'] ?></td>
                        <td>
                            <div class="uk-flex" style="gap:8px">
                                <!-- Inline edit via modal -->
                                <a href="#modal-edit-<?= $cat['id'] ?>" uk-toggle
                                   uk-icon="pencil" class="uk-icon-link"
                                   uk-tooltip="<?= __('edit') ?>"></a>
                                <form method="post" style="display:inline">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                    <button type="submit"
                                            uk-icon="trash"
                                            uk-tooltip="<?= __('delete') ?>"
                                            data-confirm="<?= htmlspecialchars(__('confirm_delete')) ?>"
                                            style="color:#e74c3c;background:none;border:none;cursor:pointer;padding:0"
                                            class="uk-icon-link"></button>
                                </form>
                            </div>

                            <!-- Edit modal -->
                            <div id="modal-edit-<?= $cat['id'] ?>" uk-modal>
                                <div class="uk-modal-dialog uk-modal-body">
                                    <h3 class="uk-modal-title"><?= __('edit') ?></h3>
                                    <form method="post">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="_action" value="update">
                                        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                        <div class="uk-margin">
                                            <input class="uk-input" type="text" name="name"
                                                   value="<?= htmlspecialchars($cat['name']) ?>" required>
                                        </div>
                                        <div class="uk-margin">
                                            <label class="uk-form-label"><?= __('sort_order') ?></label>
                                            <input class="uk-input" type="number" name="sort_order"
                                                   value="<?= $cat['sort_order'] ?>" style="width:80px">
                                        </div>
                                        <div class="uk-flex" style="gap:8px">
                                            <button class="uk-button uk-button-primary"><?= __('save') ?></button>
                                            <button class="uk-button uk-button-default uk-modal-close"><?= __('cancel') ?></button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>
    </div>

    <!-- Add category form -->
    <div class="uk-width-1-3@m">
        <div class="e-card">
            <h3 style="font-size:1rem;font-weight:600;margin-bottom:16px"><?= __('category_new') ?></h3>
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="_action" value="create">
                <div class="uk-margin">
                    <label class="uk-form-label"><?= __('name') ?></label>
                    <input class="uk-input" type="text" name="name" required autofocus>
                </div>
                <div class="uk-margin">
                    <label class="uk-form-label"><?= __('sort_order') ?></label>
                    <input class="uk-input" type="number" name="sort_order"
                           value="<?= count($categories) * 10 ?>" style="width:80px">
                </div>
                <button class="uk-button uk-button-primary uk-width-1-1"><?= __('create') ?></button>
            </form>
        </div>
    </div>

</div>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
