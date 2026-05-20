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

    if ($action === 'merge_group') {
        $targetId  = (int) ($_POST['target_candidate_id'] ?? 0);
        $canonical = trim($_POST['canonical_override'] ?? '');
        $items = [];
        foreach ($_POST['items'] ?? [] as $v) {
            [$qid, $cid] = explode(':', $v, 2);
            $items[] = ['queue_id' => (int)$qid, 'source_candidate_id' => (int)$cid];
        }
        if ($targetId && !empty($items)) {
            try {
                Deduplication::mergeGroup($items, $targetId, $user['id'], $canonical);
                Flash::success(__('candidates_merged_alias'));
            } catch (\Throwable $e) {
                Flash::error(__('error_prefix') . $e->getMessage());
            }
        }
    }

    if ($action === 'keep_group') {
        $queueIds = array_map('intval', $_POST['queue_ids'] ?? []);
        if (!empty($queueIds)) {
            Deduplication::keepGroup($queueIds, $user['id']);
            Flash::success(__('candidate_kept_separate'));
        }
    }

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

    if ($action === 'merge_manual') {
        $cids      = array_filter(array_map('intval', $_POST['cids'] ?? []));
        $canonical = trim($_POST['canonical_override'] ?? '');
        if (count($cids) >= 2) {
            try {
                Deduplication::mergeManual(array_values($cids), $user['id'], $canonical);
                Flash::success(__('candidates_merged_alias'));
            } catch (\Throwable $e) {
                Flash::error(__('error_prefix') . $e->getMessage());
            }
        }
    }

    header('Location: /admin/dedup.php?round_id=' . $roundId . '&tab=' . $tab);
    exit;
}

$groups         = Deduplication::getPendingQueueGrouped($roundId);
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
        <strong style="color:var(--e-primary)"><?= count($groups) ?></strong> <?= __('pending_review_label') ?>
        &nbsp;·&nbsp;
        <strong style="color:#27ae60"><?= $reviewedCount ?></strong> <?= __('reviewed_label') ?>
    </div>
</div>

<!-- Tabs -->
<ul class="uk-tab uk-margin-bottom" uk-tab>
    <li class="<?= $tab === 'queue' ? 'uk-active' : '' ?>">
        <a href="/admin/dedup.php?round_id=<?= $roundId ?>&tab=queue">
            <?= __('dedup_tab') ?>
            <?php if (count($groups) > 0): ?>
            <span class="uk-badge" style="background:var(--e-primary)"><?= count($groups) ?></span>
            <?php endif ?>
        </a>
    </li>
    <li class="<?= $tab === 'browse' ? 'uk-active' : '' ?>">
        <a href="/admin/dedup.php?round_id=<?= $roundId ?>&tab=browse">
            <?= __('dedup_browse_tab') ?>
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

<?php if (empty($groups)): ?>
<div class="e-card uk-text-center" style="padding:60px">
    <span uk-icon="icon:check;ratio:2" style="color:#27ae60"></span>
    <p style="color:#9a94b8;margin-top:12px"><?= __('no_dedup_pending') ?></p>
</div>
<?php else: ?>

<div class="uk-alert-primary" uk-alert>
    <p><?= __('dedup_intro') ?></p>
</div>

<?php
$currentCat = null;
foreach ($groups as $group):
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
                       form="grp-<?= $group['items'][0]['id'] ?>"
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
            <form id="grp-<?= $group['items'][0]['id'] ?>" method="post">
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

            <!-- Keep all -->
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

<?php elseif ($tab === 'browse'): // tab = browse ?>

<p style="color:#9a94b8;font-size:.875rem;margin-bottom:16px"><?= __('dedup_select_hint') ?></p>

<div id="e-browse-merge-bar"
     style="display:none;position:sticky;top:60px;z-index:200;background:var(--e-bg);border:1px solid var(--e-primary);border-radius:6px;padding:10px 16px;margin-bottom:16px;display:none">
    <form method="post" id="e-browse-merge-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="_action" value="merge_manual">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span id="e-browse-sel-count" style="font-size:.875rem;color:var(--e-primary);font-weight:600"></span>
            <input class="uk-input uk-form-small" type="text" name="canonical_override"
                   placeholder="<?= htmlspecialchars(__('dedup_canonical_label')) ?>"
                   style="max-width:260px">
            <button class="uk-button uk-button-primary uk-button-small" id="e-browse-merge-btn" type="submit" disabled>
                <span uk-icon="icon:link;ratio:.8"></span> <?= __('dedup_merge_selected') ?>
            </button>
        </div>
    </form>
</div>

<?php
$catsSorted = $categories;
usort($catsSorted, fn($a, $b) => ($a['sort_order'] ?? 999) <=> ($b['sort_order'] ?? 999) ?: $a['id'] <=> $b['id']);
foreach ($catsSorted as $cat):
    $cands = array_filter($candByCategory[$cat['id']] ?? [], fn($c) => true);
    usort($cands, fn($a, $b) => strcmp($a['name'], $b['name']));
    if (empty($cands)) continue;
?>
<h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--e-accent);margin:24px 0 6px">
    <?= htmlspecialchars($cat['name']) ?>
    <span style="color:#9a94b8;font-weight:400;text-transform:none;letter-spacing:0">(<?= count($cands) ?>)</span>
</h3>
<div class="e-card" style="padding:12px 16px">
<?php foreach ($cands as $c): ?>
    <label style="display:flex;align-items:center;gap:8px;padding:3px 0;font-size:.875rem;cursor:pointer">
        <input type="checkbox" class="e-browse-check"
               data-form="e-browse-merge-form"
               name="cids[]"
               value="<?= $c['id'] ?>">
        <span><?= htmlspecialchars($c['name']) ?></span>
    </label>
<?php endforeach ?>
</div>
<?php endforeach ?>

<script>
(function() {
    const bar   = document.getElementById('e-browse-merge-bar');
    const btn   = document.getElementById('e-browse-merge-btn');
    const cnt   = document.getElementById('e-browse-sel-count');
    const form  = document.getElementById('e-browse-merge-form');

    function refresh() {
        const checked = document.querySelectorAll('.e-browse-check:checked');
        const n = checked.length;
        bar.style.display = n > 0 ? 'block' : 'none';
        btn.disabled = n < 2;
        cnt.textContent = n + ' <?= __('dedup_variants') ?> selezionate';

        // Sync checked values into the form (remove old hidden inputs, add new ones)
        form.querySelectorAll('input[type=hidden][name="cids[]"]').forEach(el => el.remove());
        checked.forEach(cb => {
            const h = document.createElement('input');
            h.type = 'hidden'; h.name = 'cids[]'; h.value = cb.value;
            form.appendChild(h);
        });
    }

    document.addEventListener('change', e => {
        if (e.target.classList.contains('e-browse-check')) refresh();
    });
})();
</script>

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
