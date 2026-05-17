<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Models\Event;

$already   = (bool) ($_GET['already'] ?? false);
$slug      = $_SESSION['vote_confirmed_slug'] ?? ($_GET['slug'] ?? '');
$event     = $slug ? Event::findBySlug($slug) : null;

unset($_SESSION['vote_confirmed_slug']);

$pageTitle = __('vote_confirmed');

ob_start();
?>
<div style="max-width:480px;margin:0 auto;text-align:center;padding:40px 0">

    <?php if ($already): ?>
    <div style="font-size:2.5rem;margin-bottom:16px">ℹ️</div>
    <h2 style="font-size:1.3rem;font-weight:700;margin-bottom:8px">
        <?= __('vote_already_voted') ?>
    </h2>
    <?php else: ?>
    <div style="font-size:3rem;margin-bottom:16px">✓</div>
    <h2 style="font-size:1.4rem;font-weight:700;color:#27ae60;margin-bottom:8px">
        <?= __('vote_confirmed') ?>
    </h2>
    <?php endif ?>

    <?php if ($event): ?>
    <p style="color:#6b6494;margin-bottom:24px">
        <?= htmlspecialchars($event['name']) ?>
    </p>
    <?php if ($event['results_public']): ?>
    <a href="/vote/results.php?slug=<?= urlencode($slug) ?>" class="uk-button uk-button-default">
        View results
    </a>
    <?php endif ?>
    <?php endif ?>

</div>
<?php
$content = ob_get_clean();
require ROOT . '/templates/public/layout.php';
