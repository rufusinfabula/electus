<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Core\Mailer;
use Electus\Models\Event;
use Electus\Models\Round;
use Electus\Models\VoterList;

$slug  = trim($_GET['slug'] ?? '');
$event = $slug ? Event::findBySlug($slug) : null;

if (!$event || $event['status'] !== 'active') {
    http_response_code(404);
    die('Event not found or not active.');
}

$round = Round::activeForEvent((int) $event['id']);
$errors = [];

// ── Handle registration POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $action = $_POST['_action'] ?? '';

    // Voluntary or mandatory registration
    if ($action === 'register') {
        $email   = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
        $name    = trim($_POST['name'] ?? '');
        $consent = isset($_POST['consent_marketing']);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('vote_your_email') . ': invalid address.';
        } else {
            $existing = VoterList::findByEmail($email, (int) $event['id']);
            if ($existing) {
                // Already registered — resend or proceed
                if ($existing['token_used']) {
                    $errors[] = __('vote_already_voted');
                } elseif ($event['email_verification'] && !$existing['approved']) {
                    Flash::warning(__('vote_verify_sent'));
                    header('Location: /vote/event.php?slug=' . urlencode($slug));
                    exit;
                } else {
                    header('Location: /vote/cast.php?token=' . urlencode($existing['token']));
                    exit;
                }
            } else {
                $approved = !$event['email_verification'];
                $result   = VoterList::register((int) $event['id'], $email, $name, $consent, $approved);

                if ($event['email_verification']) {
                    // Send verification email
                    $cfg      = require ROOT . '/config/config.php';
                    $link     = rtrim($cfg['app']['url'], '/') . '/vote/verify.php?token=' . urlencode($result['token']);
                    $subject  = 'Confirm your vote — ' . $event['name'];
                    $body     = '<p>Click the link below to confirm your email and proceed to vote:</p>'
                              . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';
                    Mailer::send($email, $name, $subject, $body);
                    VoterList::markInvited($result['id']);
                    Flash::success(__('vote_verify_sent'));
                    header('Location: /vote/event.php?slug=' . urlencode($slug));
                    exit;
                } else {
                    header('Location: /vote/cast.php?token=' . urlencode($result['token']));
                    exit;
                }
            }
        }
    }

    // Registration-with-approval request
    if ($action === 'request_registration') {
        $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
        $name  = trim($_POST['name'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } else {
            $pdo  = \Electus\Core\Database::get();
            // Check if already requested
            $stmt = $pdo->prepare("SELECT id, status FROM voter_registration_requests WHERE event_id = ? AND email = ? LIMIT 1");
            $stmt->execute([$event['id'], $email]);
            $req = $stmt->fetch();

            if ($req) {
                if ($req['status'] === 'approved') {
                    $voter = VoterList::findByEmail($email, (int) $event['id']);
                    if ($voter) {
                        header('Location: /vote/cast.php?token=' . urlencode($voter['token']));
                        exit;
                    }
                }
                Flash::warning('Your request is ' . $req['status'] . '. You will receive an email when approved.');
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO voter_registration_requests (event_id, name, email) VALUES (?, ?, ?)"
                );
                $stmt->execute([$event['id'], $name, $email]);
                Flash::success('Your registration request has been submitted. You will receive an email when approved.');
            }
            header('Location: /vote/event.php?slug=' . urlencode($slug));
            exit;
        }
    }
}

$pageTitle = htmlspecialchars($event['name']);
$accessMode = $event['access_mode'];

ob_start();
?>
<div style="max-width:520px;margin:0 auto">

    <?php if (!empty($event['public_logo_url'])): ?>
    <div class="e-public-logo-event">
        <img src="<?= htmlspecialchars($event['public_logo_url']) ?>"
             alt="<?= htmlspecialchars($event['name']) ?>">
    </div>
    <?php endif ?>

    <h1 style="font-size:1.6rem;font-weight:800;color:var(--e-text);margin-bottom:8px">
        <?= htmlspecialchars($event['name']) ?>
    </h1>

    <?php if (!empty($event['description'])): ?>
    <div class="e-public-description"><?= $event['description'] ?></div>
    <?php endif ?>

    <?php if (!empty($event['public_info_box'])): ?>
    <div class="e-public-infobox uk-alert-primary" uk-alert>
        <?= $event['public_info_box'] ?>
    </div>
    <?php endif ?>

    <?php if ($accessMode !== 'anonymous'): ?>
    <p class="e-gdpr-notice">
        <?= __('gdpr_notice') ?>
        <?php if (!empty($event['public_privacy_url'])): ?>
        <a href="<?= htmlspecialchars($event['public_privacy_url']) ?>" target="_blank" rel="noopener">
            <?= __('privacy_policy') ?>
        </a>
        <?php endif ?>
    </p>
    <?php endif ?>

    <?php if (!$round): ?>
    <div class="uk-alert-warning" uk-alert>
        <p><?= __('vote_closed') ?></p>
    </div>

    <?php elseif ($accessMode === 'anonymous'): ?>
    <!-- Anonymous: go straight to cast -->
    <div style="text-align:center;padding:16px 0">
        <a href="/vote/cast.php?event=<?= urlencode($slug) ?>"
           class="uk-button uk-button-primary uk-button-large">
            <?= __('vote_proceed') ?> &rarr;
        </a>
    </div>

    <?php elseif ($accessMode === 'voluntary_registration'): ?>
    <!-- Voluntary: offer choice -->
    <?php if ($errors): ?>
    <div class="uk-alert-danger" uk-alert>
        <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach ?>
    </div>
    <?php endif ?>
    <div class="e-vote-box">
        <h3 style="font-size:1rem;font-weight:600;margin-bottom:16px"><?= __('vote_register') ?></h3>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="register">
            <div class="uk-margin">
                <input class="uk-input" type="email" name="email"
                       placeholder="<?= __('vote_your_email') ?>" required>
            </div>
            <div class="uk-margin">
                <input class="uk-input" type="text" name="name"
                       placeholder="<?= __('vote_your_name') ?>">
            </div>
            <label class="uk-margin-small-bottom" style="display:block">
                <input type="checkbox" name="consent_marketing"> <?= __('vote_consent') ?>
            </label>
            <button class="uk-button uk-button-primary uk-width-1-1 uk-margin-top">
                <?= __('vote_proceed') ?> &rarr;
            </button>
        </form>
    </div>
    <div style="text-align:center;margin-top:16px">
        <a href="/vote/cast.php?event=<?= urlencode($slug) ?>"
           style="color:#9a94b8;font-size:.875rem">
            Vote without registering
        </a>
    </div>

    <?php elseif ($accessMode === 'mandatory_registration'): ?>
    <!-- Mandatory registration -->
    <?php if ($errors): ?>
    <div class="uk-alert-danger" uk-alert>
        <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach ?>
    </div>
    <?php endif ?>
    <div class="e-vote-box">
        <h3 style="font-size:1rem;font-weight:600;margin-bottom:16px"><?= __('vote_register') ?></h3>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="register">
            <div class="uk-margin">
                <input class="uk-input" type="email" name="email"
                       placeholder="<?= __('vote_your_email') ?>" required>
            </div>
            <div class="uk-margin">
                <input class="uk-input" type="text" name="name"
                       placeholder="<?= __('vote_your_name') ?>">
            </div>
            <label class="uk-margin-small-bottom" style="display:block">
                <input type="checkbox" name="consent_marketing"> <?= __('vote_consent') ?>
            </label>
            <button class="uk-button uk-button-primary uk-width-1-1 uk-margin-top">
                <?= __('vote_proceed') ?> &rarr;
            </button>
        </form>
    </div>

    <?php elseif ($accessMode === 'closed_list'): ?>
    <!-- Closed list: requires token via email -->
    <div class="uk-alert-primary" uk-alert>
        <p>This is a closed vote. Check your email for the personal voting link.</p>
    </div>

    <?php elseif ($accessMode === 'registration_with_approval'): ?>
    <!-- Registration with admin approval -->
    <?php if ($errors): ?>
    <div class="uk-alert-danger" uk-alert>
        <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach ?>
    </div>
    <?php endif ?>
    <div class="e-vote-box">
        <h3 style="font-size:1rem;font-weight:600;margin-bottom:4px"><?= __('vote_register') ?></h3>
        <p style="color:#9a94b8;font-size:.875rem;margin-bottom:16px">
            Your request will be reviewed by the organiser. You will receive an email if approved.
        </p>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="request_registration">
            <div class="uk-margin">
                <input class="uk-input" type="email" name="email"
                       placeholder="<?= __('vote_your_email') ?>" required>
            </div>
            <div class="uk-margin">
                <input class="uk-input" type="text" name="name"
                       placeholder="<?= __('vote_your_name') ?>">
            </div>
            <button class="uk-button uk-button-primary uk-width-1-1 uk-margin-top">
                Request access
            </button>
        </form>
    </div>
    <?php endif ?>

</div>
<?php
$content = ob_get_clean();
require ROOT . '/templates/public/layout.php';
