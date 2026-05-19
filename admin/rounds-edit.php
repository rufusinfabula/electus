<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\CatTerm;
use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Models\Category;
use Electus\Models\Event;
use Electus\Models\Round;

$eventId = (int) ($_GET['event_id'] ?? 0);
$roundId = (int) ($_GET['id'] ?? 0);

$round = $roundId ? Round::find($roundId) : null;
if ($round) $eventId = (int) $round['event_id'];

$event = Event::find($eventId);
if (!$event) { Flash::error('Event not found.'); header('Location: /admin/events.php'); exit; }

Auth::requireEventPermission($eventId);

$allRounds    = Round::forEvent($eventId);
$allCategories = Category::forEvent($eventId);
$errors       = [];

// Load existing category map for this round (keyed by category_id)
$existingMap = [];
if ($roundId) {
    foreach (Round::categoriesFor($roundId) as $row) {
        $existingMap[$row['id']] = $row;
    }
    // If map is empty (pre-migration round), default all categories as active
    if (empty($existingMap)) {
        foreach ($allCategories as $cat) {
            $existingMap[$cat['id']] = ['advancement_mode' => 'manual', 'advancement_count' => null, 'next_category_id' => null];
        }
    }
}

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

    // Category map from POST
    $activeCats  = array_map('intval', $_POST['active_categories'] ?? []);
    $catSettings = [];
    foreach ($activeCats as $cid) {
        $catSettings[$cid] = [
            'mode'    => in_array($_POST['cat_mode'][$cid] ?? '', ['auto','manual','all','none'], true)
                             ? $_POST['cat_mode'][$cid] : 'manual',
            'count'   => $_POST['cat_count'][$cid] ?? '',
            'next_cat'=> (int) ($_POST['cat_next'][$cid] ?? 0) ?: null,
        ];
    }

    if (!$errors) {
        if ($round) {
            Round::update($roundId, $data);
            Round::saveCategoryMap($roundId, $catSettings);
            Flash::success(__('round_saved'));
            header('Location: /admin/rounds-edit.php?id=' . $roundId . '&event_id=' . $eventId);
        } else {
            $newId = Round::create($eventId, $data);
            Round::saveCategoryMap($newId, $catSettings);
            Flash::success(__('round_saved'));
            header('Location: /admin/rounds-edit.php?id=' . $newId . '&event_id=' . $eventId);
        }
        exit;
    }
    // Re-populate after validation error
    $round = array_merge($round ?? [], $data);
    // Re-populate existingMap from POST so UI stays consistent
    $existingMap = [];
    foreach ($activeCats as $cid) {
        $existingMap[$cid] = $catSettings[$cid] + ['id' => $cid, 'advancement_mode' => $catSettings[$cid]['mode'], 'advancement_count' => $catSettings[$cid]['count']];
    }
}

$cfg            = $round['config'] ?? [];
$catTermS       = CatTerm::label($event, 's');
$catTermP       = CatTerm::label($event, 'p');
$pageTitle      = $round ? __('round_edit') : __('round_new');
$activeMenu     = 'rounds';
$currentEventId = $eventId;
$currentRoundId = $roundId ?: null;

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
            <label class="uk-form-label">Turno precedente <span style="color:#9a94b8">(opzionale)</span></label>
            <select class="uk-select" name="parent_round_id">
                <option value="">— Nessuno (primo turno) —</option>
                <?php foreach ($allRounds as $r):
                    if ($r['id'] === ($round['id'] ?? 0)) continue; ?>
                <option value="<?= $r['id'] ?>" <?= ($round['parent_round_id'] ?? null) == $r['id'] ? 'selected' : '' ?>>
                    #<?= $r['round_number'] ?> <?= htmlspecialchars($r['label'] ?? '') ?>
                </option>
                <?php endforeach ?>
            </select>
        </div>

        <div class="uk-width-1-2@m"></div>

    </div>

    <!-- ── Categorie attive ───────────────────────────────────────────────── -->
    <?php if (!empty($allCategories)): ?>
    <hr style="margin:24px 0">
    <h4 style="font-size:.9rem;font-weight:700;color:var(--e-primary);margin-bottom:4px">
        <?= $catTermP ?> attive in questo turno
    </h4>
    <p style="font-size:.8rem;color:#9a94b8;margin-bottom:16px">
        Seleziona quali <?= $catTermP ?> sono in gioco. Per ogni <?= $catTermS ?> puoi definire quanti candidati avanzano al turno successivo.
    </p>

    <?php
    // Compute next-round categories for the "maps to" dropdown
    $nextRound = null;
    foreach ($allRounds as $r) {
        if ((int)($r['parent_round_id'] ?? 0) === (int)($round['id'] ?? 0)) {
            $nextRound = $r;
            break;
        }
    }
    $nextRoundCats = $nextRound ? Category::forRound((int)$nextRound['id']) : [];
    if (empty($nextRoundCats)) $nextRoundCats = $allCategories;
    ?>

    <div style="border:1px solid #ece9f5;border-radius:10px;overflow:hidden">
    <table class="uk-table uk-table-small uk-table-divider uk-margin-remove" style="font-size:.85rem">
        <thead>
            <tr style="background:var(--e-bg)">
                <th style="width:32px"></th>
                <th><?= $catTermS ?></th>
                <th style="width:160px">Avanzamento</th>
                <th style="width:100px">Top-N</th>
                <?php if ($nextRound): ?><th style="width:180px">Mappa a (turno <?= $nextRound['round_number'] ?>)</th><?php endif ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($allCategories as $cat):
            $isActive = isset($existingMap[$cat['id']]);
            $mapRow   = $existingMap[$cat['id']] ?? [];
            $mode     = $mapRow['advancement_mode'] ?? 'manual';
            $count    = $mapRow['advancement_count'] ?? '';
            $nextCat  = $mapRow['next_category_id'] ?? null;
        ?>
        <tr class="e-cat-row <?= $isActive ? '' : 'e-cat-inactive' ?>">
            <td style="text-align:center">
                <input type="checkbox" name="active_categories[]"
                       value="<?= $cat['id'] ?>"
                       class="e-cat-toggle uk-checkbox"
                       <?= $isActive ? 'checked' : '' ?>
                       data-row="cat-<?= $cat['id'] ?>">
            </td>
            <td style="font-weight:600;color:<?= $isActive ? 'var(--e-text)' : '#c8c3e0' ?>">
                <?= htmlspecialchars($cat['name']) ?>
            </td>
            <td>
                <select class="uk-select uk-form-small e-cat-field"
                        name="cat_mode[<?= $cat['id'] ?>]"
                        <?= !$isActive ? 'disabled' : '' ?>>
                    <option value="manual" <?= $mode === 'manual' ? 'selected' : '' ?>>Manuale</option>
                    <option value="auto"   <?= $mode === 'auto'   ? 'selected' : '' ?>>Automatico (top-N)</option>
                    <option value="all"    <?= $mode === 'all'    ? 'selected' : '' ?>>Tutti avanzano</option>
                    <option value="none"   <?= $mode === 'none'   ? 'selected' : '' ?>>Nessuno avanza</option>
                </select>
            </td>
            <td>
                <input class="uk-input uk-form-small e-cat-field"
                       type="number" min="1"
                       name="cat_count[<?= $cat['id'] ?>]"
                       value="<?= htmlspecialchars((string)$count) ?>"
                       placeholder="es. 5"
                       <?= !$isActive ? 'disabled' : '' ?>>
            </td>
            <?php if ($nextRound): ?>
            <td>
                <select class="uk-select uk-form-small e-cat-field"
                        name="cat_next[<?= $cat['id'] ?>]"
                        <?= !$isActive ? 'disabled' : '' ?>>
                    <option value="">— stessa categoria —</option>
                    <?php foreach ($nextRoundCats as $nc): ?>
                    <option value="<?= $nc['id'] ?>" <?= $nextCat == $nc['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($nc['name']) ?>
                    </option>
                    <?php endforeach ?>
                </select>
            </td>
            <?php endif ?>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>

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
    // Voting model config toggle
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
        var isFree = bordaMode && bordaMode.value === 'free';
        if (document.getElementById('borda-scale-row'))
            document.getElementById('borda-scale-row').style.display  = isFree ? 'none' : '';
        if (document.getElementById('borda-budget-row'))
            document.getElementById('borda-budget-row').style.display = isFree ? '' : 'none';
    }
    if (bordaMode) {
        bordaMode.addEventListener('change', toggleBordaMode);
        toggleBordaMode();
    }

    // Category row toggle
    document.querySelectorAll('.e-cat-toggle').forEach(function (chk) {
        chk.addEventListener('change', function () {
            var row = this.closest('tr');
            var fields = row.querySelectorAll('.e-cat-field');
            fields.forEach(function (f) { f.disabled = !chk.checked; });
            row.style.opacity = chk.checked ? '1' : '.4';
        });
        // Set initial opacity
        var row = chk.closest('tr');
        row.style.opacity = chk.checked ? '1' : '.4';
    });
})();
</script>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
