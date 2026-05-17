<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Models\Event;
use Electus\Models\Round;

$eventId = (int) ($_GET['event_id'] ?? 0);
if (!$eventId) { header('Location: /admin/events.php'); exit; }

$event = Event::find($eventId);
if (!$event) { Flash::error('Event not found.'); header('Location: /admin/events.php'); exit; }

Auth::requireEventPermission($eventId);

// Quick status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $action  = $_POST['_action'] ?? '';
    $roundId = (int) ($_POST['round_id'] ?? 0);

    if ($roundId && in_array($action, ['activate','close','delete'], true)) {
        if ($action === 'activate') {
            Round::setStatus($roundId, 'active');
            Flash::success('Round activated.');
        } elseif ($action === 'close') {
            Round::setStatus($roundId, 'closed');
            Flash::success('Round closed.');
        } elseif ($action === 'delete') {
            Round::delete($roundId);
            Flash::success('Round deleted.');
        }
    }

    header('Location: /admin/rounds.php?event_id=' . $eventId);
    exit;
}

$rounds         = Round::forEvent($eventId);
$pageTitle      = __('rounds_title');
$activeMenu     = 'rounds';
$currentEventId = $eventId;

$modelIcons = [
    'open'         => 'pencil',
    'single'       => 'check',
    'multiple'     => 'thumbnails',
    'borda'        => 'star',
    'proportional' => 'pull',
    'weighted'     => 'more',
];

ob_start();
?>
<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom">
    <div class="uk-flex uk-flex-middle" style="gap:12px">
        <a href="/admin/events-edit.php?id=<?= $eventId ?>" uk-icon="arrow-left" class="uk-icon-link"></a>
        <div>
            <p style="margin:0;font-size:.8rem;color:#9a94b8"><?= htmlspecialchars($event['name']) ?></p>
            <h1 class="e-page-title uk-margin-remove"><?= __('rounds_title') ?></h1>
        </div>
    </div>
    <div class="uk-flex" style="gap:8px">
        <a href="/admin/categories.php?event_id=<?= $eventId ?>" class="uk-button uk-button-default uk-button-small">
            <?= __('categories_title') ?>
        </a>
        <a href="/admin/rounds-edit.php?event_id=<?= $eventId ?>" class="uk-button uk-button-primary uk-button-small">
            <span uk-icon="plus-circle"></span> <?= __('round_new') ?>
        </a>
    </div>
</div>

<?php if (empty($rounds)): ?>
<div class="e-card uk-text-center" style="padding:60px">
    <span uk-icon="icon:list;ratio:2" style="color:#c8c3e0"></span>
    <p style="color:#9a94b8;margin-top:12px"><?= __('rounds_title') ?>: 0</p>
    <a href="/admin/rounds-edit.php?event_id=<?= $eventId ?>" class="uk-button uk-button-primary uk-margin-top">
        <?= __('round_new') ?>
    </a>
</div>
<?php else: ?>
<div class="uk-grid-small" uk-grid>
    <?php foreach ($rounds as $round): ?>
    <div class="uk-width-1-1">
        <div class="e-card" style="position:relative">

            <!-- Status badges -->
            <div style="position:absolute;top:20px;right:20px;display:flex;gap:6px;align-items:center">
                <?php if ($round['results_released']): ?>
                <span class="e-badge e-badge-active" title="Results public">&#127758;</span>
                <?php elseif ($round['votes_validated']): ?>
                <span class="e-badge" style="background:#fff3cd;color:#856404" title="Votes validated">&#10003; validated</span>
                <?php endif ?>
                <span class="e-badge e-badge-<?= $round['status'] ?>">
                    <?= __('event_status_' . $round['status']) ?>
                </span>
            </div>

            <div class="uk-flex uk-flex-middle" style="gap:12px;margin-bottom:8px">
                <span uk-icon="icon:<?= $modelIcons[$round['model']] ?? 'list' ?>"
                      style="color:var(--e-accent)"></span>
                <h3 style="margin:0;font-size:1rem;font-weight:700">
                    <?= __('round_number') ?><?= $round['round_number'] ?>
                    <?php if ($round['label']): ?>
                    — <?= htmlspecialchars($round['label']) ?>
                    <?php endif ?>
                </h3>
            </div>

            <p style="margin:0 0 12px;color:#6b6494;font-size:.875rem">
                <strong><?= __('round_model') ?>:</strong> <?= __('model_' . $round['model']) ?>
                <?php if ($round['opens_at']): ?>
                &nbsp;·&nbsp;<?= date('d/m/Y H:i', strtotime($round['opens_at'])) ?>
                →
                <?= $round['closes_at'] ? date('d/m/Y H:i', strtotime($round['closes_at'])) : '∞' ?>
                <?php endif ?>
                <?php if ($round['parent_label']): ?>
                &nbsp;·&nbsp;follows: <?= htmlspecialchars($round['parent_label']) ?>
                <?php endif ?>
            </p>

            <!-- Actions -->
            <div class="uk-flex uk-flex-wrap" style="gap:8px">
                <a href="/admin/candidates.php?round_id=<?= $round['id'] ?>"
                   class="uk-button uk-button-default uk-button-small">
                    <?= __('candidates_title') ?>
                </a>
                <?php if ($round['model'] === 'open'): ?>
                <a href="/admin/dedup.php?round_id=<?= $round['id'] ?>"
                   class="uk-button uk-button-default uk-button-small">
                    <?= __('dedup_title') ?>
                </a>
                <?php endif ?>
                <a href="/admin/results.php?round_id=<?= $round['id'] ?>"
                   class="uk-button uk-button-default uk-button-small">
                    <?= __('results_title') ?>
                </a>
                <a href="/admin/rounds-edit.php?id=<?= $round['id'] ?>&event_id=<?= $eventId ?>"
                   class="uk-button uk-button-default uk-button-small">
                    <?= __('edit') ?>
                </a>

                <form method="post" style="display:inline">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="round_id" value="<?= $round['id'] ?>">
                    <?php if ($round['status'] === 'draft'): ?>
                    <input type="hidden" name="_action" value="activate">
                    <button class="uk-button uk-button-primary uk-button-small">Activate</button>
                    <?php elseif ($round['status'] === 'active'): ?>
                    <input type="hidden" name="_action" value="close">
                    <button class="uk-button uk-button-danger uk-button-small">Close round</button>
                    <?php endif ?>
                </form>

                <form method="post" style="display:inline;margin-left:auto">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="_action" value="delete">
                    <input type="hidden" name="round_id" value="<?= $round['id'] ?>">
                    <button class="uk-button uk-button-link uk-button-small"
                            style="color:#e74c3c"
                            data-confirm="<?= htmlspecialchars(__('confirm_delete')) ?>">
                        <?= __('delete') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach ?>
</div>
<?php endif ?>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
