<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\CatTerm;
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
            'activate' => (function () use ($roundId) { Round::setStatus($roundId, 'active');  Flash::success(__('round_activated')); })(),
            'close'    => (function () use ($roundId) { Round::setStatus($roundId, 'closed');  Flash::success(__('round_closed_msg')); })(),
            'delete'   => (function () use ($roundId) { Round::delete($roundId); Flash::success(__('round_deleted')); })(),
        };
    }

    if ($action === 'update_cat_adv' && $roundId) {
        $catId   = (int) ($_POST['category_id'] ?? 0);
        $mode    = $_POST['advancement_mode'] ?? 'manual';
        $count   = strlen($_POST['advancement_count'] ?? '') ? (int) $_POST['advancement_count'] : null;
        if (!in_array($mode, ['auto','all','none','manual'], true)) $mode = 'manual';
        if ($catId) {
            Database::get()->prepare(
                'UPDATE round_category_map SET advancement_mode=?, advancement_count=? WHERE round_id=? AND category_id=?'
            )->execute([$mode, $count, $roundId, $catId]);
            Flash::success(__('advancement_updated'));
        }
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

$catTermS       = CatTerm::label($event, 's');
$catTermP       = CatTerm::label($event, 'p');
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
            <span uk-icon="icon:settings;ratio:.85"></span> <?= __('event_settings_btn') ?>
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
            <div class="e-stat-label"><?= $catTermP ?></div>
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
            <div class="e-stat-label"><?= __('total_votes') ?></div>
        </div>
    </div>
</div>

<!-- Public link strip (full width, always visible) -->
<?php $isLive = ($event['status'] === 'active'); ?>
<div class="e-publink-strip<?= $isLive ? ' e-publink-live' : '' ?> uk-margin-bottom">
    <?php if ($isLive): ?>
    <span class="e-publink-dot"></span>
    <span style="font-size:.72rem;font-weight:700;color:#1a7a3c;white-space:nowrap"><?= __('voting_open') ?></span>
    <?php else: ?>
    <span uk-icon="icon:link;ratio:.8" style="color:#9a94b8;flex-shrink:0"></span>
    <?php endif ?>
    <code class="e-publink-url"><?= htmlspecialchars($voteUrl) ?></code>
    <?php if (!$isLive): ?>
    <span style="font-size:.72rem;color:#e67e22;white-space:nowrap"><?= __('activate_to_enable') ?></span>
    <?php endif ?>
    <div style="display:flex;gap:6px;flex-shrink:0;margin-left:auto">
        <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($voteUrl, ENT_QUOTES) ?>');this.innerHTML='&#10003; <?= __('copied_btn') ?>';setTimeout(()=>this.innerHTML='<span uk-icon=\'icon:copy;ratio:.75\'></span> <?= __('copy_btn') ?>',1800)"
                class="uk-button uk-button-default uk-button-small">
            <span uk-icon="icon:copy;ratio:.75"></span> <?= __('copy_btn') ?>
        </button>
        <a href="<?= htmlspecialchars($voteUrl) ?>" target="_blank"
           class="uk-button uk-button-default uk-button-small" title="<?= __('open_newtab') ?>">
            <span uk-icon="icon:forward;ratio:.75"></span>
        </a>
    </div>
</div>

<?php if ($pendingDedup > 0): ?>
<div class="uk-alert-warning" uk-alert style="margin-bottom:20px">
    <p>
        <span uk-icon="warning"></span>
        <?= str_replace(':count', (string)$pendingDedup, __('pending_dedup_alert')) ?>
        <a href="/admin/results.php?round_id=<?= $rounds[0]['id'] ?? 0 ?>&tab=review" style="font-weight:600">
            &rarr;
        </a>
    </p>
</div>
<?php endif ?>

<!-- Pipeline -->
<?php if (empty($rounds)): ?>
<div class="e-card uk-text-center" style="padding:60px">
    <span uk-icon="icon:list;ratio:2" style="color:#c8c3e0"></span>
    <p style="color:#9a94b8;margin-top:12px"><?= __('no_rounds_yet') ?></p>
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
    $stepLabels = [
        __('event_status_draft'),
        __('step_in_progress'),
        __('event_status_closed'),
        __('step_validated'),
        __('step_published'),
    ];

    // Date-vs-status warning
    $now      = time();
    $opensTs  = $round['opens_at']  ? strtotime($round['opens_at'])  : 0;
    $closesTs = $round['closes_at'] ? strtotime($round['closes_at']) : PHP_INT_MAX;
    $dateWarn = '';
    if ($round['status'] === 'active' && $opensTs > $now)   $dateWarn = 'future';
    if ($round['status'] === 'active' && $closesTs < $now)  $dateWarn = 'expired';

    // Display step: override "active" to show "pending" when dates are in the future
    $displayStep = $step;
    if ($dateWarn === 'future') $displayStep = -1; // special: scheduled/pending

    // Advancement dot colours
    $dotColor = ['auto'=>'#27ae60','all'=>'#27ae60','none'=>'#bbb','manual'=>'#6b52d4'];
?>
<!-- Phase block -->
<div class="e-pipeline-block e-card">

    <!-- Round header -->
    <div class="uk-flex uk-flex-between uk-flex-top" style="gap:12px">
        <div class="uk-flex uk-flex-middle" style="gap:10px">
            <div class="e-pipeline-number"><?= $round['round_number'] ?></div>
            <div>
                <div class="uk-flex uk-flex-middle" style="gap:6px;flex-wrap:wrap">
                    <h3 style="margin:0;font-size:.95rem;font-weight:700;color:var(--e-text)">
                        <?= $round['label'] ? htmlspecialchars($round['label']) : __('round_number') . $round['round_number'] ?>
                    </h3>
                    <?php if ($round['status'] === 'active' && $event['status'] === 'active' && $dateWarn === ''): ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:700;color:#1a7a3c;background:#e6f9ee;padding:2px 8px;border-radius:20px">
                        <span style="width:6px;height:6px;border-radius:50%;background:#1a7a3c;animation:e-pulse 1.4s ease-in-out infinite"></span>
                        Live
                    </span>
                    <?php elseif ($dateWarn === 'future'): ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:600;color:#a05800;background:#fdf2e3;padding:2px 8px;border-radius:20px">
                        <span uk-icon="icon:clock;ratio:.7"></span> <?= __('opens_on') ?> <?= date('d/m', $opensTs) ?>
                    </span>
                    <?php elseif ($dateWarn === 'expired'): ?>
                    <span style="font-size:.68rem;font-weight:600;color:#c0392b;background:#fde8e8;padding:2px 8px;border-radius:20px">
                        ⚠ <?= __('dates_expired') ?>
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
            <?php if ($displayStep === -1): ?>
            <!-- Scheduled/pending state: shown between Bozza and In corso -->
            <span class="e-stepper-dot e-step-done" title="<?= __('event_status_draft') ?>"></span>
            <span class="e-stepper-sep"></span>
            <span class="e-stepper-dot e-step-pending" title="<?= __('step_pending') ?>">
                <span class="e-stepper-label e-stepper-label-pending"><?= __('step_pending') ?></span>
            </span>
            <span class="e-stepper-sep"></span>
            <span class="e-stepper-dot e-step-future" title="<?= __('step_in_progress') ?>"></span>
            <span class="e-stepper-sep"></span>
            <span class="e-stepper-dot e-step-future" title="<?= __('event_status_closed') ?>"></span>
            <span class="e-stepper-sep"></span>
            <span class="e-stepper-dot e-step-future" title="<?= __('step_validated') ?>"></span>
            <?php else: ?>
            <?php foreach ($stepLabels as $s => $lbl):
                $done    = $displayStep > $s;
                $current = $displayStep === $s;
            ?>
            <?php if ($s > 0): ?><span class="e-stepper-sep"></span><?php endif ?>
            <span class="e-stepper-dot <?= $done ? 'e-step-done' : ($current ? 'e-step-current' : 'e-step-future') ?>"
                  title="<?= $lbl ?>">
                <?php if ($current): ?><span class="e-stepper-label"><?= $lbl ?></span><?php endif ?>
            </span>
            <?php endforeach ?>
            <?php endif ?>
        </div>
    </div>

    <!-- Categories — compact 3-col grid with popover for advancement settings -->
    <?php if (!empty($roundCats)): ?>
    <div class="e-cats-grid">
        <?php foreach ($roundCats as $cat):
            $mode     = $cat['advancement_mode'] ?? 'manual';
            $cnt      = $cat['advancement_count'] ?? null;
            $advDesc  = match($mode) {
                'auto'   => str_replace(':n', (string)($cnt ?? '?'), __('advancement_desc_auto')),
                'all'    => __('advancement_desc_all'),
                'none'   => __('advancement_desc_none'),
                default  => __('advancement_desc_manual'),
            };
            $dc  = $dotColor[$mode] ?? '#bbb';
            $pid = 'pop-' . $round['id'] . '-' . $cat['id'];
        ?>
        <div class="e-cat-chip">
            <span class="e-cat-dot" style="background:<?= $dc ?>"></span>
            <span class="e-cat-chip-name"><?= htmlspecialchars($cat['name']) ?></span>
            <!-- Popover trigger -->
            <div class="uk-inline" style="margin-left:auto;flex-shrink:0">
                <button type="button" class="e-cat-pop-btn" title="<?= __('advancement_settings') ?>">⋯</button>
                <div uk-drop="mode:click;pos:bottom-right;offset:6;boundary:.e-pipeline-block"
                     class="e-cat-popover uk-drop">
                    <div class="e-cat-pop-inner">
                        <div class="e-cat-pop-title"><?= htmlspecialchars($cat['name']) ?></div>
                        <div class="e-cat-pop-desc"><?= htmlspecialchars($advDesc) ?></div>
                        <?php if (!$isLast): ?>
                        <form method="post" style="margin-top:10px">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="_action"     value="update_cat_adv">
                            <input type="hidden" name="round_id"   value="<?= $round['id'] ?>">
                            <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                            <label style="font-size:.72rem;font-weight:600;color:#6b6494;display:block;margin-bottom:4px"><?= __('advancement_next_round') ?></label>
                            <select name="advancement_mode" class="uk-select uk-form-small"
                                    onchange="this.nextElementSibling.style.display=this.value==='auto'?'flex':'none'">
                                <?php foreach ([
                                    'auto'   => __('advancement_top_n'),
                                    'all'    => __('advancement_all'),
                                    'none'   => __('advancement_none'),
                                    'manual' => __('advancement_manual'),
                                ] as $mv => $ml): ?>
                                <option value="<?= $mv ?>" <?= $mode === $mv ? 'selected' : '' ?>><?= $ml ?></option>
                                <?php endforeach ?>
                            </select>
                            <div style="display:<?= $mode === 'auto' ? 'flex' : 'none' ?>;align-items:center;gap:6px;margin-top:6px">
                                <label style="font-size:.72rem;color:#6b6494;white-space:nowrap"><?= __('top_label') ?></label>
                                <input type="number" name="advancement_count"
                                       value="<?= htmlspecialchars((string)($cnt ?? '')) ?>"
                                       min="1" class="uk-input uk-form-small" style="width:60px">
                                <label style="font-size:.72rem;color:#6b6494;white-space:nowrap"><?= __('candidates_lc') ?></label>
                            </div>
                            <button type="submit" class="uk-button uk-button-primary uk-button-small" style="margin-top:8px;width:100%">
                                <?= __('save') ?>
                            </button>
                        </form>
                        <?php else: ?>
                        <p style="font-size:.75rem;color:#9a94b8;margin:8px 0 0;font-style:italic"><?= __('last_round_note') ?></p>
                        <?php endif ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>

    <!-- Actions -->
    <div class="e-round-actions">
        <!-- Primary contextual action -->
        <?php if ($round['status'] === 'active'): ?>
        <a href="/admin/candidates.php?round_id=<?= $round['id'] ?>"
           class="uk-button uk-button-primary uk-button-small">
            <span uk-icon="icon:list;ratio:.75"></span> <?= __('candidates_title') ?>
        </a>
        <?php elseif ($round['status'] === 'closed'): ?>
        <a href="/admin/results.php?round_id=<?= $round['id'] ?>"
           class="uk-button uk-button-primary uk-button-small">
            <span uk-icon="icon:bar-chart;ratio:.75"></span> <?= __('results_title') ?><?= $round['results_released'] ? ' ✓' : '' ?>
        </a>
        <?php else: ?>
        <a href="/admin/candidates.php?round_id=<?= $round['id'] ?>"
           class="uk-button uk-button-default uk-button-small"><?= __('candidates_title') ?></a>
        <?php endif ?>

        <!-- Secondary actions -->
        <?php if ($round['status'] !== 'closed'): ?>
        <a href="/admin/results.php?round_id=<?= $round['id'] ?>"
           class="uk-button uk-button-default uk-button-small"><?= __('results_title') ?></a>
        <?php elseif ($round['status'] !== 'active'): ?>
        <a href="/admin/candidates.php?round_id=<?= $round['id'] ?>"
           class="uk-button uk-button-default uk-button-small"><?= __('candidates_title') ?></a>
        <?php endif ?>
        <a href="/admin/rounds-edit.php?id=<?= $round['id'] ?>&event_id=<?= $eventId ?>"
           class="uk-button uk-button-default uk-button-small">
            <span uk-icon="icon:pencil;ratio:.75"></span> <?= __('edit') ?>
        </a>

        <!-- Lifecycle action -->
        <?php if (in_array($round['status'], ['draft','active'], true)): ?>
        <form method="post" style="display:inline;margin:0">
            <?= Csrf::field() ?>
            <input type="hidden" name="round_id" value="<?= $round['id'] ?>">
            <?php if ($round['status'] === 'draft'): ?>
            <input type="hidden" name="_action" value="activate">
            <button class="uk-button uk-button-primary uk-button-small">
                <span uk-icon="icon:play;ratio:.75"></span> <?= __('round_activate') ?>
            </button>
            <?php else: ?>
            <input type="hidden" name="_action" value="close">
            <button class="uk-button uk-button-small e-btn-danger">
                <span uk-icon="icon:ban;ratio:.75"></span> <?= __('round_close_btn') ?>
            </button>
            <?php endif ?>
        </form>
        <?php endif ?>
    </div>

    <!-- Delete — absolute bottom-right of card -->
    <form method="post" class="e-round-delete">
        <?= Csrf::field() ?>
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="round_id" value="<?= $round['id'] ?>">
        <button type="button"
                onclick="if(confirm('<?= htmlspecialchars(__('confirm_delete')) ?>')) this.closest('form').submit()"
                class="e-round-delete-btn" title="Elimina turno">
            <span uk-icon="icon:trash;ratio:.85"></span>
        </button>
    </form>

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
        <span uk-icon="plus-circle"></span> <?= __('add_round') ?>
    </a>
</div>


</div><!-- /e-pipeline -->
<?php endif ?>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
