<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Database;
use Electus\Core\Flash;
use Electus\Models\Event;

$eventId = (int) ($_GET['event_id'] ?? 0);
if (!$eventId) { header('Location: /admin/events.php'); exit; }

$event = Event::find($eventId);
if (!$event) { Flash::error('Event not found.'); header('Location: /admin/events.php'); exit; }

Auth::requireEventPermission($eventId);

$pdo = Database::get();

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'delete' && Auth::hasRole('superadmin', 'event_manager')) {
        $voterId = (int) ($_POST['voter_id'] ?? 0);
        if ($voterId) {
            $pdo->prepare('DELETE FROM voter_lists WHERE id = ? AND event_id = ?')
                ->execute([$voterId, $eventId]);
            Flash::success(__('voter_deleted'));
        }
    }

    if ($action === 'toggle_approved') {
        $voterId = (int) ($_POST['voter_id'] ?? 0);
        $val     = (int) ($_POST['approved'] ?? 0);
        if ($voterId) {
            $pdo->prepare('UPDATE voter_lists SET approved = ? WHERE id = ? AND event_id = ?')
                ->execute([$val, $voterId, $eventId]);
            Flash::success($val ? __('voter_approved') : __('voter_suspended'));
        }
    }

    if ($action === 'approve_request') {
        $reqId = (int) ($_POST['request_id'] ?? 0);
        if ($reqId) {
            $req = $pdo->prepare("SELECT * FROM voter_registration_requests WHERE id = ? AND event_id = ? AND status = 'pending'");
            $req->execute([$reqId, $eventId]);
            $reqRow = $req->fetch();
            if ($reqRow) {
                $token = bin2hex(random_bytes(32));
                $pdo->prepare(
                    "INSERT IGNORE INTO voter_lists (event_id, email, name, token, source, approved)
                     VALUES (?, ?, ?, ?, 'self_registered', 1)"
                )->execute([$eventId, $reqRow['email'], $reqRow['name'], $token]);
                $pdo->prepare(
                    "UPDATE voter_registration_requests SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?"
                )->execute([(int) Auth::currentUser()['id'], $reqId]);
                Flash::success(__('voter_request_approved'));
            }
        }
    }

    if ($action === 'reject_request') {
        $reqId = (int) ($_POST['request_id'] ?? 0);
        if ($reqId) {
            $pdo->prepare(
                "UPDATE voter_registration_requests SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ? WHERE id = ? AND event_id = ?"
            )->execute([(int) Auth::currentUser()['id'], $reqId, $eventId]);
            Flash::success(__('voter_request_rejected'));
        }
    }

    header('Location: /admin/voters.php?event_id=' . $eventId);
    exit;
}

// Stats
$stmt = $pdo->prepare('SELECT COUNT(*) FROM voter_lists WHERE event_id = ?');
$stmt->execute([$eventId]);
$totalVoters = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM voter_lists WHERE event_id = ? AND token_used = 1');
$stmt->execute([$eventId]);
$voted = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM voter_lists WHERE event_id = ? AND approved = 0');
$stmt->execute([$eventId]);
$suspended = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM voter_registration_requests WHERE event_id = ? AND status = 'pending'"
);
$stmt->execute([$eventId]);
$pendingRequests = (int) $stmt->fetchColumn();

// Voter list (paginated in future; full list for now)
$stmt = $pdo->prepare(
    'SELECT * FROM voter_lists WHERE event_id = ? ORDER BY created_at DESC'
);
$stmt->execute([$eventId]);
$voters = $stmt->fetchAll();

// Pending registration requests
$stmt = $pdo->prepare(
    "SELECT * FROM voter_registration_requests WHERE event_id = ? AND status = 'pending' ORDER BY requested_at ASC"
);
$stmt->execute([$eventId]);
$requests = $stmt->fetchAll();

$participation = $totalVoters > 0 ? round($voted / $totalVoters * 100) : 0;

$pageTitle      = __('voters_title');
$activeMenu     = 'voters';
$currentEventId = $eventId;

ob_start();
?>
<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom">
    <div class="uk-flex uk-flex-middle" style="gap:12px">
        <a href="/admin/rounds.php?event_id=<?= $eventId ?>" uk-icon="arrow-left" class="uk-icon-link"></a>
        <div>
            <p style="margin:0;font-size:.8rem;color:#9a94b8"><?= htmlspecialchars($event['name']) ?></p>
            <h1 class="e-page-title uk-margin-remove"><?= __('voters_title') ?></h1>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="uk-grid-small uk-margin-bottom" uk-grid>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value"><?= $totalVoters ?></div>
            <div class="e-stat-label"><?= __('stat_registered') ?></div>
        </div>
    </div>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value" style="color:var(--e-primary)"><?= $voted ?></div>
            <div class="e-stat-label"><?= __('stat_voted') ?></div>
        </div>
    </div>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value" style="color:<?= $participation >= 50 ? '#27ae60' : '#e67e22' ?>">
                <?= $participation ?>%
            </div>
            <div class="e-stat-label"><?= __('participation') ?></div>
        </div>
    </div>
    <?php if ($suspended > 0): ?>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value" style="color:#e74c3c"><?= $suspended ?></div>
            <div class="e-stat-label"><?= __('stat_suspended') ?></div>
        </div>
    </div>
    <?php endif ?>
    <?php if ($pendingRequests > 0): ?>
    <div class="uk-width-auto">
        <div class="e-stat" style="min-width:120px">
            <div class="e-stat-value" style="color:#e67e22"><?= $pendingRequests ?></div>
            <div class="e-stat-label"><?= __('pending_requests') ?></div>
        </div>
    </div>
    <?php endif ?>
</div>

<?php if ($pendingRequests > 0): ?>
<!-- Pending registration requests -->
<div class="e-card uk-margin-bottom">
    <h3 style="font-size:.9rem;font-weight:700;margin-bottom:12px;color:#e67e22">
        <span uk-icon="warning"></span> <?= __('pending_registrations_title') ?> (<?= $pendingRequests ?>)
    </h3>
    <table class="uk-table uk-table-small uk-table-divider uk-margin-remove">
        <thead>
            <tr>
                <th><?= __('name') ?></th>
                <th><?= __('email') ?></th>
                <th><?= __('request_date') ?></th>
                <th style="width:140px"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($requests as $req): ?>
            <tr>
                <td><?= htmlspecialchars($req['name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($req['email']) ?></td>
                <td style="color:#9a94b8;font-size:.8rem">
                    <?= date('d/m/Y H:i', strtotime($req['requested_at'])) ?>
                </td>
                <td>
                    <div class="uk-flex" style="gap:6px">
                        <form method="post" style="display:inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="_action" value="approve_request">
                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                            <button class="uk-button uk-button-primary uk-button-small"><?= __('approve') ?></button>
                        </form>
                        <form method="post" style="display:inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="_action" value="reject_request">
                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                            <button class="uk-button uk-button-small"
                                    style="background:#e74c3c;color:#fff;border-color:#e74c3c"><?= __('reject') ?></button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>

<!-- Voter list -->
<?php if (empty($voters)): ?>
<div class="e-card uk-text-center" style="padding:60px">
    <span uk-icon="icon:users;ratio:2" style="color:#c8c3e0"></span>
    <p style="color:#9a94b8;margin-top:12px"><?= __('no_voters_yet') ?></p>
    <?php if ($event['access_mode'] === 'closed_list'): ?>
    <p style="color:#9a94b8;font-size:.875rem"><?= __('voters_closed_list_hint') ?></p>
    <?php else: ?>
    <p style="color:#9a94b8;font-size:.875rem"><?= __('voters_register_hint') ?></p>
    <?php endif ?>
</div>
<?php else: ?>
<div class="e-table">
    <table class="uk-table uk-table-hover uk-table-divider uk-margin-remove" style="font-size:.875rem">
        <thead>
            <tr>
                <th><?= __('name') ?></th>
                <th><?= __('email') ?></th>
                <th><?= __('status') ?></th>
                <th>Token</th>
                <th><?= __('created_at') ?></th>
                <th style="width:80px"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($voters as $v): ?>
            <tr>
                <td><?= htmlspecialchars($v['name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($v['email']) ?></td>
                <td>
                    <?php if (!$v['approved']): ?>
                    <span class="e-badge e-badge-closed"><?= __('status_suspended') ?></span>
                    <?php elseif ($v['token_used']): ?>
                    <span class="e-badge e-badge-active"><?= __('status_voted') ?></span>
                    <?php else: ?>
                    <span class="e-badge e-badge-draft"><?= __('status_not_voted') ?></span>
                    <?php endif ?>
                </td>
                <td style="color:#9a94b8;font-family:monospace;font-size:.75rem">
                    <?= $v['source'] === 'preloaded' ? substr($v['token'], 0, 12) . '…' : '—' ?>
                </td>
                <td style="color:#9a94b8;font-size:.8rem">
                    <?= date('d/m/Y', strtotime($v['created_at'])) ?>
                    <?php if ($v['source'] === 'self_registered'): ?>
                    <span class="e-badge" style="background:#f0eeff;color:#6b52d4;font-size:.65rem">self</span>
                    <?php endif ?>
                </td>
                <td>
                    <div class="uk-flex" style="gap:4px">
                        <?php if ($v['approved']): ?>
                        <form method="post" style="display:inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="_action" value="toggle_approved">
                            <input type="hidden" name="voter_id" value="<?= $v['id'] ?>">
                            <input type="hidden" name="approved" value="0">
                            <button uk-icon="icon:ban;ratio:.8" uk-tooltip="<?= __('suspend') ?>"
                                    style="color:#e67e22;background:none;border:none;cursor:pointer;padding:0"
                                    class="uk-icon-link"></button>
                        </form>
                        <?php else: ?>
                        <form method="post" style="display:inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="_action" value="toggle_approved">
                            <input type="hidden" name="voter_id" value="<?= $v['id'] ?>">
                            <input type="hidden" name="approved" value="1">
                            <button uk-icon="icon:check;ratio:.8" uk-tooltip="<?= __('approve') ?>"
                                    style="color:#27ae60;background:none;border:none;cursor:pointer;padding:0"
                                    class="uk-icon-link"></button>
                        </form>
                        <?php endif ?>
                        <?php if (!$v['token_used'] && Auth::hasRole('superadmin', 'event_manager')): ?>
                        <form method="post" style="display:inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="_action" value="delete">
                            <input type="hidden" name="voter_id" value="<?= $v['id'] ?>">
                            <button uk-icon="icon:trash;ratio:.8" uk-tooltip="<?= __('remove') ?>"
                                    data-confirm="<?= htmlspecialchars(__('confirm_delete')) ?>"
                                    style="color:#e74c3c;background:none;border:none;cursor:pointer;padding:0"
                                    class="uk-icon-link"></button>
                        </form>
                        <?php endif ?>
                    </div>
                </td>
            </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
