<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Models\Candidate;
use Electus\Models\Category;
use Electus\Models\Deduplication;
use Electus\Models\Event;
use Electus\Models\Round;

$roundId = (int) ($_GET['round_id'] ?? 0);
$round   = $roundId ? Round::find($roundId) : null;
if (!$round || $round['model'] !== 'open') {
    Flash::error('Deduplication is only available for open rounds.');
    header('Location: /admin/events.php');
    exit;
}

$eventId = (int) $round['event_id'];
$event   = Event::find($eventId);
Auth::requireEventPermission($eventId);

$tab = $_GET['tab'] ?? 'queue';

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $action  = $_POST['_action'] ?? '';
    $user    = Auth::currentUser();
    $queueId = (int) ($_POST['queue_id'] ?? 0);

    if ($action === 'merge' && $queueId) {
        $sourceId   = (int) ($_POST['source_candidate_id'] ?? 0);
        $targetId   = (int) ($_POST['target_candidate_id'] ?? 0);
        $canonical  = trim($_POST['canonical_override'] ?? '');

        if ($sourceId && $targetId) {
            try {
                Deduplication::merge($queueId, $sourceId, $targetId, $user['id'], $canonical);
                Flash::success(__('candidates_merged_alias'));
            } catch (\Throwable $e) {
                Flash::error(__('error_prefix') . $e->getMessage());
            }
        }
    }

    if ($action === 'keep' && $queueId) {
        Deduplication::keep($queueId, $user['id']);
        Flash::success(__('candidate_kept_separate'));
    }

    if ($action === 'exclude' && $queueId) {
        $candidateId = (int) ($_POST['candidate_id'] ?? 0);
        if ($candidateId) {
            Deduplication::exclude($queueId, $candidateId, $user['id']);
            Flash::success(__('candidate_excluded'));
        }
    }

    if ($action === 'delete_alias') {
        $aliasId = (int) ($_POST['alias_id'] ?? 0);
        if ($aliasId) {
            Deduplication::deleteAlias($aliasId);
            Flash::success(__('alias_deleted'));
        }
    }

    header('Location: /admin/dedup.php?round_id=' . $roundId . '&tab=' . $tab);
    exit;
}

$queue          = Deduplication::getPendingQueue($roundId);
$reviewedCount  = Deduplication::getReviewedCount($roundId);
$aliases        = Deduplication::getAliasDictionary($eventId);
$categories     = Category::forEvent($eventId);

// All active candidates for the "pick target" dropdown, grouped by category
$allCandidates = Candidate::forRound($roundId);
$candByCategory = [];
foreach ($allCandidates as $c) {
    $candByCategory[$c['category_id']][] = $c;
}

$pageTitle      = __('dedup_title');
$activeMenu     = 'rounds';
$currentEventId = $eventId;

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
            </p>
            <h1 class="e-page-title uk-margin-remove"><?= __('dedup_title') ?></h1>
        </div>
    </div>
    <div style="text-align:right;font-size:.85rem;color:#9a94b8">
        <strong style="color:var(--e-primary)"><?= count($queue) ?></strong> <?= __('pending_review_label') ?>
        &nbsp;·&nbsp;
        <strong style="color:#27ae60"><?= $reviewedCount ?></strong> <?= __('reviewed_label') ?>
    </div>
</div>

<!-- Tabs -->
<ul class="uk-tab uk-margin-bottom" uk-tab>
    <li class="<?= $tab === 'queue' ? 'uk-active' : '' ?>">
        <a href="/admin/dedup.php?round_id=<?= $roundId ?>&tab=queue">
            <?= __('dedup_tab') ?>
            <?php if (count($queue) > 0): ?>
            <span class="uk-badge" style="background:var(--e-primary)"><?= count($queue) ?></span>
            <?php endif ?>
        </a>
    </li>
    <li class="<?= $tab === 'aliases' ? 'uk-active' : '' ?>">
        <a href="/admin/dedup.php?round_id=<?= $roundId ?>&tab=aliases">
            <?= __('alias_dict_title') ?>
            <span class="uk-badge" style="background:#9a94b8"><?= count($aliases) ?></span>
        </a>
    </li>
</ul>

<?php if ($tab === 'queue'): ?>

<?php if (empty($queue)): ?>
<div class="e-card uk-text-center" style="padding:60px">
    <span uk-icon="icon:check;ratio:2" style="color:#27ae60"></span>
    <p style="color:#9a94b8;margin-top:12px">No pending items in the deduplication queue.</p>
</div>
<?php else: ?>

<div class="uk-alert-primary" uk-alert>
    <p>Review these candidates that may be duplicate entries.
       <strong>Merge</strong> to combine them into one, <strong>Keep</strong> to confirm they are distinct,
       or <strong>Exclude</strong> to remove them from results.</p>
</div>

<?php
$currentCat = null;
foreach ($queue as $item):
    if ($item['category_id'] !== $currentCat):
        if ($currentCat !== null) echo '</div>'; // close prev group
        $currentCat = $item['category_id'];
?>
<h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--e-accent);margin:24px 0 8px">
    <?= htmlspecialchars($item['category_name']) ?>
</h3>
<div class="uk-grid-small" uk-grid>
<?php endif ?>

    <div class="uk-width-1-1">
    <div class="e-card" style="border-left:4px solid <?= $item['similarity_score'] >= 85 ? '#e74c3c' : ($item['similarity_score'] >= 70 ? '#f39c12' : '#9a94b8') ?>">
        <div class="uk-grid-small" uk-grid>

            <!-- Left: the duplicate entry -->
            <div class="uk-width-1-3@m">
                <p style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:#9a94b8;margin:0 0 4px"><?= __('dedup_raw') ?></p>
                <p style="font-size:1rem;font-weight:700;margin:0 0 2px"><?= htmlspecialchars($item['new_cand_name'] ?? $item['raw_input']) ?></p>
                <p style="font-size:.8rem;color:#9a94b8;margin:0">
                    <?= __('normalized_label') ?>: <code><?= htmlspecialchars($item['normalized_input']) ?></code>
                </p>
            </div>

            <!-- Middle: similarity score -->
            <div class="uk-width-1-6@m uk-text-center uk-flex uk-flex-middle uk-flex-center">
                <div>
                    <div style="font-size:1.4rem;font-weight:800;color:<?= $item['similarity_score'] >= 85 ? '#e74c3c' : ($item['similarity_score'] >= 70 ? '#f39c12' : '#6b6494') ?>">
                        <?= $item['similarity_score'] ?>%
                    </div>
                    <div style="font-size:.7rem;color:#9a94b8"><?= __('dedup_score') ?></div>
                </div>
            </div>

            <!-- Right: suggested match + actions -->
            <div class="uk-width-1-2@m">
                <p style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:#9a94b8;margin:0 0 4px">
                    <?= __('dedup_suggestion') ?>
                </p>
                <?php if ($item['suggested_name']): ?>
                <p style="font-size:1rem;font-weight:600;margin:0 0 12px;color:var(--e-primary)">
                    <?= htmlspecialchars($item['suggested_name']) ?>
                </p>
                <?php else: ?>
                <p style="color:#9a94b8;margin:0 0 12px;font-size:.875rem"><?= __('no_suggestion') ?></p>
                <?php endif ?>

                <!-- Merge form -->
                <form method="post" style="display:inline-block;margin-right:8px">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="_action" value="merge">
                    <input type="hidden" name="queue_id" value="<?= $item['id'] ?>">
                    <input type="hidden" name="source_candidate_id" value="<?= $item['new_cand_id'] ?>">

                    <div class="uk-margin-small-bottom">
                        <select name="target_candidate_id" class="uk-select uk-form-small" style="width:220px">
                            <?php foreach ($candByCategory[$item['category_id']] ?? [] as $c):
                                if ($c['id'] == $item['new_cand_id']) continue; ?>
                            <option value="<?= $c['id'] ?>"
                                <?= $c['id'] == $item['suggested_candidate_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="uk-margin-small-bottom">
                        <input class="uk-input uk-form-small" type="text" name="canonical_override"
                               placeholder="Custom canonical name (optional)"
                               style="width:220px">
                    </div>
                    <button class="uk-button uk-button-primary uk-button-small" type="submit">
                        <span uk-icon="icon:link;ratio:.8"></span> <?= __('dedup_merge') ?>
                    </button>
                </form>

                <!-- Keep form -->
                <form method="post" style="display:inline-block;margin-right:4px">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="_action" value="keep">
                    <input type="hidden" name="queue_id" value="<?= $item['id'] ?>">
                    <button class="uk-button uk-button-default uk-button-small">
                        <?= __('dedup_keep') ?>
                    </button>
                </form>

                <!-- Exclude form -->
                <form method="post" style="display:inline-block">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="_action" value="exclude">
                    <input type="hidden" name="queue_id" value="<?= $item['id'] ?>">
                    <input type="hidden" name="candidate_id" value="<?= $item['new_cand_id'] ?>">
                    <button class="uk-button uk-button-link uk-button-small"
                            data-confirm="<?= htmlspecialchars(__('exclude_candidate_confirm')) ?>"
                            style="color:#e74c3c">
                        <?= __('dedup_exclude') ?>
                    </button>
                </form>
            </div>

        </div>
    </div>
    </div>

<?php endforeach ?>
<?php if ($currentCat !== null) echo '</div>'; ?>

<?php endif ?>

<?php else: // tab = aliases ?>

<p style="color:#9a94b8;font-size:.875rem;margin-bottom:16px">
    These aliases are automatically applied to future editions of this event.
    Any normalized input matching an alias is instantly resolved to the canonical name.
</p>

<?php if (empty($aliases)): ?>
<div class="e-card uk-text-center" style="padding:40px">
    <p style="color:#9a94b8">No aliases saved yet. They are created when you merge candidates.</p>
</div>
<?php else: ?>
<div class="e-table">
    <table class="uk-table uk-table-hover uk-table-divider uk-margin-remove">
        <thead>
            <tr>
                <th><?= __('categories_title') ?></th>
                <th><?= __('alias_normalized_col') ?></th>
                <th><?= __('canonical_name_col') ?></th>
                <th><?= __('created_by_col') ?></th>
                <th style="width:60px"></th>
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
                        <button uk-icon="icon:trash;ratio:.8"
                                data-confirm="<?= htmlspecialchars(__('alias_delete_confirm')) ?>"
                                style="color:#e74c3c;background:none;border:none;cursor:pointer;padding:0"
                                class="uk-icon-link"></button>
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
