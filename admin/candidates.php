<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Models\Candidate;
use Electus\Models\Category;
use Electus\Models\Event;
use Electus\Models\Round;

$roundId = (int) ($_GET['round_id'] ?? 0);
$round   = $roundId ? Round::find($roundId) : null;
if (!$round) { Flash::error('Round not found.'); header('Location: /admin/events.php'); exit; }

$eventId = (int) $round['event_id'];
$event   = Event::find($eventId);
Auth::requireEventPermission($eventId);

$categories = Category::forEvent($eventId);

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        $catId = (int) ($_POST['category_id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        if ($catId && $name) {
            Candidate::create($roundId, $catId, $name, 'manual');
            Flash::success(__('candidate_saved'));
        }
    }

    if ($action === 'update') {
        $candId = (int) ($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        if ($candId && $name) {
            Candidate::update($candId, $name);
            Flash::success(__('candidate_saved'));
        }
    }

    if ($action === 'delete') {
        $candId = (int) ($_POST['id'] ?? 0);
        if ($candId) {
            Candidate::delete($candId);
            Flash::success(__('candidate_deleted'));
        }
    }

    if ($action === 'exclude') {
        $candId = (int) ($_POST['id'] ?? 0);
        if ($candId) {
            Candidate::setStatus($candId, 'excluded');
            Flash::success('Candidate excluded.');
        }
    }

    if ($action === 'restore') {
        $candId = (int) ($_POST['id'] ?? 0);
        if ($candId) {
            Candidate::setStatus($candId, 'active');
            Flash::success('Candidate restored.');
        }
    }

    header('Location: /admin/candidates.php?round_id=' . $roundId);
    exit;
}

$candidates     = Candidate::forRound($roundId, 'active');
$excluded       = Candidate::forRound($roundId, 'excluded');
$isOpen         = $round['model'] === 'open';

$pageTitle      = __('candidates_title');
$activeMenu     = 'rounds';
$currentEventId = $eventId;

// Group candidates by category
$byCategory = [];
foreach ($candidates as $c) {
    $byCategory[$c['category_id']][] = $c;
}

ob_start();
?>
<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom">
    <div class="uk-flex uk-flex-middle" style="gap:12px">
        <a href="/admin/rounds.php?event_id=<?= $eventId ?>" uk-icon="arrow-left" class="uk-icon-link"></a>
        <div>
            <p style="margin:0;font-size:.8rem;color:#9a94b8">
                <?= htmlspecialchars($event['name']) ?> —
                <?= __('round_number') ?><?= $round['round_number'] ?>
                <?= $round['label'] ? '— ' . htmlspecialchars($round['label']) : '' ?>
                <span class="e-badge e-badge-<?= $round['status'] ?>" style="margin-left:6px">
                    <?= __('model_' . $round['model']) ?>
                </span>
            </p>
            <h1 class="e-page-title uk-margin-remove"><?= __('candidates_title') ?></h1>
        </div>
    </div>
    <?php if ($round['model'] === 'open'): ?>
    <a href="/admin/dedup.php?round_id=<?= $roundId ?>" class="uk-button uk-button-default uk-button-small">
        <?= __('dedup_title') ?>
    </a>
    <?php endif ?>
</div>

<?php if (empty($categories)): ?>
<div class="uk-alert-warning" uk-alert>
    <p>No categories defined for this event. <a href="/admin/categories.php?event_id=<?= $eventId ?>">Add categories first</a>.</p>
</div>
<?php else: ?>

<div class="uk-grid-medium" uk-grid>

    <!-- Candidates by category -->
    <div class="uk-width-<?= $isOpen ? '1-1' : '2-3' ?>@m">

        <?php if ($isOpen): ?>
        <div class="uk-alert-primary" uk-alert>
            <p>This is an <strong>open round</strong>. Candidates emerge from voter input. Use the
               <a href="/admin/dedup.php?round_id=<?= $roundId ?>">Deduplication queue</a> to review them.</p>
        </div>
        <?php endif ?>

        <?php if (empty($candidates) && !$isOpen): ?>
        <div class="e-card uk-text-center" style="padding:40px">
            <p style="color:#9a94b8">No candidates yet. Add some using the form.</p>
        </div>
        <?php else: ?>

        <?php foreach ($categories as $cat): ?>
        <?php $catCandidates = $byCategory[$cat['id']] ?? []; ?>
        <div class="e-card uk-margin-small-bottom">
            <div class="uk-flex uk-flex-between uk-flex-middle uk-margin-small-bottom">
                <h3 style="margin:0;font-size:.9rem;font-weight:700;color:var(--e-accent)">
                    <?= htmlspecialchars($cat['name']) ?>
                </h3>
                <span style="color:#9a94b8;font-size:.8rem"><?= count($catCandidates) ?> candidates</span>
            </div>

            <?php if (empty($catCandidates)): ?>
            <p style="color:#c8c3e0;font-size:.875rem;margin:0">—</p>
            <?php else: ?>
            <table class="uk-table uk-table-small uk-table-divider uk-margin-remove">
                <tbody>
                    <?php foreach ($catCandidates as $cand): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($cand['name']) ?>
                            <?php if ($cand['source'] !== 'manual'): ?>
                            <span class="e-badge" style="background:#f0eeff;color:#6b52d4;font-size:.7rem">
                                <?= $cand['source'] ?>
                            </span>
                            <?php endif ?>
                        </td>
                        <td style="width:90px">
                            <div class="uk-flex" style="gap:6px">
                                <?php if (!$isOpen): ?>
                                <a href="#modal-cand-edit-<?= $cand['id'] ?>" uk-toggle
                                   uk-icon="icon:pencil;ratio:.8" class="uk-icon-link"></a>
                                <?php endif ?>
                                <form method="post" style="display:inline">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="_action" value="exclude">
                                    <input type="hidden" name="id" value="<?= $cand['id'] ?>">
                                    <button uk-icon="icon:ban;ratio:.8"
                                            uk-tooltip="Exclude"
                                            data-confirm="Exclude this candidate?"
                                            style="color:#e74c3c;background:none;border:none;cursor:pointer;padding:0"
                                            class="uk-icon-link"></button>
                                </form>
                                <?php if (!$isOpen): ?>
                                <form method="post" style="display:inline">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?= $cand['id'] ?>">
                                    <button uk-icon="icon:trash;ratio:.8"
                                            data-confirm="<?= htmlspecialchars(__('confirm_delete')) ?>"
                                            style="color:#e74c3c;background:none;border:none;cursor:pointer;padding:0"
                                            class="uk-icon-link"></button>
                                </form>
                                <?php endif ?>
                            </div>
                        </td>
                    </tr>

                    <?php if (!$isOpen): ?>
                    <!-- Edit modal -->
                    <div id="modal-cand-edit-<?= $cand['id'] ?>" uk-modal>
                        <div class="uk-modal-dialog uk-modal-body">
                            <h3 class="uk-modal-title"><?= __('edit') ?></h3>
                            <form method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="_action" value="update">
                                <input type="hidden" name="id" value="<?= $cand['id'] ?>">
                                <input class="uk-input" type="text" name="name"
                                       value="<?= htmlspecialchars($cand['name']) ?>" required>
                                <div class="uk-flex uk-margin-top" style="gap:8px">
                                    <button class="uk-button uk-button-primary"><?= __('save') ?></button>
                                    <button class="uk-button uk-button-default uk-modal-close"><?= __('cancel') ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif ?>

                    <?php endforeach ?>
                </tbody>
            </table>
            <?php endif ?>
        </div>
        <?php endforeach ?>

        <?php endif ?>
    </div>

    <!-- Add form (closed rounds only) -->
    <?php if (!$isOpen): ?>
    <div class="uk-width-1-3@m">
        <div class="e-card">
            <h3 style="font-size:1rem;font-weight:600;margin-bottom:16px"><?= __('candidate_new') ?></h3>
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="_action" value="create">
                <div class="uk-margin">
                    <label class="uk-form-label"><?= __('categories_title') ?></label>
                    <select class="uk-select" name="category_id" required>
                        <option value="">— Select —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="uk-margin">
                    <label class="uk-form-label"><?= __('candidate_name') ?></label>
                    <input class="uk-input" type="text" name="name" required>
                </div>
                <button class="uk-button uk-button-primary uk-width-1-1"><?= __('create') ?></button>
            </form>
        </div>
    </div>
    <?php endif ?>

</div>

<!-- Excluded candidates -->
<?php if (!empty($excluded)): ?>
<details class="uk-margin-top" style="cursor:pointer">
    <summary style="color:#9a94b8;font-size:.85rem">
        <?= count($excluded) ?> excluded candidate(s)
    </summary>
    <div class="e-table uk-margin-small-top">
        <table class="uk-table uk-table-small uk-table-divider uk-margin-remove">
            <tbody>
                <?php foreach ($excluded as $cand): ?>
                <tr>
                    <td style="color:#9a94b8"><?= htmlspecialchars($cand['name']) ?></td>
                    <td style="color:#9a94b8"><?= htmlspecialchars($cand['category_name']) ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="_action" value="restore">
                            <input type="hidden" name="id" value="<?= $cand['id'] ?>">
                            <button class="uk-button uk-button-link uk-button-small">Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</details>
<?php endif ?>

<?php endif ?>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
