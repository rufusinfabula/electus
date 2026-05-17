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
$totalVotes = (int) $pdo->prepare(
    'SELECT COUNT(*) FROM votes v
     JOIN event_rounds r ON r.id = v.round_id
     WHERE r.event_id = ?'
)->execute([$eventId]) ? $pdo->query(
    'SELECT COUNT(*) FROM votes v
     JOIN event_rounds r ON r.id = v.round_id
     WHERE r.event_id = ' . $eventId
)->fetchColumn() : 0;

// Use prepared properly
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
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value"><?= count($rounds) ?></div>
            <div class="e-stat-label"><?= __('rounds_title') ?></div>
        </div>
    </div>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value"><?= $categoriesCount ?></div>
            <div class="e-stat-label"><?= __('categories_title') ?></div>
        </div>
    </div>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value"><?= $registeredVoters ?></div>
            <div class="e-stat-label"><?= __('voters_title') ?></div>
        </div>
    </div>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value" style="color:var(--e-primary)"><?= $totalVotes ?></div>
            <div class="e-stat-label">Voti totali</div>
        </div>
    </div>

    <!-- Public link -->
    <div class="uk-width-expand">
        <div class="e-stat" style="display:flex;align-items:center;gap:12px;height:100%">
            <span uk-icon="icon:link;ratio:.9" style="color:var(--e-accent);flex-shrink:0"></span>
            <div style="flex:1;min-width:0">
                <div style="font-size:.7rem;color:#9a94b8;margin-bottom:2px;text-transform:uppercase;letter-spacing:.06em">
                    Link di voto pubblico
                </div>
                <code style="font-size:.8rem;color:<?= $event['status'] === 'active' ? 'var(--e-primary)' : '#9a94b8' ?>;word-break:break-all">
                    <?= htmlspecialchars($voteUrl) ?>
                </code>
                <?php if ($event['status'] !== 'active'): ?>
                <div style="font-size:.7rem;color:#e67e22;margin-top:2px">
                    Attiva la votazione per abilitare questo link
                </div>
                <?php endif ?>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0">
                <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($voteUrl, ENT_QUOTES) ?>');this.innerHTML='&#10003;';setTimeout(()=>this.innerHTML='<span uk-icon=\'icon:copy;ratio:.8\'></span>',1500)"
                        class="uk-button uk-button-default uk-button-small" title="Copia">
                    <span uk-icon="icon:copy;ratio:.8"></span>
                </button>
                <a href="<?= htmlspecialchars($voteUrl) ?>" target="_blank"
                   class="uk-button uk-button-default uk-button-small" title="Apri">
                    <span uk-icon="icon:forward;ratio:.8"></span>
                </a>
            </div>
        </div>
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

<!-- Rounds -->
<?php if (empty($rounds)): ?>
<div class="e-card uk-text-center" style="padding:60px">
    <span uk-icon="icon:list;ratio:2" style="color:#c8c3e0"></span>
    <p style="color:#9a94b8;margin-top:12px">Nessun turno ancora. Crea il primo turno di voto.</p>
    <a href="/admin/rounds-edit.php?event_id=<?= $eventId ?>" class="uk-button uk-button-primary uk-margin-top">
        <span uk-icon="plus-circle"></span> <?= __('round_new') ?>
    </a>
</div>
<?php else: ?>

<div style="display:flex;flex-direction:column;gap:12px">
    <?php foreach ($rounds as $round):
        // Lifecycle step
        $step = 0;
        if ($round['status'] === 'active')            $step = 1;
        if ($round['status'] === 'closed')            $step = 2;
        if ($round['votes_validated'])                $step = 3;
        if ($round['results_released'])               $step = 4;
    ?>
    <div class="e-card" style="padding:20px 24px">

        <!-- Round header -->
        <div class="uk-flex uk-flex-between uk-flex-top uk-margin-small-bottom">
            <div class="uk-flex uk-flex-middle" style="gap:10px">
                <span uk-icon="icon:<?= $modelIcons[$round['model']] ?? 'list' ?>"
                      style="color:var(--e-accent)"></span>
                <div>
                    <h3 style="margin:0;font-size:.95rem;font-weight:700">
                        <?= __('round_number') ?><?= $round['round_number'] ?>
                        <?= $round['label'] ? '— ' . htmlspecialchars($round['label']) : '' ?>
                    </h3>
                    <p style="margin:0;font-size:.78rem;color:#9a94b8">
                        <?= __('model_' . $round['model']) ?>
                        <?php if ($round['opens_at']): ?>
                        &nbsp;·&nbsp;<?= date('d/m/Y H:i', strtotime($round['opens_at'])) ?>
                        → <?= $round['closes_at'] ? date('d/m/Y H:i', strtotime($round['closes_at'])) : '∞' ?>
                        <?php endif ?>
                    </p>
                </div>
            </div>
            <!-- Lifecycle indicator -->
            <div style="display:flex;align-items:center;gap:6px;font-size:.72rem">
                <?php
                $steps = [
                    [0, 'Bozza',     'e-badge-draft'],
                    [1, 'In corso',  'e-badge-active'],
                    [2, 'Chiuso',    'e-badge-closed'],
                    [3, 'Validato',  ''],
                    [4, 'Pubblicato','e-badge-active'],
                ];
                foreach ($steps as [$s, $label, $cls]):
                    $done    = $step > $s;
                    $current = $step === $s;
                ?>
                <span <?php if ($s > 0): ?>style="color:#c8c3e0"><?= '›' ?></span><?php endif ?>
                <span class="<?= $current ? 'e-badge ' . $cls : '' ?>"
                      style="<?= $done ? 'color:#27ae60;font-weight:700' : ($current ? '' : 'color:#c8c3e0') ?>">
                    <?= $done ? '✓' : '' ?><?= $label ?>
                </span>
                <?php endforeach ?>
            </div>
        </div>

        <!-- Action buttons -->
        <div class="uk-flex uk-flex-wrap" style="gap:8px;margin-top:14px;padding-top:14px;border-top:1px solid #f5f3ff">

            <a href="/admin/candidates.php?round_id=<?= $round['id'] ?>"
               class="uk-button uk-button-default uk-button-small">
                <?= __('candidates_title') ?>
            </a>

            <a href="/admin/results.php?round_id=<?= $round['id'] ?>"
               class="uk-button uk-button-default uk-button-small">
                <?= __('results_title') ?>
                <?php if ($round['results_released']): ?>
                <span style="color:#27ae60;margin-left:4px">&#127758;</span>
                <?php endif ?>
            </a>

            <a href="/admin/rounds-edit.php?id=<?= $round['id'] ?>&event_id=<?= $eventId ?>"
               class="uk-button uk-button-default uk-button-small">
                <?= __('edit') ?>
            </a>

            <!-- Activate / Close -->
            <?php if (in_array($round['status'], ['draft','active'], true)): ?>
            <form method="post" style="display:inline;margin:0">
                <?= Csrf::field() ?>
                <input type="hidden" name="round_id" value="<?= $round['id'] ?>">
                <?php if ($round['status'] === 'draft'): ?>
                <input type="hidden" name="_action" value="activate">
                <button class="uk-button uk-button-primary uk-button-small">
                    <span uk-icon="icon:play;ratio:.8"></span> Attiva
                </button>
                <?php else: ?>
                <input type="hidden" name="_action" value="close">
                <button class="uk-button uk-button-small"
                        style="background:#e74c3c;color:#fff;border-color:#e74c3c">
                    <span uk-icon="icon:ban;ratio:.8"></span> Chiudi
                </button>
                <?php endif ?>
            </form>
            <?php endif ?>

            <!-- Delete (right-aligned) -->
            <form method="post" style="display:inline;margin:0 0 0 auto">
                <?= Csrf::field() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="round_id" value="<?= $round['id'] ?>">
                <button class="uk-button uk-button-link uk-button-small"
                        style="color:#c8c3e0"
                        data-confirm="<?= htmlspecialchars(__('confirm_delete')) ?>">
                    <?= __('delete') ?>
                </button>
            </form>

        </div>

        <!-- Active voting link -->
        <?php if ($round['status'] === 'active' && $event['status'] === 'active'): ?>
        <div style="margin-top:12px;padding:10px 14px;background:#f0fdf4;border-radius:8px;display:flex;align-items:center;gap:10px">
            <span style="font-size:.75rem;color:#27ae60;font-weight:600">&#9679; VOTO APERTO</span>
            <code style="font-size:.78rem;color:#27ae60;flex:1"><?= htmlspecialchars($voteUrl) ?></code>
            <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($voteUrl, ENT_QUOTES) ?>');this.textContent='Copiato!';setTimeout(()=>this.textContent='Copia',1500)"
                    class="uk-button uk-button-small" style="background:#27ae60;color:#fff;border:none;padding:4px 12px;border-radius:6px;font-size:.75rem">
                Copia
            </button>
        </div>
        <?php endif ?>

    </div>
    <?php endforeach ?>
</div>
<?php endif ?>

<!-- Categories quick link -->
<div class="uk-margin-medium-top uk-flex uk-flex-right">
    <a href="/admin/categories.php?event_id=<?= $eventId ?>"
       class="uk-button uk-button-default uk-button-small">
        <span uk-icon="icon:tag;ratio:.85"></span>
        Gestisci categorie (<?= $categoriesCount ?>)
    </a>
</div>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
