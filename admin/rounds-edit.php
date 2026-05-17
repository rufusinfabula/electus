<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Models\Event;
use Electus\Models\Round;

$eventId = (int) ($_GET['event_id'] ?? 0);
$roundId = (int) ($_GET['id'] ?? 0);

$round = $roundId ? Round::find($roundId) : null;
if ($round) $eventId = (int) $round['event_id'];

$event = Event::find($eventId);
if (!$event) { Flash::error('Event not found.'); header('Location: /admin/events.php'); exit; }

Auth::requireEventPermission($eventId);

$allRounds = Round::forEvent($eventId);
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();

    $models   = ['open','single','multiple','borda','proportional','weighted'];
    $statuses = ['draft','active','closed'];

    $data = [
        'round_number'     => (int) ($_POST['round_number'] ?? 1),
        'label'            => trim($_POST['label'] ?? ''),
        'model'            => $_POST['model'] ?? 'single',
        'status'           => $_POST['status'] ?? 'draft',
        'opens_at'         => trim($_POST['opens_at'] ?? ''),
        'closes_at'        => trim($_POST['closes_at'] ?? ''),
        'parent_round_id'  => (int) ($_POST['parent_round_id'] ?? 0) ?: null,
        'top_n_to_promote' => (int) ($_POST['top_n_to_promote'] ?? 0) ?: null,
        'config'           => [],
    ];

    if (!in_array($data['model'], $models, true))   $errors[] = 'Invalid voting model.';
    if (!in_array($data['status'], $statuses, true)) $errors[] = 'Invalid status.';

    // Model-specific config
    switch ($data['model']) {
        case 'multiple':
            $data['config']['max_choices'] = (int) ($_POST['config_max_choices'] ?? 3);
            break;
        case 'borda':
            $data['config']['borda_mode']  = in_array($_POST['config_borda_mode'] ?? '', ['fixed','free'], true)
                ? $_POST['config_borda_mode'] : 'fixed';
            $data['config']['borda_scale'] = trim($_POST['config_borda_scale'] ?? '');
            if ($data['config']['borda_mode'] === 'free') {
                $data['config']['borda_budget'] = (int) ($_POST['config_borda_budget'] ?? 100);
            }
            $data['config']['max_candidates'] = (int) ($_POST['config_max_candidates'] ?? 0) ?: null;
            break;
        case 'proportional':
            $data['config']['seats'] = (int) ($_POST['config_seats'] ?? 10);
            break;
        case 'weighted':
            // weights are per voter, no round-level config needed
            break;
    }

    if (!$errors) {
        if ($round) {
            Round::update($roundId, $data);
            Flash::success(__('round_saved'));
            header('Location: /admin/rounds-edit.php?id=' . $roundId . '&event_id=' . $eventId);
        } else {
            $newId = Round::create($eventId, $data);
            Flash::success(__('round_saved'));
            header('Location: /admin/rounds-edit.php?id=' . $newId . '&event_id=' . $eventId);
        }
        exit;
    }
    // Re-populate
    $round = array_merge($round ?? [], $data);
}

$cfg            = $round['config'] ?? [];
$pageTitle      = $round ? __('round_edit') : __('round_new');
$activeMenu     = 'rounds';
$currentEventId = $eventId;

ob_start();
?>
<div class="uk-flex uk-flex-middle" style="gap:12px;margin-bottom:24px">
    <a href="/admin/rounds.php?event_id=<?= $eventId ?>" uk-icon="arrow-left" class="uk-icon-link"></a>
    <div>
        <p style="margin:0;font-size:.8rem;color:#9a94b8"><?= htmlspecialchars($event['name']) ?></p>
        <h1 class="e-page-title uk-margin-remove"><?= htmlspecialchars($pageTitle) ?></h1>
    </div>
</div>

<?php if ($errors): ?>
<div class="uk-alert-danger" uk-alert>
    <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach ?>
</div>
<?php endif ?>

<div class="e-card">
<form method="post">
    <?= Csrf::field() ?>

    <div class="uk-grid-medium" uk-grid>

        <!-- Round number -->
        <div class="uk-width-1-4@m">
            <label class="uk-form-label"><?= __('round_number') ?></label>
            <input class="uk-input" type="number" name="round_number" min="1"
                   value="<?= (int) ($round['round_number'] ?? count($allRounds) + 1) ?>">
        </div>

        <!-- Label -->
        <div class="uk-width-1-2@m">
            <label class="uk-form-label"><?= __('round_label') ?> <span style="color:#9a94b8">(optional)</span></label>
            <input class="uk-input" type="text" name="label"
                   value="<?= htmlspecialchars($round['label'] ?? '') ?>"
                   placeholder="e.g. Phase 1 — Open nominations">
        </div>

        <!-- Status -->
        <div class="uk-width-1-4@m">
            <label class="uk-form-label"><?= __('status') ?></label>
            <select class="uk-select" name="status">
                <?php foreach (['draft','active','closed'] as $s): ?>
                <option value="<?= $s ?>" <?= ($round['status'] ?? 'draft') === $s ? 'selected' : '' ?>>
                    <?= __('event_status_' . $s) ?>
                </option>
                <?php endforeach ?>
            </select>
        </div>

        <!-- Model -->
        <div class="uk-width-1-3@m">
            <label class="uk-form-label"><?= __('round_model') ?> *</label>
            <select class="uk-select" name="model" id="model-select">
                <?php foreach (['open','single','multiple','borda','proportional','weighted'] as $m): ?>
                <option value="<?= $m ?>" <?= ($round['model'] ?? 'single') === $m ? 'selected' : '' ?>>
                    <?= __('model_' . $m) ?>
                </option>
                <?php endforeach ?>
            </select>
        </div>

        <!-- Opens / Closes -->
        <div class="uk-width-1-3@m">
            <label class="uk-form-label"><?= __('round_opens_at') ?> <span style="color:#9a94b8">(optional)</span></label>
            <input class="uk-input" type="datetime-local" name="opens_at"
                   value="<?= htmlspecialchars(str_replace(' ', 'T', $round['opens_at'] ?? '')) ?>">
        </div>
        <div class="uk-width-1-3@m">
            <label class="uk-form-label"><?= __('round_closes_at') ?> <span style="color:#9a94b8">(optional)</span></label>
            <input class="uk-input" type="datetime-local" name="closes_at"
                   value="<?= htmlspecialchars(str_replace(' ', 'T', $round['closes_at'] ?? '')) ?>">
        </div>

        <!-- Parent round -->
        <div class="uk-width-1-2@m">
            <label class="uk-form-label">Follows round <span style="color:#9a94b8">(for multi-round events)</span></label>
            <select class="uk-select" name="parent_round_id">
                <option value="">— None —</option>
                <?php foreach ($allRounds as $r):
                    if ($r['id'] === ($round['id'] ?? 0)) continue; ?>
                <option value="<?= $r['id'] ?>" <?= ($round['parent_round_id'] ?? null) == $r['id'] ? 'selected' : '' ?>>
                    #<?= $r['round_number'] ?> <?= htmlspecialchars($r['label'] ?? '') ?> (<?= $r['model'] ?>)
                </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="uk-width-1-2@m">
            <label class="uk-form-label">Promote top N candidates per category</label>
            <input class="uk-input" type="number" name="top_n_to_promote" min="1"
                   placeholder="e.g. 3"
                   value="<?= $round['top_n_to_promote'] ?? '' ?>">
        </div>

    </div>

    <!-- ── Model-specific config ──────────────────────────────────────────── -->

    <div id="config-multiple" class="uk-margin-top" style="display:none">
        <hr><h4 style="font-size:.9rem;font-weight:600;color:var(--e-primary)">Multiple choice settings</h4>
        <div class="uk-width-1-4@m">
            <label class="uk-form-label">Max choices per category</label>
            <input class="uk-input" type="number" name="config_max_choices" min="2"
                   value="<?= $cfg['max_choices'] ?? 3 ?>">
        </div>
    </div>

    <div id="config-borda" class="uk-margin-top" style="display:none">
        <hr><h4 style="font-size:.9rem;font-weight:600;color:var(--e-primary)">Borda settings</h4>
        <div class="uk-grid-medium" uk-grid>
            <div class="uk-width-1-3@m">
                <label class="uk-form-label">Mode</label>
                <select class="uk-select" name="config_borda_mode" id="borda-mode">
                    <option value="fixed" <?= ($cfg['borda_mode'] ?? 'fixed') === 'fixed' ? 'selected' : '' ?>>Fixed scale</option>
                    <option value="free"  <?= ($cfg['borda_mode'] ?? 'fixed') === 'free'  ? 'selected' : '' ?>>Free budget</option>
                </select>
            </div>
            <div class="uk-width-2-3@m" id="borda-scale-row">
                <label class="uk-form-label">Point scale <span style="color:#9a94b8">(comma-separated, e.g. 12,10,8,7,6)</span></label>
                <input class="uk-input" type="text" name="config_borda_scale"
                       value="<?= htmlspecialchars($cfg['borda_scale'] ?? '12,10,8,7,6,5,4,3,2,1') ?>"
                       placeholder="12,10,8,7,6,5,4,3,2,1">
            </div>
            <div class="uk-width-1-3@m" id="borda-budget-row" style="display:none">
                <label class="uk-form-label">Total point budget per voter</label>
                <input class="uk-input" type="number" name="config_borda_budget" min="1"
                       value="<?= $cfg['borda_budget'] ?? 100 ?>">
            </div>
            <div class="uk-width-1-3@m">
                <label class="uk-form-label">Max candidates to rank <span style="color:#9a94b8">(0 = all)</span></label>
                <input class="uk-input" type="number" name="config_max_candidates" min="0"
                       value="<?= $cfg['max_candidates'] ?? 0 ?>">
            </div>
        </div>
    </div>

    <div id="config-proportional" class="uk-margin-top" style="display:none">
        <hr><h4 style="font-size:.9rem;font-weight:600;color:var(--e-primary)">Proportional settings</h4>
        <div class="uk-width-1-4@m">
            <label class="uk-form-label">Total seats to distribute</label>
            <input class="uk-input" type="number" name="config_seats" min="1"
                   value="<?= $cfg['seats'] ?? 10 ?>">
        </div>
    </div>

    <div class="uk-margin-top uk-flex" style="gap:12px">
        <button type="submit" class="uk-button uk-button-primary"><?= __('save') ?></button>
        <a href="/admin/rounds.php?event_id=<?= $eventId ?>" class="uk-button uk-button-default"><?= __('cancel') ?></a>
        <?php if ($round): ?>
        <a href="/admin/candidates.php?round_id=<?= $roundId ?>" class="uk-button uk-button-default" style="margin-left:auto">
            <?= __('candidates_title') ?> &rarr;
        </a>
        <?php endif ?>
    </div>
</form>
</div>

<script>
(function () {
    var modelSelect = document.getElementById('model-select');
    var configSections = { multiple: 'config-multiple', borda: 'config-borda', proportional: 'config-proportional' };

    function toggleConfig() {
        var model = modelSelect.value;
        Object.values(configSections).forEach(function (id) {
            document.getElementById(id).style.display = 'none';
        });
        if (configSections[model]) {
            document.getElementById(configSections[model]).style.display = '';
        }
    }

    modelSelect.addEventListener('change', toggleConfig);
    toggleConfig();

    // Borda mode switch
    var bordaMode = document.getElementById('borda-mode');
    function toggleBordaMode() {
        var isFree = bordaMode.value === 'free';
        document.getElementById('borda-scale-row').style.display  = isFree ? 'none' : '';
        document.getElementById('borda-budget-row').style.display = isFree ? '' : 'none';
    }
    if (bordaMode) {
        bordaMode.addEventListener('change', toggleBordaMode);
        toggleBordaMode();
    }
})();
</script>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
