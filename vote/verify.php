<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Flash;
use Electus\Models\VoterList;
use Electus\Core\Database;

$token = trim($_GET['token'] ?? '');

if (!$token) {
    http_response_code(400);
    die('Missing token.');
}

// Look up the token across all events
$pdo  = Database::get();
$stmt = $pdo->prepare(
    'SELECT vl.*, e.slug AS event_slug
     FROM voter_lists vl
     JOIN events e ON e.id = vl.event_id
     WHERE vl.token = ? LIMIT 1'
);
$stmt->execute([$token]);
$voter = $stmt->fetch();

if (!$voter) {
    Flash::error('Invalid or expired verification link.');
    header('Location: /');
    exit;
}

if ($voter['token_used']) {
    Flash::warning(__('vote_already_voted'));
    header('Location: /vote/confirm.php?already=1&slug=' . urlencode($voter['event_slug']));
    exit;
}

// Approve the voter
$stmt = $pdo->prepare('UPDATE voter_lists SET approved = 1 WHERE id = ?');
$stmt->execute([$voter['id']]);

// Redirect to cast with token
header('Location: /vote/cast.php?token=' . urlencode($token));
exit;
