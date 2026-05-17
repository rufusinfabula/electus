<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Models\Candidate;
use Electus\Models\Category;
use Electus\Models\Event;
use Electus\Models\Round;
use Electus\Models\Vote;
use Electus\Models\VoterList;
use Electus\VoteModels\VoteModelFactory;

// Resolve event + round
$token     = trim($_GET['token'] ?? $_POST['_token'] ?? '');
$eventSlug = trim($_GET['event'] ?? '');

$event = null;
$voter = null;

if ($token) {
    // Token-based access: identify event via voter_lists
    // We need at least one active event — find the event by brute-force token lookup
    $pdo  = \Electus\Core\Database::get();
    $stmt = $pdo->prepare('SELECT vl.*, e.* FROM voter_lists vl JOIN events e ON e.id = vl.event_id WHERE vl.token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if ($row) {
        $voter = $row;
        $event = Event::find((int) $row['event_id']);
    }
} elseif ($eventSlug) {
    $event = Event::findBySlug($eventSlug);
}

if (!$event || $event['status'] !== 'active') {
    http_response_code(404);
    die('Event not found or not active.');
}

// Validate token if required by access mode
$requiresToken = in_array($event['access_mode'], ['mandatory_registration','closed_list','registration_with_approval','voluntary_registration'], true);

if ($requiresToken && !$token) {
    Flash::error('A valid token is required to vote.');
    header('Location: /vote/event.php?slug=' . urlencode($event['slug']));
    exit;
}

if ($token) {
    $voter = VoterList::findByToken($token, (int) $event['id']);
    if (!$voter) {
        Flash::error('Invalid or expired token.');
        header('Location: /vote/event.php?slug=' . urlencode($event['slug']));
        exit;
    }
    if ($voter['token_used']) {
        Flash::warning(__('vote_already_voted'));
        header('Location: /vote/confirm.php?already=1&slug=' . urlencode($event['slug']));
        exit;
    }
    if (!$voter['approved']) {
        Flash::warning('Your registration is pending approval.');
        header('Location: /vote/event.php?slug=' . urlencode($event['slug']));
        exit;
    }
}

$round = Round::activeForEvent((int) $event['id']);
if (!$round) {
    Flash::warning(__('vote_closed'));
    header('Location: /vote/event.php?slug=' . urlencode($event['slug']));
    exit;
}

$categories          = Category::forEvent((int) $event['id']);
$candidatesByCategory = [];
foreach ($categories as $cat) {
    $candidatesByCategory[$cat['id']] = Candidate::forRoundAndCategory((int) $round['id'], (int) $cat['id']);
}

$model  = VoteModelFactory::make($round['model']);
$errors = [];

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();

    $errors = $model->validate($_POST, $round, $categories, $candidatesByCategory);

    if (empty($errors)) {
        $votes = $model->buildVotes($_POST, $round, $candidatesByCategory);

        if (empty($votes)) {
            $errors[] = 'No valid votes submitted.';
        } else {
            try {
                Vote::cast($round, $votes, $token ?: null);
                $_SESSION['vote_confirmed_slug'] = $event['slug'];
                header('Location: /vote/confirm.php');
                exit;
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$pageTitle = __('vote_title') . ' — ' . htmlspecialchars($event['name']);

ob_start();
?>
<h1 style="font-size:1.4rem;font-weight:800;margin-bottom:4px">
    <?= htmlspecialchars($event['name']) ?>
</h1>
<p style="color:#9a94b8;margin-bottom:24px;font-size:.875rem">
    <?= __('round_number') ?><?= $round['round_number'] ?>
    <?= $round['label'] ? '— ' . htmlspecialchars($round['label']) : '' ?>
    · <?= __('model_' . $round['model']) ?>
</p>

<?php if ($errors): ?>
<div class="uk-alert-danger" uk-alert>
    <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach ?>
</div>
<?php endif ?>

<form method="post" id="vote-form">
    <?= Csrf::field() ?>
    <?php if ($token): ?>
    <input type="hidden" name="_token" value="<?= htmlspecialchars($token) ?>">
    <?php endif ?>
    <input type="hidden" name="event" value="<?= htmlspecialchars($event['slug']) ?>">

    <?= $model->renderForm($round, $categories, $candidatesByCategory) ?>

    <div style="margin-top:28px;text-align:center">
        <button type="submit" class="uk-button uk-button-primary uk-button-large" style="min-width:220px">
            <?= __('vote_submit') ?>
        </button>
    </div>
</form>
<?php
$content = ob_get_clean();
require ROOT . '/templates/public/layout.php';
