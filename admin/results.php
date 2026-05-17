<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
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
    Flash::error('Turno non trovato.');
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
            catch (\Throwable $e) { Flash::error('Errore: ' . $e->getMessage()); }
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
            if (!$toRoundId || !$topN) { Flash::error('Specifica turno e numero di candidati.'); }
            elseif (!$round['votes_validated']) { Flash::error(__('results_not_validated')); }
            else {
                try { Results::promote($roundId, $toRoundId, $topN); Flash::success('Candidati promossi.'); }
                catch (\Throwable $e) { Flash::error('Errore: ' . $e->getMessage()); }
            }
        })(),

        // Dedup actions
        'merge' => (function () use ($roundId, $user) {
            $queueId   = (int) ($_POST['queue_id'] ?? 0);
            $sourceId  = (int) ($_POST['source_candidate_id'] ?? 0);
            $targetId  = (int) ($_POST['target_candidate_id'] ?? 0);
            $canonical = trim($_POST['canonical_override'] ?? '');
            if ($queueId && $sourceId && $targetId) {
                try { Deduplication::merge($queueId, $sourceId, $targetId, $user['id'], $canonical); Flash::success('Candidati uniti. Alias salvato.'); }
                catch (\Throwable $e) { Flash::error('Errore: ' . $e->getMessage()); }
            }
        })(),

        'keep' => (function () use ($user) {
            $queueId = (int) ($_POST['queue_id'] ?? 0);
            if ($queueId) { Deduplication::keep($queueId, $user['id']); Flash::success('Candidato mantenuto separato.'); }
        })(),

        'exclude' => (function () use ($user) {
            $queueId     = (int) ($_POST['queue_id'] ?? 0);
            $candidateId = (int) ($_POST['candidate_id'] ?? 0);
            if ($queueId && $candidateId) { Deduplication::exclude($queueId, $candidateId, $user['id']); Flash::success('Candidato escluso.'); }
        })(),

        'delete_alias' => (function () {
            $aliasId = (int) ($_POST['alias_id'] ?? 0);
            if ($aliasId) { Deduplication::deleteAlias($aliasId); Flash::success('Alias eliminato.'); }
        })(),

        'rescan' => (function () use ($roundId) {
            $n = Deduplication::rescanAll($roundId);
            Flash::success($n > 0 ? "$n nuovi possibili duplicati trovati." : 'Nessun nuovo duplicato trovato.');
        })(),

        default => null,
    };

    $redirectTab = in_array($action, ['merge','keep','exclude','delete_alias','rescan'], true) ? 'review' : 'results';
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
$validatorInfo     = Results::validatorInfo($roundId);
$allRounds         = Round::forEvent($eventId);
$laterRounds       = array_filter($allRounds, fn($r) => $r['round_number'] > $round['round_number']);

// Dedup data (for open rounds)
$isOpen        = $round['model'] === 'open';
$dedupQueue    = $isOpen ? Deduplication::getPendingQueue($roundId)    : [];
$reviewedCount = $isOpen ? Deduplication::getReviewedCount($roundId)   : 0;
$aliases       = $isOpen ? Deduplication::getAliasDictionary($eventId) : [];
$allCandidates = $isOpen ? Candidate::forRound($roundId)               : [];
$candByCategory = [];
foreach ($allCandidates as $c) { $candByCategory[$c['category_id']][] = $c; }

$pendingCount = count($dedupQueue);

$pageTitle      = __('results_title') . ' — ' . __('round_number') . $round['round_number'];
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
            <div class="e-stat-value"><?= $voterCount ?></div>
            <div class="e-stat-label">Votanti</div>
        </div>
    </div>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value"><?= $voteRowCount ?></div>
            <div class="e-stat-label">Voti espressi</div>
        </div>
    </div>
    <div class="uk-width-expand">
        <div class="e-card" style="height:100%;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
            <?php if ($round['votes_validated']): ?>
            <div>
                <span class="e-badge e-badge-active">&#10003; <?= __('votes_validated') ?></span>
                <?php if ($validatorInfo && $validatorInfo['name']): ?>
                <p style="margin:4px 0 0;font-size:.72rem;color:#9a94b8">
                    da <strong><?= htmlspecialchars($validatorInfo['name']) ?></strong>
                    il <?= htmlspecialchars(substr($validatorInfo['validated_at'] ?? '', 0, 16)) ?>
                </p>
                <?php endif ?>
            </div>
            <?php else: ?>
            <span class="e-badge e-badge-draft">Voti non ancora validati</span>
            <?php endif ?>
            <?php if ($round['results_released']): ?>
            <span class="e-badge e-badge-active">&#127758; Risultati pubblici</span>
            <?php else: ?>
            <span class="e-badge e-badge-closed">Risultati non pubblicati</span>
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
                    data-confirm="Rimuovere la validazione? I risultati verranno nascosti.">
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
                    data-confirm="Nascondere i risultati dalla pagina pubblica?">
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
                    Turno #<?= $lr['round_number'] ?> <?= htmlspecialchars($lr['label'] ?? '') ?>
                </option>
                <?php endforeach ?>
            </select>
            <button class="uk-button uk-button-default uk-button-small"
                    <?= !$round['votes_validated'] ? 'disabled' : '' ?>
                    data-confirm="Promuovere i top <?= (int)$round['top_n_to_promote'] ?> al turno selezionato?">
                <span uk-icon="icon:push;ratio:.8"></span>
                Promuovi top <?= (int)$round['top_n_to_promote'] ?>
            </button>
        </form>
        <?php elseif ($round['promotion_confirmed']): ?>
        <span style="font-size:.8rem;color:#27ae60">&#10003; Candidati promossi</span>
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
    $catName = $rows[0]['category_name'];
    $chartId = 'chart-cat-' . $catId;
    $labels  = array_map(fn($r) => $r['candidate_name'], $rows);
    $values  = array_map(fn($r) => (float)$r['total_points'], $rows);
    $winner  = $rows[0]['candidate_name'];
?>
<div class="uk-margin-medium-bottom">
    <h3 style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--e-accent);margin-bottom:10px">
        <?= htmlspecialchars($catName) ?>
    </h3>
    <div class="uk-grid-medium" uk-grid>
        <div class="uk-width-1-2@l">
            <div class="e-table">
                <table class="uk-table uk-table-hover uk-table-divider uk-table-small uk-margin-remove">
                    <thead>
                        <tr>
                            <th style="width:36px">#</th>
                            <th>Candidato</th>
                            <th style="text-align:right"><?= __('total_votes') ?></th>
                            <th style="text-align:right"><?= __('total_points') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr <?= $r['rank'] == 1 ? 'style="background:#f0fdf4;font-weight:700"' : '' ?>>
                        <td><?= $r['rank'] == 1 ? '<span style="color:#27ae60">&#9733;</span>' : '<span style="color:#9a94b8">' . $r['rank'] . '</span>' ?></td>
                        <td><?= htmlspecialchars($r['candidate_name']) ?></td>
                        <td style="text-align:right;color:#6b6494"><?= $r['total_votes'] ?></td>
                        <td style="text-align:right;color:var(--e-primary);font-weight:600"><?= $r['total_points'] ?></td>
                    </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="uk-width-1-2@l">
            <div class="e-card" style="height:100%;min-height:180px">
                <canvas id="<?= $chartId ?>" style="max-height:300px"></canvas>
            </div>
        </div>
    </div>
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
<?php endforeach ?>
<?php endif ?>

<?php elseif ($tab === 'review' && $isOpen): ?>

<!-- Dedup / Candidate review tab -->
<div class="uk-flex uk-flex-between uk-flex-top uk-margin-bottom" style="gap:16px">
    <p style="color:#9a94b8;font-size:.875rem;margin:0;flex:1"><?= __('dedup_intro') ?></p>
    <div style="display:flex;align-items:center;gap:12px;flex-shrink:0">
        <span style="font-size:.85rem;color:#9a94b8">
            <strong style="color:var(--e-primary)"><?= $pendingCount ?></strong> da rivedere
            &nbsp;·&nbsp;
            <strong style="color:#27ae60"><?= $reviewedCount ?></strong> revisionati
        </span>
        <form method="post" style="margin:0">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="rescan">
            <button class="uk-button uk-button-default uk-button-small"
                    title="Ri-analizza tutti i candidati alla ricerca di possibili duplicati non ancora segnalati">
                <span uk-icon="icon:search;ratio:.8"></span> Ri-scansiona
            </button>
        </form>
    </div>
</div>

<?php if (empty($dedupQueue)): ?>
<div class="e-card uk-text-center" style="padding:60px">
    <span uk-icon="icon:check;ratio:2" style="color:#27ae60"></span>
    <p style="color:#9a94b8;margin-top:12px">Nessuna candidatura in attesa di revisione.</p>
</div>
<?php else:
    $currentCat = null;
    foreach ($dedupQueue as $item):
        if ($item['category_id'] !== $currentCat):
            if ($currentCat !== null) echo '</div>';
            $currentCat = $item['category_id'];
?>
<h3 style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--e-accent);margin:20px 0 8px">
    <?= htmlspecialchars($item['category_name']) ?>
</h3>
<div>
<?php endif ?>

<div class="e-card uk-margin-small-bottom" style="border-left:4px solid <?= $item['similarity_score'] >= 85 ? '#e74c3c' : ($item['similarity_score'] >= 70 ? '#f39c12' : '#9a94b8') ?>;padding:16px 20px">
    <div class="uk-grid-small" uk-grid>

        <div class="uk-width-1-3@m">
            <p style="font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:#9a94b8;margin:0 0 4px">Nome inserito</p>
            <p style="font-size:.95rem;font-weight:700;margin:0 0 2px"><?= htmlspecialchars($item['new_cand_name'] ?? $item['raw_input']) ?></p>
            <p style="font-size:.75rem;color:#9a94b8;margin:0">
                Normalizzato: <code><?= htmlspecialchars($item['normalized_input']) ?></code>
            </p>
        </div>

        <div class="uk-width-1-6@m uk-text-center uk-flex uk-flex-middle uk-flex-center">
            <div>
                <div style="font-size:1.3rem;font-weight:800;color:<?= $item['similarity_score'] >= 85 ? '#e74c3c' : ($item['similarity_score'] >= 70 ? '#f39c12' : '#6b6494') ?>">
                    <?= $item['similarity_score'] ?>%
                </div>
                <div style="font-size:.68rem;color:#9a94b8">somiglianza</div>
            </div>
        </div>

        <div class="uk-width-1-2@m">
            <p style="font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:#9a94b8;margin:0 0 4px">
                Possibile corrispondenza
            </p>
            <?php if ($item['suggested_name']): ?>
            <p style="font-size:.95rem;font-weight:600;margin:0 0 10px;color:var(--e-primary)">
                <?= htmlspecialchars($item['suggested_name']) ?>
            </p>
            <?php else: ?>
            <p style="color:#9a94b8;margin:0 0 10px;font-size:.875rem">Nessun suggerimento</p>
            <?php endif ?>

            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                <!-- Merge -->
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="_action" value="merge">
                    <input type="hidden" name="queue_id" value="<?= $item['id'] ?>">
                    <input type="hidden" name="source_candidate_id" value="<?= $item['new_cand_id'] ?>">
                    <div style="margin-bottom:6px">
                        <select name="target_candidate_id" class="uk-select uk-form-small" style="width:210px">
                            <?php foreach ($candByCategory[$item['category_id']] ?? [] as $c):
                                if ($c['id'] == $item['new_cand_id']) continue; ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id'] == $item['suggested_candidate_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div style="margin-bottom:6px">
                        <input class="uk-input uk-form-small" type="text" name="canonical_override"
                               placeholder="Nome canonico personalizzato (opzionale)" style="width:210px">
                    </div>
                    <button class="uk-button uk-button-primary uk-button-small">
                        <span uk-icon="icon:link;ratio:.8"></span> Unisci
                    </button>
                </form>

                <!-- Keep -->
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="_action" value="keep">
                    <input type="hidden" name="queue_id" value="<?= $item['id'] ?>">
                    <button class="uk-button uk-button-default uk-button-small">Mantieni separato</button>
                </form>

                <!-- Exclude -->
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="_action" value="exclude">
                    <input type="hidden" name="queue_id" value="<?= $item['id'] ?>">
                    <input type="hidden" name="candidate_id" value="<?= $item['new_cand_id'] ?>">
                    <button class="uk-button uk-button-link uk-button-small"
                            data-confirm="Escludere questo candidato dai risultati?"
                            style="color:#e74c3c">
                        Escludi
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
    Dizionario alias (applicati automaticamente alle prossime edizioni)
</h3>
<div class="e-table">
    <table class="uk-table uk-table-hover uk-table-divider uk-table-small uk-margin-remove">
        <thead>
            <tr>
                <th>Categoria</th>
                <th>Alias (normalizzato)</th>
                <th>→ Nome canonico</th>
                <th>Creato da</th>
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
                            data-confirm="Eliminare questo alias?"></button>
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
