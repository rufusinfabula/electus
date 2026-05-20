<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\CatTerm;
use Electus\Core\Database;
use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Models\Candidate;
use Electus\Models\Deduplication;
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

$tab  = $_GET['tab'] ?? 'results';
$user = Auth::currentUser();

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $action = $_POST['_action'] ?? '';

    // Results actions
    match ($action) {
        'compute' => (function () use ($roundId) {
            try { Results::compute($roundId); Flash::success(__('results_computed')); }
            catch (\Throwable $e) { Flash::error(__('error_prefix') . $e->getMessage()); }
        })(),

        'validate' => (function () use ($roundId, $user) {
            Results::validate($roundId, $user['id']);
            Flash::success(__('votes_validated'));
        })(),

        'unvalidate' => (function () use ($roundId) {
            Results::unvalidate($roundId);
            Flash::success(__('votes_unvalidated'));
        })(),

        'publish' => (function () use ($round, $roundId) {
            if (!$round['votes_validated']) { Flash::error(__('results_not_validated')); }
            else { Results::publish($roundId); Flash::success(__('results_published')); }
        })(),

        'unpublish' => (function () use ($roundId) {
            Results::unpublish($roundId);
            Flash::success(__('results_unpublished'));
        })(),

        'promote' => (function () use ($round, $roundId) {
            $toRoundId = (int) ($_POST['to_round_id'] ?? 0);
            $topN      = (int) ($round['top_n_to_promote'] ?? 0);
            if (!$toRoundId || !$topN) { Flash::error(__('specify_round_count')); }
            elseif (!$round['votes_validated']) { Flash::error(__('results_not_validated')); }
            else {
                try { Results::promote($roundId, $toRoundId, $topN); Flash::success(__('candidates_promoted')); }
                catch (\Throwable $e) { Flash::error(__('error_prefix') . $e->getMessage()); }
            }
        })(),

        // Dedup actions
        'merge_group' => (function () use ($user) {
            $targetId  = (int) ($_POST['target_candidate_id'] ?? 0);
            $canonical = trim($_POST['canonical_override'] ?? '');
            $items = [];
            foreach ($_POST['items'] ?? [] as $v) {
                [$qid, $cid] = explode(':', $v, 2);
                $items[] = ['queue_id' => (int)$qid, 'source_candidate_id' => (int)$cid];
            }
            if ($targetId && !empty($items)) {
                try { Deduplication::mergeGroup($items, $targetId, $user['id'], $canonical); Flash::success(__('candidates_merged_alias')); }
                catch (\Throwable $e) { Flash::error(__('error_prefix') . $e->getMessage()); }
            }
        })(),

        'keep_group' => (function () use ($user) {
            $queueIds = array_map('intval', $_POST['queue_ids'] ?? []);
            if (!empty($queueIds)) { Deduplication::keepGroup($queueIds, $user['id']); Flash::success(__('candidate_kept_separate')); }
        })(),

        'merge' => (function () use ($roundId, $user) {
            $queueId   = (int) ($_POST['queue_id'] ?? 0);
            $sourceId  = (int) ($_POST['source_candidate_id'] ?? 0);
            $targetId  = (int) ($_POST['target_candidate_id'] ?? 0);
            $canonical = trim($_POST['canonical_override'] ?? '');
            if ($queueId && $sourceId && $targetId) {
                try { Deduplication::merge($queueId, $sourceId, $targetId, $user['id'], $canonical); Flash::success(__('candidates_merged_alias')); }
                catch (\Throwable $e) { Flash::error(__('error_prefix') . $e->getMessage()); }
            }
        })(),

        'keep' => (function () use ($user) {
            $queueId = (int) ($_POST['queue_id'] ?? 0);
            if ($queueId) { Deduplication::keep($queueId, $user['id']); Flash::success(__('candidate_kept_separate')); }
        })(),

        'exclude' => (function () use ($user) {
            $queueId     = (int) ($_POST['queue_id'] ?? 0);
            $candidateId = (int) ($_POST['candidate_id'] ?? 0);
            if ($queueId && $candidateId) { Deduplication::exclude($queueId, $candidateId, $user['id']); Flash::success(__('candidate_excluded')); }
        })(),

        'delete_alias' => (function () {
            $aliasId = (int) ($_POST['alias_id'] ?? 0);
            if ($aliasId) { Deduplication::deleteAlias($aliasId); Flash::success(__('alias_deleted')); }
        })(),

        'rescan' => (function () use ($roundId) {
            $n = Deduplication::rescanAll($roundId);
            Flash::success($n > 0 ? str_replace(':n', (string)$n, __('rescan_result')) : __('rescan_none'));
        })(),

        default => null,
    };

    $redirectTab = in_array($action, ['merge_group','keep_group','merge','keep','exclude','delete_alias','rescan'], true) ? 'review' : 'results';
    header('Location: /admin/results.php?round_id=' . $roundId . '&tab=' . $redirectTab);
    exit;
}

// ── Exports ───────────────────────────────────────────────────────────────────
$export = $_GET['export'] ?? '';
if ($export === 'csv') {
    $rows = Results::toCsvRows($roundId);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="risultati_turno_' . $roundId . '.csv"');
    $out = fopen('php://output', 'w');
    if ($rows) { fputcsv($out, array_keys($rows[0])); foreach ($rows as $r) fputcsv($out, $r); }
    fclose($out);
    exit;
}
if ($export === 'json') {
    $rows = Results::toCsvRows($roundId);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="risultati_turno_' . $roundId . '.json"');
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Data ──────────────────────────────────────────────────────────────────────
$round             = Round::find($roundId);
$resultsByCategory = Results::forRound($roundId);
$voterCount        = Results::voterCount($roundId);
$voteRowCount      = Results::voteRowCount($roundId);
$stmt = Database::get()->prepare("SELECT COUNT(*) FROM candidates WHERE round_id = ? AND status = 'active'");
$stmt->execute([$roundId]);
$uniqueCandidates = (int) $stmt->fetchColumn();
$validatorInfo     = Results::validatorInfo($roundId);
$allRounds         = Round::forEvent($eventId);
$laterRounds       = array_filter($allRounds, fn($r) => $r['round_number'] > $round['round_number']);

// Dedup data (for open rounds)
$isOpen         = $round['model'] === 'open';
$dedupGroups    = $isOpen ? Deduplication::getPendingQueueGrouped($roundId) : [];
$reviewedCount  = $isOpen ? Deduplication::getReviewedCount($roundId)      : 0;
$aliases        = $isOpen ? Deduplication::getAliasDictionary($eventId)    : [];
$allCandidates  = $isOpen ? Candidate::forRound($roundId)                  : [];
$candByCategory = [];
foreach ($allCandidates as $c) { $candByCategory[$c['category_id']][] = $c; }

$pendingCount = count($dedupGroups);

$catTermS       = CatTerm::label($event, 's');
$catTermP       = CatTerm::label($event, 'p');
$pageTitle      = __('results_title') . ' — ' . __('round_number') . $round['round_number'];
$activeMenu     = 'results';
$currentEventId = $eventId;
$currentRoundId = $roundId;

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

<!-- Stats row -->
<div class="uk-grid-small uk-margin-bottom" uk-grid>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value" style="color:var(--e-accent)"><?= $uniqueCandidates ?></div>
            <div class="e-stat-label"><?= __('stat_unique_candidates') ?></div>
        </div>
    </div>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value"><?= $voterCount ?></div>
            <div class="e-stat-label"><?= __('stat_voters') ?></div>
        </div>
    </div>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value"><?= $voteRowCount ?></div>
            <div class="e-stat-label"><?= __('stat_votes_cast') ?></div>
        </div>
    </div>
    <div class="uk-width-expand">
        <div class="e-card" style="height:100%;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
            <?php if ($round['votes_validated']): ?>
            <div>
                <span class="e-badge e-badge-active">&#10003; <?= __('votes_validated') ?></span>
                <?php if ($validatorInfo && $validatorInfo['name']): ?>
                <p style="margin:4px 0 0;font-size:.72rem;color:#9a94b8">
                    <?= __('validated_by_label') ?> <strong><?= htmlspecialchars($validatorInfo['name']) ?></strong>
                    <?= htmlspecialchars(substr($validatorInfo['validated_at'] ?? '', 0, 16)) ?>
                </p>
                <?php endif ?>
            </div>
            <?php else: ?>
            <span class="e-badge e-badge-draft"><?= __('votes_not_validated') ?></span>
            <?php endif ?>
            <?php if ($round['results_released']): ?>
            <span class="e-badge e-badge-active">&#127758; <?= __('results_public_badge') ?></span>
            <?php else: ?>
            <span class="e-badge e-badge-closed"><?= __('results_not_public') ?></span>
            <?php endif ?>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="uk-tab uk-margin-bottom" uk-tab>
    <li class="<?= $tab === 'results' ? 'uk-active' : '' ?>">
        <a href="?round_id=<?= $roundId ?>&tab=results"><?= __('results_title') ?></a>
    </li>
    <?php if ($isOpen): ?>
    <li class="<?= $tab === 'review' ? 'uk-active' : '' ?>">
        <a href="?round_id=<?= $roundId ?>&tab=review">
            <?= __('dedup_tab') ?>
            <?php if ($pendingCount > 0): ?>
            <span class="uk-badge" style="background:#e67e22;margin-left:4px"><?= $pendingCount ?></span>
            <?php else: ?>
            <span class="uk-badge" style="background:#9a94b8;margin-left:4px"><?= $reviewedCount ?></span>
            <?php endif ?>
        </a>
    </li>
    <?php endif ?>
</ul>

<?php if ($tab === 'results'): ?>

<!-- Action toolbar -->
<div class="e-card uk-margin-bottom" style="padding:14px 20px">
    <div class="uk-flex uk-flex-wrap" style="gap:10px;align-items:center">
        <form method="post" style="margin:0">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="compute">
            <button class="uk-button uk-button-primary uk-button-small">
                <span uk-icon="icon:refresh;ratio:.8"></span> <?= __('results_compute') ?>
            </button>
        </form>

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
                    data-confirm="<?= htmlspecialchars(__('unvalidate_confirm')) ?>">
                <?= __('votes_unvalidate') ?>
            </button>
        </form>
        <?php endif ?>

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
                    data-confirm="<?= htmlspecialchars(__('hide_from_public_confirm')) ?>">
                <?= __('results_unpublish') ?>
            </button>
        </form>
        <?php endif ?>

        <?php if ($round['top_n_to_promote'] && !$round['promotion_confirmed'] && !empty($laterRounds)): ?>
        <form method="post" style="margin:0;display:flex;gap:6px;align-items:center">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="promote">
            <select name="to_round_id" class="uk-select uk-form-small" style="width:auto">
                <?php foreach ($laterRounds as $lr): ?>
                <option value="<?= $lr['id'] ?>">
                    <?= __('round_number') ?><?= $lr['round_number'] ?> <?= htmlspecialchars($lr['label'] ?? '') ?>
                </option>
                <?php endforeach ?>
            </select>
            <button class="uk-button uk-button-default uk-button-small"
                    <?= !$round['votes_validated'] ? 'disabled' : '' ?>
                    data-confirm="<?= htmlspecialchars(str_replace(':n', (string)(int)$round['top_n_to_promote'], __('promote_confirm'))) ?>">
                <span uk-icon="icon:push;ratio:.8"></span>
                <?= str_replace(':n', (string)(int)$round['top_n_to_promote'], __('promote_btn')) ?>
            </button>
        </form>
        <?php elseif ($round['promotion_confirmed']): ?>
        <span style="font-size:.8rem;color:#27ae60">&#10003; <?= __('promote_confirmed_msg') ?></span>
        <?php endif ?>
    </div>
</div>

<!-- Results by category -->
<?php if (empty($resultsByCategory)): ?>
<div class="e-card uk-text-center" style="padding:60px">
    <span uk-icon="icon:info;ratio:2" style="color:#9a94b8"></span>
    <p style="color:#9a94b8;margin-top:12px"><?= __('results_not_computed') ?></p>
</div>
<?php else: ?>

<?php foreach ($resultsByCategory as $catId => $rows):
    $catName    = $rows[0]['category_name'];
    $chartId    = 'chart-cat-' . $catId;
    $labels     = array_map(fn($r) => $r['candidate_name'], $rows);
    $values     = array_map(fn($r) => (float)$r['total_points'], $rows);
    $winner     = $rows[0]['candidate_name'];
    $sumVotes   = array_sum(array_column($rows, 'total_votes'));
    $sumPoints  = array_sum(array_column($rows, 'total_points'));
?>
<div class="uk-margin-medium-bottom">
    <h3 style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--e-accent);margin-bottom:10px">
        <?= htmlspecialchars($catName) ?>
    </h3>
    <div class="e-table">
        <table class="uk-table uk-table-hover uk-table-divider uk-table-small uk-margin-remove">
            <thead>
                <tr>
                    <th style="width:36px">#</th>
                    <th><?= __('candidate_col') ?></th>
                    <th style="text-align:right;white-space:nowrap"><?= __('total_votes') ?></th>
                    <th style="text-align:right;white-space:nowrap;color:#9a94b8">%</th>
                    <th style="text-align:right;white-space:nowrap"><?= __('total_points') ?></th>
                    <th style="text-align:right;white-space:nowrap;color:#9a94b8">%</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $pctVotes  = $sumVotes  > 0 ? round($r['total_votes']  / $sumVotes  * 100, 1) : 0;
                $pctPoints = $sumPoints > 0 ? round($r['total_points'] / $sumPoints * 100, 1) : 0;
            ?>
            <tr <?= $r['rank'] == 1 ? 'style="background:#f0fdf4;font-weight:700"' : '' ?>>
                <td><?= $r['rank'] == 1 ? '<span style="color:#27ae60">&#9733;</span>' : '<span style="color:#9a94b8">' . $r['rank'] . '</span>' ?></td>
                <td><?= htmlspecialchars($r['candidate_name']) ?></td>
                <td style="text-align:right;white-space:nowrap;color:#6b6494"><?= $r['total_votes'] ?></td>
                <td style="text-align:right;white-space:nowrap;color:#9a94b8;font-size:.82rem"><?= $pctVotes ?>%</td>
                <td style="text-align:right;white-space:nowrap;color:var(--e-primary);font-weight:600"><?= $r['total_points'] ?></td>
                <td style="text-align:right;white-space:nowrap;color:#9a94b8;font-size:.82rem"><?= $pctPoints ?>%</td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if (count($rows) <= 25): ?>
    <div class="e-card uk-margin-small-top" style="padding:12px 16px">
        <canvas id="<?= $chartId ?>" style="max-height:300px"></canvas>
    </div>
    <script>
    (function(){
        var ctx = document.getElementById('<?= $chartId ?>');
        if (!ctx) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{
                    label: '<?= addslashes(__('total_points')) ?>',
                    data: <?= json_encode($values) ?>,
                    backgroundColor: <?= json_encode(array_map(fn($r) => $r['rank'] == 1 ? '#27ae60' : '#7B68EE', $rows)) ?>,
                    borderRadius: 6
                }]
            },
            options: {
                indexAxis: 'y', responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: { color: '#f0eeff' } },
                    y: { grid: { display: false }, ticks: { font: { size: 11 } } }
                }
            }
        });
    })();
    </script>
    <?php endif ?>
</div>
<?php endforeach ?>
<?php endif ?>

<?php elseif ($tab === 'review' && $isOpen): ?>

<!-- Dedup / Candidate review tab -->
<div class="uk-flex uk-flex-between uk-flex-top uk-margin-bottom" style="gap:16px">
    <p style="color:#9a94b8;font-size:.875rem;margin:0;flex:1"><?= __('dedup_intro') ?></p>
    <div style="display:flex;align-items:center;gap:12px;flex-shrink:0">
        <span style="font-size:.85rem;color:#9a94b8">
            <strong style="color:var(--e-primary)"><?= $pendingCount ?></strong> <?= __('pending_review_label') ?>
            &nbsp;·&nbsp;
            <strong style="color:#27ae60"><?= $reviewedCount ?></strong> <?= __('reviewed_label') ?>
        </span>
        <form method="post" style="margin:0">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="rescan">
            <button class="uk-button uk-button-default uk-button-small"
                    title="<?= __('rescan_tooltip') ?>">
                <span uk-icon="icon:search;ratio:.8"></span> <?= __('rescan_btn') ?>
            </button>
        </form>
    </div>
</div>

<?php if (empty($dedupGroups)): ?>
<div class="e-card uk-text-center" style="padding:60px">
    <span uk-icon="icon:check;ratio:2" style="color:#27ae60"></span>
    <p style="color:#9a94b8;margin-top:12px"><?= __('no_dedup_pending') ?></p>
</div>
<?php else: ?>

<?php
$currentCat = null;
foreach ($dedupGroups as $group):
    if ($group['category_id'] !== $currentCat):
        if ($currentCat !== null) echo '</div>';
        $currentCat = $group['category_id'];
?>
<h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--e-accent);margin:24px 0 8px">
    <?= htmlspecialchars($group['category_name']) ?>
</h3>
<div class="uk-grid-small" uk-grid>
<?php endif ?>

<div class="uk-width-1-1">
<div class="e-card" style="border-left:4px solid <?= $group['max_score'] >= 85 ? '#e74c3c' : ($group['max_score'] >= 70 ? '#f39c12' : '#9a94b8') ?>">
    <div class="uk-grid-small uk-flex-middle" uk-grid>

        <!-- Varianti -->
        <div class="uk-width-2-5@m">
            <p style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:#9a94b8;margin:0 0 8px">
                <?= count($group['items']) ?> <?= __('dedup_variants') ?>
            </p>
            <?php foreach ($group['items'] as $item): ?>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:4px;font-size:.875rem">
                <input type="checkbox" class="e-group-item-check"
                       form="grpr-<?= $group['items'][0]['id'] ?>"
                       name="items[]"
                       value="<?= $item['id'] ?>:<?= $item['new_cand_id'] ?? 0 ?>"
                       checked>
                <span>
                    <strong><?= htmlspecialchars($item['raw_input']) ?></strong>
                    <span style="color:#9a94b8;font-size:.78rem;margin-left:4px"><?= $item['similarity_score'] ?>%</span>
                </span>
            </label>
            <?php endforeach ?>
        </div>

        <!-- Freccia -->
        <div class="uk-width-expand uk-text-center uk-visible@m">
            <span uk-icon="icon:arrow-right;ratio:1.2" style="color:#9a94b8"></span>
        </div>

        <!-- Target + azioni -->
        <div class="uk-width-1-2@m">
            <form id="grpr-<?= $group['items'][0]['id'] ?>" method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="_action" value="merge_group">
                <p style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:#9a94b8;margin:0 0 6px">
                    <?= __('dedup_suggestion') ?>
                </p>
                <div class="uk-margin-small-bottom">
                    <select name="target_candidate_id" class="uk-select uk-form-small" style="width:100%;max-width:280px">
                        <?php foreach ($candByCategory[$group['category_id']] ?? [] as $c): ?>
                        <option value="<?= $c['id'] ?>"
                            <?= $c['id'] == $group['suggested_candidate_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="uk-margin-small-bottom">
                    <input class="uk-input uk-form-small" type="text" name="canonical_override"
                           placeholder="<?= htmlspecialchars(__('dedup_canonical_label')) ?>"
                           style="width:100%;max-width:280px">
                </div>
                <button class="uk-button uk-button-primary uk-button-small" type="submit">
                    <span uk-icon="icon:link;ratio:.8"></span> <?= __('dedup_merge_selected') ?>
                </button>
            </form>

            <form method="post" style="margin-top:6px">
                <?= Csrf::field() ?>
                <input type="hidden" name="_action" value="keep_group">
                <?php foreach ($group['items'] as $item): ?>
                <input type="hidden" name="queue_ids[]" value="<?= $item['id'] ?>">
                <?php endforeach ?>
                <button class="uk-button uk-button-default uk-button-small">
                    <?= __('dedup_keep_all') ?>
                </button>
            </form>
        </div>

    </div>
</div>
</div>

<?php endforeach ?>
<?php if ($currentCat !== null) echo '</div>'; ?>

<?php endif ?>

<?php if (!empty($aliases)): ?>
<hr class="uk-margin-medium-top">
<h3 style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#9a94b8;margin-bottom:12px">
    <?= __('alias_dict_title') ?>
</h3>
<div class="e-table">
    <table class="uk-table uk-table-hover uk-table-divider uk-table-small uk-margin-remove">
        <thead>
            <tr>
                <th><?= $catTermS ?></th>
                <th><?= __('alias_normalized_col') ?></th>
                <th><?= __('canonical_name_col') ?></th>
                <th><?= __('created_by_col') ?></th>
                <th style="width:40px"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($aliases as $alias): ?>
        <tr>
            <td style="color:var(--e-accent);font-weight:600"><?= htmlspecialchars($alias['category_name']) ?></td>
            <td><code><?= htmlspecialchars($alias['alias']) ?></code></td>
            <td><strong><?= htmlspecialchars($alias['canonical_name']) ?></strong></td>
            <td style="color:#9a94b8"><?= htmlspecialchars($alias['created_by_name'] ?? '—') ?></td>
            <td>
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="_action" value="delete_alias">
                    <input type="hidden" name="alias_id" value="<?= $alias['id'] ?>">
                    <button style="background:none;border:none;cursor:pointer;padding:0;color:#e74c3c"
                            uk-icon="icon:trash;ratio:.8"
                            data-confirm="<?= htmlspecialchars(__('alias_delete_confirm')) ?>"></button>
                </form>
            </td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>

<?php endif ?>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
