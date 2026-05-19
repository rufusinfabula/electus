<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Database;
use Electus\Core\Flash;
use Electus\Models\Event;
use Electus\Models\Round;

$eventId = (int) ($_GET['event_id'] ?? 0);
if (!$eventId) { header('Location: /admin/events.php'); exit; }

$event = Event::find($eventId);
if (!$event) { Flash::error('Voting session not found.'); header('Location: /admin/events.php'); exit; }

Auth::requireEventPermission($eventId);

// Quick round status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $action  = $_POST['_action'] ?? '';
    $roundId = (int) ($_POST['round_id'] ?? 0);

    if ($roundId && in_array($action, ['activate','close','delete'], true)) {
        match ($action) {
            'activate' => (function () use ($roundId) { Round::setStatus($roundId, 'active');  Flash::success('Turno attivato.'); })(),
            'close'    => (function () use ($roundId) { Round::setStatus($roundId, 'closed');  Flash::success('Turno chiuso.'); })(),
            'delete'   => (function () use ($roundId) { Round::delete($roundId); Flash::success('Turno eliminato.'); })(),
        };
    }

    header('Location: /admin/rounds.php?event_id=' . $eventId);
    exit;
}

$rounds = Round::forEvent($eventId);
$pdo    = Database::get();

// Event-level stats
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM votes v
     JOIN event_rounds r ON r.id = v.round_id WHERE r.event_id = ?'
);
$stmt->execute([$eventId]);
$totalVotes = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM voter_lists WHERE event_id = ?'
);
$stmt->execute([$eventId]);
$registeredVoters = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE event_id = ?');
$stmt->execute([$eventId]);
$categoriesCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM dedup_queue dq
     JOIN event_rounds r ON r.id = dq.round_id
     WHERE r.event_id = ? AND dq.status = 'pending'"
);
$stmt->execute([$eventId]);
$pendingDedup = (int) $stmt->fetchColumn();

$appUrl         = rtrim($config['app']['url'] ?? '', '/');
$voteUrl        = $appUrl . '/vote/event.php?slug=' . urlencode($event['slug']);

$modelIcons = [
    'open'         => 'pencil',
    'single'       => 'check',
    'multiple'     => 'thumbnails',
    'borda'        => 'star',
    'proportional' => 'pull',
    'weighted'     => 'more',
];

$pageTitle      = htmlspecialchars($event['name']);
$activeMenu     = 'rounds';
$currentEventId = $eventId;

ob_start();
?>

<!-- Header -->
<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom">
    <div class="uk-flex uk-flex-middle" style="gap:12px">
        <a href="/admin/events.php" uk-icon="arrow-left" class="uk-icon-link"></a>
        <div>
            <div class="uk-flex uk-flex-middle" style="gap:8px">
                <h1 class="e-page-title uk-margin-remove"><?= htmlspecialchars($event['name']) ?></h1>
                <span class="e-badge e-badge-<?= $event['status'] ?>">
                    <?= __('event_status_' . $event['status']) ?>
                </span>
            </div>
            <p style="margin:2px 0 0;font-size:.8rem;color:#9a94b8">
                <?= __('access_' . $event['access_mode']) ?>
                &nbsp;·&nbsp; /<?= htmlspecialchars($event['slug']) ?>
            </p>
        </div>
    </div>
    <div class="uk-flex" style="gap:8px">
        <a href="/admin/events-edit.php?id=<?= $eventId ?>"
           class="uk-button uk-button-default uk-button-small">
            <span uk-icon="icon:settings;ratio:.85"></span> Impostazioni
        </a>
        <a href="/admin/rounds-edit.php?event_id=<?= $eventId ?>"
           class="uk-button uk-button-primary uk-button-small">
            <span uk-icon="plus-circle"></span> <?= __('round_new') ?>
        </a>
    </div>
</div>

<!-- Stats row -->
<div class="uk-grid-small uk-margin-bottom" uk-grid>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:110px">
            <div class="e-stat-value"><?= count($rounds) ?></div>
            <div class="e-stat-label"><?= __('rounds_title') ?></div>
        </div>
    </div>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:110px">
            <div class="e-stat-value"><?= $categoriesCount ?></div>
            <div class="e-stat-label"><?= __('categories_title') ?></div>
        </div>
    </div>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:110px">
            <div class="e-stat-value"><?= $registeredVoters ?></div>
            <div class="e-stat-label"><?= __('voters_title') ?></div>
        </div>
    </div>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:110px">
            <div class="e-stat-value" style="color:var(--e-primary)"><?= $totalVotes ?></div>
            <div class="e-stat-label">Voti totali</div>
        </div>
    </div>
</div>

<!-- Public link strip (full width, always visible) -->
<?php $isLive = ($event['status'] === 'active'); ?>
<div class="e-publink-strip<?= $isLive ? ' e-publink-live' : '' ?> uk-margin-bottom">
    <?php if ($isLive): ?>
    <span class="e-publink-dot"></span>
    <span style="font-size:.72rem;font-weight:700;color:#1a7a3c;white-space:nowrap">VOTO APERTO</span>
    <?php else: ?>
    <span uk-icon="icon:link;ratio:.8" style="color:#9a94b8;flex-shrink:0"></span>
    <?php endif ?>
    <code class="e-publink-url"><?= htmlspecialchars($voteUrl) ?></code>
    <?php if (!$isLive): ?>
    <span style="font-size:.72rem;color:#e67e22;white-space:nowrap">Attiva la votazione per abilitare</span>
    <?php endif ?>
    <div style="display:flex;gap:6px;flex-shrink:0;margin-left:auto">
        <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($voteUrl, ENT_QUOTES) ?>');this.innerHTML='&#10003; Copiato';setTimeout(()=>this.innerHTML='<span uk-icon=\'icon:copy;ratio:.75\'></span> Copia',1800)"
                class="uk-button uk-button-default uk-button-small">
            <span uk-icon="icon:copy;ratio:.75"></span> Copia
        </button>
        <a href="<?= htmlspecialchars($voteUrl) ?>" target="_blank"
           class="uk-button uk-button-default uk-button-small" title="Apri in nuova scheda">
            <span uk-icon="icon:forward;ratio:.75"></span>
        </a>
    </div>
</div>

<?php if ($pendingDedup > 0): ?>
<div class="uk-alert-warning" uk-alert style="margin-bottom:20px">
    <p>
        <span uk-icon="warning"></span>
        <strong><?= $pendingDedup ?> candidature</strong> in attesa di revisione.
        Revisionarle prima di calcolare i risultati.
        <a href="/admin/results.php?round_id=<?= $rounds[0]['id'] ?? 0 ?>&tab=review" style="font-weight:600">
            Vai alla revisione &rarr;
        </a>
    </p>
</div>
<?php endif ?>

<!-- Pipeline -->
<?php if (empty($rounds)): ?>
<div class="e-card uk-text-center" style="padding:60px">
    <span uk-icon="icon:list;ratio:2" style="color:#c8c3e0"></span>
    <p style="color:#9a94b8;margin-top:12px">Nessuna fase ancora. Crea la prima fase di voto.</p>
    <a href="/admin/rounds-edit.php?event_id=<?= $eventId ?>" class="uk-button uk-button-primary uk-margin-top">
        <span uk-icon="plus-circle"></span> <?= __('round_new') ?>
    </a>
</div>
<?php else: ?>

<div class="e-pipeline">
<?php foreach ($rounds as $i => $round):
    $step       = 0;
    if ($round['status'] === 'active')  $step = 1;
    if ($round['status'] === 'closed')  $step = 2;
    if ($round['votes_validated'])      $step = 3;
    if ($round['results_released'])     $step = 4;

    $roundCats  = Round::categoriesFor((int) $round['id']);
    $isLast     = $i === count($rounds) - 1;

    $stepLabels = ['Bozza', 'In corso', 'Chiuso', 'Validato', 'Pubblicato'];
    $catCount   = count($roundCats);
    $showMore   = $catCount > 4;
    $roundUid   = 'r' . $round['id'];
?>
<!-- Phase block -->
<div class="e-pipeline-block e-card">

    <!-- Round header -->
    <div class="uk-flex uk-flex-between uk-flex-top" style="gap:12px">
        <div class="uk-flex uk-flex-middle" style="gap:10px">
            <div class="e-pipeline-number"><?= $round['round_number'] ?></div>
            <div>
                <div class="uk-flex uk-flex-middle" style="gap:6px">
                    <h3 style="margin:0;font-size:.95rem;font-weight:700;color:var(--e-text)">
                        <?= $round['label'] ? htmlspecialchars($round['label']) : 'Fase ' . $round['round_number'] ?>
                    </h3>
                    <?php if ($round['status'] === 'active' && $event['status'] === 'active'): ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:700;color:#1a7a3c;background:#e6f9ee;padding:2px 8px;border-radius:20px">
                        <span style="width:6px;height:6px;border-radius:50%;background:#1a7a3c;animation:e-pulse 1.4s ease-in-out infinite"></span>
                        Live
                    </span>
                    <?php endif ?>
                </div>
                <p style="margin:0;font-size:.78rem;color:#9a94b8">
                    <span uk-icon="icon:<?= $modelIcons[$round['model']] ?? 'list' ?>;ratio:.8"></span>
                    <?= __('model_' . $round['model']) ?>
                    <?php if ($round['opens_at']): ?>
                    &nbsp;·&nbsp;<?= date('d/m/Y', strtotime($round['opens_at'])) ?>
                    → <?= $round['closes_at'] ? date('d/m/Y', strtotime($round['closes_at'])) : '∞' ?>
                    <?php endif ?>
                </p>
            </div>
        </div>

        <!-- Lifecycle stepper (dot-based) -->
        <div class="e-stepper" style="flex-shrink:0">
            <?php foreach ($stepLabels as $s => $lbl):
                $done    = $step > $s;
                $current = $step === $s;
            ?>
            <?php if ($s > 0): ?><span class="e-stepper-sep"></span><?php endif ?>
            <span class="e-stepper-dot <?= $done ? 'e-step-done' : ($current ? 'e-step-current' : 'e-step-future') ?>"
                  title="<?= $lbl ?>">
                <?php if ($current): ?><span class="e-stepper-label"><?= $lbl ?></span><?php endif ?>
            </span>
            <?php endforeach ?>
        </div>
    </div>

    <!-- Active categories with advancement summary -->
    <?php if (!empty($roundCats)): ?>
    <div class="e-pipeline-cats<?= $showMore ? ' e-cats-collapsible' : '' ?>" id="cats-<?= $roundUid ?>">
        <?php foreach ($roundCats as $ci => $cat):
            $mode     = $cat['advancement_mode'] ?? 'manual';
            $cnt      = $cat['advancement_count'] ?? null;
            $advLabel = match($mode) {
                'auto'   => 'top ' . ($cnt ?? '?') . ' avanzano',
                'all'    => 'tutti avanzano',
                'none'   => 'nessuno avanza',
                default  => 'avanzamento manuale',
            };
        ?>
        <div class="e-pipeline-cat<?= ($showMore && $ci >= 4) ? ' e-cat-hidden' : '' ?>">
            <span class="e-pipeline-cat-name"><?= htmlspecialchars($cat['name']) ?></span>
            <?php if (!$isLast): ?>
            <span class="e-pipeline-cat-adv e-pipeline-adv-<?= $mode ?>"><?= $advLabel ?></span>
            <?php endif ?>
        </div>
        <?php endforeach ?>
        <?php if ($showMore): ?>
        <button type="button" class="e-cats-more" onclick="toggleCats('<?= $roundUid ?>', this)">
            +<?= $catCount - 4 ?> altre
        </button>
        <?php endif ?>
    </div>
    <?php endif ?>

    <!-- Actions -->
    <div class="e-round-actions">
        <!-- Primary contextual action -->
        <?php if ($round['status'] === 'active'): ?>
        <a href="/admin/candidates.php?round_id=<?= $round['id'] ?>"
           class="uk-button uk-button-primary uk-button-small">
            <span uk-icon="icon:list;ratio:.75"></span> Candidati
        </a>
        <?php elseif ($round['status'] === 'closed'): ?>
        <a href="/admin/results.php?round_id=<?= $round['id'] ?>"
           class="uk-button uk-button-primary uk-button-small">
            <span uk-icon="icon:bar-chart;ratio:.75"></span> Risultati<?= $round['results_released'] ? ' ✓' : '' ?>
        </a>
        <?php else: ?>
        <a href="/admin/candidates.php?round_id=<?= $round['id'] ?>"
           class="uk-button uk-button-default uk-button-small">Candidati</a>
        <?php endif ?>

        <!-- Secondary actions -->
        <?php if ($round['status'] !== 'closed'): ?>
        <a href="/admin/results.php?round_id=<?= $round['id'] ?>"
           class="uk-button uk-button-default uk-button-small">Risultati</a>
        <?php elseif ($round['status'] !== 'active'): ?>
        <a href="/admin/candidates.php?round_id=<?= $round['id'] ?>"
           class="uk-button uk-button-default uk-button-small">Candidati</a>
        <?php endif ?>
        <a href="/admin/rounds-edit.php?id=<?= $round['id'] ?>&event_id=<?= $eventId ?>"
           class="uk-button uk-button-default uk-button-small">
            <span uk-icon="icon:pencil;ratio:.75"></span> Modifica
        </a>

        <!-- Lifecycle action -->
        <?php if (in_array($round['status'], ['draft','active'], true)): ?>
        <form method="post" style="display:inline;margin:0">
            <?= Csrf::field() ?>
            <input type="hidden" name="round_id" value="<?= $round['id'] ?>">
            <?php if ($round['status'] === 'draft'): ?>
            <input type="hidden" name="_action" value="activate">
            <button class="uk-button uk-button-primary uk-button-small">
                <span uk-icon="icon:play;ratio:.75"></span> Attiva
            </button>
            <?php else: ?>
            <input type="hidden" name="_action" value="close">
            <button class="uk-button uk-button-small e-btn-danger">
                <span uk-icon="icon:ban;ratio:.75"></span> Chiudi
            </button>
            <?php endif ?>
        </form>
        <?php endif ?>

        <!-- Destructive action (icon only, far right) -->
        <form method="post" style="display:inline;margin:0 0 0 auto">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="delete">
            <input type="hidden" name="round_id" value="<?= $round['id'] ?>">
            <button class="uk-button uk-button-link uk-button-small" style="color:#ddd;padding:0 6px"
                    title="<?= htmlspecialchars(__('confirm_delete')) ?>"
                    data-confirm="<?= htmlspecialchars(__('confirm_delete')) ?>">
                <span uk-icon="icon:trash;ratio:.8"></span>
            </button>
        </form>
    </div>

</div>

<?php if (!$isLast): ?>
<!-- Arrow between phases -->
<div class="e-pipeline-arrow">
    <div class="e-pipeline-arrow-line"></div>
    <div class="e-pipeline-arrow-head">▼</div>
</div>
<?php endif ?>

<?php endforeach ?>

<!-- Add phase -->
<div class="e-pipeline-add">
    <a href="/admin/rounds-edit.php?event_id=<?= $eventId ?><?= !empty($rounds) ? '&parent_round_id=' . end($rounds)['id'] : '' ?>"
       class="uk-button uk-button-primary uk-button-small">
        <span uk-icon="plus-circle"></span> Aggiungi fase
    </a>
</div>

<script>
function toggleCats(uid, btn) {
    var wrap = document.getElementById('cats-' + uid);
    var hidden = wrap.querySelectorAll('.e-cat-hidden');
    var expanded = wrap.classList.toggle('e-cats-expanded');
    hidden.forEach(function(el) { el.style.display = expanded ? 'flex' : 'none'; });
    var n = hidden.length;
    btn.textContent = expanded ? 'Mostra meno' : '+' + n + ' altre';
}
</script>

</div><!-- /e-pipeline -->
<?php endif ?>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
