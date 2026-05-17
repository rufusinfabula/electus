<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Models\Category;
use Electus\Models\Event;
use Electus\Models\Results;
use Electus\Models\Round;

$roundId = (int) ($_GET['round_id'] ?? 0);
$round   = $roundId ? Round::find($roundId) : null;
if (!$round) {
    Flash::error('Round not found.');
    header('Location: /admin/events.php');
    exit;
}

$eventId = (int) $round['event_id'];
$event   = Event::find($eventId);
Auth::requireEventPermission($eventId);

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $action = $_POST['_action'] ?? '';
    $user   = Auth::currentUser();

    match ($action) {
        'compute' => (function () use ($round, $roundId) {
            try {
                Results::compute($roundId);
                Flash::success(__('results_computed'));
            } catch (\Throwable $e) {
                Flash::error('Compute failed: ' . $e->getMessage());
            }
        })(),

        'validate' => (function () use ($round, $roundId, $user) {
            Results::validate($roundId, $user['id']);
            Flash::success(__('votes_validated'));
        })(),

        'unvalidate' => (function () use ($roundId) {
            Results::unvalidate($roundId);
            Flash::success(__('votes_unvalidated'));
        })(),

        'publish' => (function () use ($round, $roundId) {
            if (!$round['votes_validated']) {
                Flash::error(__('results_not_validated'));
            } else {
                Results::publish($roundId);
                Flash::success(__('results_published'));
            }
        })(),

        'unpublish' => (function () use ($roundId) {
            Results::unpublish($roundId);
            Flash::success(__('results_unpublished'));
        })(),

        'promote' => (function () use ($round, $roundId) {
            $toRoundId = (int) ($_POST['to_round_id'] ?? 0);
            $topN      = (int) ($round['top_n_to_promote'] ?? 0);
            if (!$toRoundId || !$topN) {
                Flash::error('Specify a target round and top-N value.');
            } elseif (!$round['votes_validated']) {
                Flash::error(__('results_not_validated'));
            } else {
                try {
                    Results::promote($roundId, $toRoundId, $topN);
                    Flash::success('Top ' . $topN . ' candidates promoted to round #' . $toRoundId . '.');
                } catch (\Throwable $e) {
                    Flash::error('Promotion failed: ' . $e->getMessage());
                }
            }
        })(),

        default => null,
    };

    // Re-fetch round after any state change
    header('Location: /admin/results.php?round_id=' . $roundId);
    exit;
}

// ── Export handlers ───────────────────────────────────────────────────────────
$export = $_GET['export'] ?? '';
if ($export === 'csv') {
    $rows = Results::toCsvRows($roundId);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="results_round_' . $roundId . '.csv"');
    $out = fopen('php://output', 'w');
    if ($rows) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    }
    fclose($out);
    exit;
}
if ($export === 'json') {
    $rows = Results::toCsvRows($roundId);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="results_round_' . $roundId . '.json"');
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Data ──────────────────────────────────────────────────────────────────────
$round = Round::find($roundId); // re-fetch after possible POST
$resultsByCategory = Results::forRound($roundId);
$voterCount        = Results::voterCount($roundId);
$voteRowCount      = Results::voteRowCount($roundId);
$validatorInfo     = Results::validatorInfo($roundId);
$categories        = Category::forEvent($eventId);

// Sibling rounds for promotion target
$allRounds = Round::forEvent($eventId);
$laterRounds = array_filter($allRounds, fn($r) => $r['round_number'] > $round['round_number']);

$pageTitle      = __('results_title');
$activeMenu     = 'rounds';
$currentEventId = $eventId;

ob_start();
?>
<!-- Header -->
<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom">
    <div class="uk-flex uk-flex-middle" style="gap:12px">
        <a href="/admin/rounds.php?event_id=<?= $eventId ?>" uk-icon="arrow-left" class="uk-icon-link"></a>
        <div>
            <p style="margin:0;font-size:.8rem;color:#9a94b8">
                <?= htmlspecialchars($event['name']) ?> —
                <?= __('round_number') ?><?= $round['round_number'] ?>
                <?= $round['label'] ? '— ' . htmlspecialchars($round['label']) : '' ?>
            </p>
            <h1 class="e-page-title uk-margin-remove"><?= __('results_title') ?></h1>
        </div>
    </div>
    <!-- Export buttons -->
    <?php if (!empty($resultsByCategory)): ?>
    <div style="display:flex;gap:8px">
        <a href="?round_id=<?= $roundId ?>&export=csv"  class="uk-button uk-button-default uk-button-small">
            <span uk-icon="icon:download;ratio:.8"></span> CSV
        </a>
        <a href="?round_id=<?= $roundId ?>&export=json" class="uk-button uk-button-default uk-button-small">
            <span uk-icon="icon:download;ratio:.8"></span> JSON
        </a>
    </div>
    <?php endif ?>
</div>

<!-- Status bar -->
<div class="uk-grid-small uk-margin-bottom" uk-grid>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:140px">
            <div class="e-stat-value"><?= $voterCount ?></div>
            <div class="e-stat-label">Voters</div>
        </div>
    </div>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:140px">
            <div class="e-stat-value"><?= $voteRowCount ?></div>
            <div class="e-stat-label">Vote entries</div>
        </div>
    </div>
    <div class="uk-width-expand">
        <div class="e-card" style="height:100%;display:flex;align-items:center;gap:20px;flex-wrap:wrap">

            <!-- Validation status -->
            <?php if ($round['votes_validated']): ?>
            <div>
                <span class="e-badge e-badge-active">&#10003; <?= __('votes_validated') ?></span>
                <?php if ($validatorInfo && $validatorInfo['name']): ?>
                <p style="margin:4px 0 0;font-size:.75rem;color:#9a94b8">
                    <?= __('validated_by') ?>: <strong><?= htmlspecialchars($validatorInfo['name']) ?></strong>
                    — <?= htmlspecialchars(substr($validatorInfo['validated_at'] ?? '', 0, 16)) ?>
                </p>
                <?php endif ?>
            </div>
            <?php else: ?>
            <span class="e-badge e-badge-draft">&#9679; Votes not yet validated</span>
            <?php endif ?>

            <!-- Results released status -->
            <?php if ($round['results_released']): ?>
            <span class="e-badge e-badge-active">&#127758; Results public</span>
            <?php else: ?>
            <span class="e-badge e-badge-closed">Results hidden</span>
            <?php endif ?>

        </div>
    </div>
</div>

<!-- Action toolbar -->
<div class="e-card uk-margin-bottom" style="padding:16px 24px">
    <div class="uk-flex uk-flex-wrap" style="gap:10px;align-items:center">

        <!-- Compute -->
        <form method="post" style="margin:0">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="compute">
            <button class="uk-button uk-button-primary uk-button-small">
                <span uk-icon="icon:refresh;ratio:.8"></span> <?= __('results_compute') ?>
            </button>
        </form>

        <!-- Validate / Unvalidate -->
        <?php if (!$round['votes_validated']): ?>
        <form method="post" style="margin:0">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="validate">
            <button class="uk-button uk-button-default uk-button-small"
                    data-confirm="<?= htmlspecialchars(__('validate_votes_confirm')) ?>"
                    style="color:#27ae60;border-color:#27ae60">
                <span uk-icon="icon:check;ratio:.8"></span> <?= __('validate_votes') ?>
            </button>
        </form>
        <?php else: ?>
        <form method="post" style="margin:0">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="unvalidate">
            <button class="uk-button uk-button-link uk-button-small" style="color:#e74c3c"
                    data-confirm="Remove validation? This will also unpublish results.">
                <?= __('votes_unvalidate') ?>
            </button>
        </form>
        <?php endif ?>

        <!-- Publish / Unpublish -->
        <?php if (!$round['results_released']): ?>
        <form method="post" style="margin:0">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="publish">
            <button class="uk-button uk-button-default uk-button-small"
                    <?= !$round['votes_validated'] ? 'disabled title="' . htmlspecialchars(__('results_not_validated')) . '"' : '' ?>
                    style="<?= $round['votes_validated'] ? 'color:var(--e-primary);border-color:var(--e-primary)' : '' ?>">
                <span uk-icon="icon:world;ratio:.8"></span> <?= __('results_publish') ?>
            </button>
        </form>
        <?php else: ?>
        <form method="post" style="margin:0">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="unpublish">
            <button class="uk-button uk-button-link uk-button-small" style="color:#e74c3c"
                    data-confirm="Hide results from the public page?">
                <?= __('results_unpublish') ?>
            </button>
        </form>
        <?php endif ?>

        <!-- Promote to next round -->
        <?php if ($round['top_n_to_promote'] && !$round['promotion_confirmed'] && !empty($laterRounds)): ?>
        <form method="post" style="margin:0;display:flex;gap:6px;align-items:center">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="promote">
            <select name="to_round_id" class="uk-select uk-form-small" style="width:auto">
                <?php foreach ($laterRounds as $lr): ?>
                <option value="<?= $lr['id'] ?>">
                    Round #<?= $lr['round_number'] ?> <?= htmlspecialchars($lr['label'] ?? '') ?>
                </option>
                <?php endforeach ?>
            </select>
            <button class="uk-button uk-button-default uk-button-small"
                    <?= !$round['votes_validated'] ? 'disabled title="' . htmlspecialchars(__('results_not_validated')) . '"' : '' ?>
                    data-confirm="Promote top <?= (int)$round['top_n_to_promote'] ?> to the selected round?">
                <span uk-icon="icon:push;ratio:.8"></span>
                Promote top <?= (int)$round['top_n_to_promote'] ?>
            </button>
        </form>
        <?php elseif ($round['promotion_confirmed']): ?>
        <span style="font-size:.8rem;color:#27ae60">&#10003; Candidates promoted</span>
        <?php endif ?>

    </div>
</div>

<!-- Results tables + charts -->
<?php if (empty($resultsByCategory)): ?>
<div class="e-card uk-text-center" style="padding:60px">
    <span uk-icon="icon:info;ratio:2" style="color:#9a94b8"></span>
    <p style="color:#9a94b8;margin-top:12px"><?= __('results_not_computed') ?></p>
</div>
<?php else: ?>

<?php foreach ($resultsByCategory as $catId => $rows):
    $catName = $rows[0]['category_name'];
    $chartId = 'chart-cat-' . $catId;
    $labels  = array_map(fn($r) => $r['candidate_name'], $rows);
    $values  = array_map(fn($r) => (float)$r['total_points'], $rows);
    $winner  = $rows[0]['candidate_name'];
?>
<div class="uk-margin-medium-bottom">
    <h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--e-accent);margin-bottom:12px">
        <?= htmlspecialchars($catName) ?>
    </h3>

    <div class="uk-grid-medium" uk-grid>
        <!-- Table -->
        <div class="uk-width-1-2@l">
            <div class="e-table">
                <table class="uk-table uk-table-hover uk-table-divider uk-table-small uk-margin-remove">
                    <thead>
                        <tr>
                            <th style="width:40px"><?= __('rank') ?></th>
                            <th><?= __('candidate_name') ?></th>
                            <th style="text-align:right"><?= __('total_votes') ?></th>
                            <th style="text-align:right"><?= __('total_points') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr <?= $r['rank'] == 1 ? 'style="background:#f0fdf4;font-weight:700"' : '' ?>>
                        <td>
                            <?php if ($r['rank'] == 1): ?>
                            <span style="color:#27ae60;font-size:1.1rem">&#9733;</span>
                            <?php else: ?>
                            <span style="color:#9a94b8"><?= $r['rank'] ?></span>
                            <?php endif ?>
                        </td>
                        <td><?= htmlspecialchars($r['candidate_name']) ?></td>
                        <td style="text-align:right;color:#6b6494"><?= $r['total_votes'] ?></td>
                        <td style="text-align:right;color:var(--e-primary);font-weight:600"><?= $r['total_points'] ?></td>
                    </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Chart -->
        <div class="uk-width-1-2@l">
            <div class="e-card" style="height:100%;min-height:200px">
                <canvas id="<?= $chartId ?>" style="max-height:320px"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var ctx = document.getElementById('<?= $chartId ?>');
    if (!ctx) return;
    var labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
    var values = <?= json_encode($values) ?>;
    var bgColors = labels.map(function(l){
        return l === <?= json_encode($winner, JSON_UNESCAPED_UNICODE) ?> ? '#27ae60' : '#7B68EE';
    });
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: '<?= addslashes(__('total_points')) ?>',
                data: values,
                backgroundColor: bgColors,
                borderRadius: 6
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, grid: { color: '#f0eeff' } },
                y: { grid: { display: false }, ticks: { font: { size: 11 } } }
            }
        }
    });
})();
</script>

<?php endforeach ?>
<?php endif ?>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
