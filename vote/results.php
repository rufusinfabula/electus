<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
require ROOT . '/vendor/autoload.php';

use Electus\Core\Database;
use Electus\Core\Lang;
use Electus\Models\Event;
use Electus\Models\Results;
use Electus\Models\Round;

// Config guard
if (!file_exists(ROOT . '/config/config.php')) {
    header('Location: /install/');
    exit;
}
require ROOT . '/config/config.php';
Database::init(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT ?? 3306);

// Language
session_start();
$lang = $_GET['lang'] ?? $_SESSION['pub_lang'] ?? 'it';
if (!in_array($lang, Lang::available(), true)) $lang = 'it';
$_SESSION['pub_lang'] = $lang;
Lang::init($lang);

// Resolve event
$slug  = $_GET['slug'] ?? '';
$event = $slug ? Event::findBySlug($slug) : null;
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

$eventId = (int) $event['id'];

// Resolve round
$roundId = (int) ($_GET['round_id'] ?? 0);
if ($roundId) {
    $round = Round::find($roundId);
    if (!$round || (int) $round['event_id'] !== $eventId) {
        http_response_code(404);
        exit('Round not found.');
    }
} else {
    $pdo  = Database::get();
    $stmt = $pdo->prepare(
        "SELECT * FROM event_rounds
         WHERE event_id = ? AND results_released = 1
         ORDER BY round_number LIMIT 1"
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();
    if ($row && $row['config']) $row['config'] = json_decode($row['config'], true) ?? [];
    $round = $row ?: null;
}

// Gate: results must be explicitly released
if (!$round || !$round['results_released']) {
    ?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= htmlspecialchars($event['name']) ?> — <?= __('results_title') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3/dist/css/uikit.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="e-public-body">
<header class="e-public-header">
    <div class="uk-container">
        <a class="e-public-logo" href="/vote/<?= htmlspecialchars($slug) ?>">
            <span class="e-logo-mark">E</span>
            <span class="e-logo-text" style="color:#fff"><?= htmlspecialchars($event['name']) ?></span>
        </a>
    </div>
</header>
<main class="e-public-main">
    <div class="uk-container uk-container-small uk-text-center" style="padding-top:80px">
        <span uk-icon="icon:clock;ratio:3" style="color:#9a94b8"></span>
        <h2 style="color:#4a4568;margin-top:20px"><?= __('results_title') ?></h2>
        <p style="color:#9a94b8"><?= __('results_not_computed') ?></p>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit-icons.min.js"></script>
</body>
</html>
    <?php
    exit;
}

// Load data
$resultsByCategory = Results::forRound((int) $round['id']);
$voterCount        = Results::voterCount((int) $round['id']);

$pdo  = Database::get();
$stmt = $pdo->prepare(
    "SELECT id, round_number, label FROM event_rounds
     WHERE event_id = ? AND results_released = 1
     ORDER BY round_number"
);
$stmt->execute([$eventId]);
$releasedRounds = $stmt->fetchAll();

// Collect chart init scripts — emitted after Chart.js CDN loads
$chartInits = '';

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= htmlspecialchars($event['name']) ?> — <?= __('results_title') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3/dist/css/uikit.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="e-public-body">

<header class="e-public-header">
    <div class="uk-container">
        <a class="e-public-logo" href="/vote/<?= htmlspecialchars($slug) ?>">
            <span class="e-logo-mark">E</span>
            <span class="e-logo-text" style="color:#fff"><?= htmlspecialchars($event['name']) ?></span>
        </a>
        <div class="e-lang-switcher">
            <?php foreach (Lang::available() as $l): ?>
            <a href="?slug=<?= urlencode($slug) ?>&round_id=<?= $round['id'] ?>&lang=<?= $l ?>"
               class="<?= $l === $lang ? 'e-lang-active' : '' ?>">
                <?= strtoupper($l) ?>
            </a>
            <?php endforeach ?>
        </div>
    </div>
</header>

<main class="e-public-main">
    <div class="uk-container uk-container-large">

        <div class="uk-flex uk-flex-between uk-flex-wrap uk-margin-bottom" style="gap:12px">
            <div>
                <h1 style="font-size:1.6rem;font-weight:800;color:var(--e-text);margin:0">
                    <?= __('results_title') ?>
                </h1>
                <p style="color:#9a94b8;margin:4px 0 0;font-size:.875rem">
                    <?= htmlspecialchars($event['name']) ?>
                    · <?= __('round_number') ?><?= $round['round_number'] ?>
                    <?= $round['label'] ? '· ' . htmlspecialchars($round['label']) : '' ?>
                    · <?= $voterCount ?> voters
                </p>
            </div>
        </div>

        <?php if (count($releasedRounds) > 1): ?>
        <ul class="uk-tab uk-margin-bottom">
            <?php foreach ($releasedRounds as $rr): ?>
            <li <?= (int) $rr['id'] === (int) $round['id'] ? 'class="uk-active"' : '' ?>>
                <a href="?slug=<?= urlencode($slug) ?>&round_id=<?= $rr['id'] ?>">
                    <?= __('round_number') ?><?= $rr['round_number'] ?>
                    <?= $rr['label'] ? '— ' . htmlspecialchars($rr['label']) : '' ?>
                </a>
            </li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>

        <?php if (empty($resultsByCategory)): ?>
        <div class="e-vote-box uk-text-center" style="padding:60px">
            <p style="color:#9a94b8"><?= __('results_not_computed') ?></p>
        </div>
        <?php endif ?>

        <?php foreach ($resultsByCategory as $catId => $rows):
            $catName  = $rows[0]['category_name'];
            $chartBarId = 'chart-bar-' . $catId;
            $chartPieId = 'chart-pie-' . $catId;
            $labels   = array_map(fn($r) => $r['candidate_name'], $rows);
            $values   = array_map(fn($r) => (float) $r['total_points'], $rows);
            $winner   = $rows[0]['candidate_name'];
            $bgColors = array_map(fn($r) => $r['rank'] == 1 ? '#27ae60' : '#7B68EE', $rows);

            $labelsJson  = json_encode($labels, JSON_UNESCAPED_UNICODE);
            $valuesJson  = json_encode($values);
            $colorsJson  = json_encode($bgColors);
            $winnerJson  = json_encode($winner, JSON_UNESCAPED_UNICODE);
            $pointsLabel = addslashes(__('total_points'));

            $chartInits .= <<<JS
(function(){
    var barCtx = document.getElementById('{$chartBarId}');
    if (barCtx) {
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: {$labelsJson},
                datasets: [{ label: '{$pointsLabel}', data: {$valuesJson}, backgroundColor: {$colorsJson}, borderRadius: 6 }]
            },
            options: {
                indexAxis: 'y', responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: { color: '#f0eeff' } },
                    y: { grid: { display: false }, ticks: { font: { size: 11 } } }
                }
            }
        });
    }
JS;
            if (count($rows) > 1) {
                $chartInits .= <<<JS

    var pieCtx = document.getElementById('{$chartPieId}');
    if (pieCtx) {
        new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: {$labelsJson},
                datasets: [{ data: {$valuesJson}, backgroundColor: {$colorsJson}, borderWidth: 2, borderColor: '#fff' }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } } }
        });
    }
JS;
            }
            $chartInits .= "\n})();\n";
        ?>
        <div class="uk-margin-large-bottom">
            <h2 style="font-size:1rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--e-accent);margin-bottom:16px">
                <?= htmlspecialchars($catName) ?>
            </h2>

            <div class="uk-grid-medium" uk-grid>
                <div class="uk-width-1-2@m">
                    <div class="e-table">
                        <table class="uk-table uk-table-hover uk-table-divider uk-margin-remove">
                            <thead>
                                <tr>
                                    <th style="width:44px">#</th>
                                    <th><?= __('candidate_name') ?></th>
                                    <th style="text-align:right"><?= __('total_points') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                            <tr <?= $r['rank'] == 1 ? 'style="background:#f0fdf4"' : '' ?>>
                                <td style="font-weight:<?= $r['rank'] == 1 ? '800' : '400' ?>;color:<?= $r['rank'] == 1 ? '#27ae60' : '#9a94b8' ?>">
                                    <?= $r['rank'] == 1 ? '&#9733;' : $r['rank'] ?>
                                </td>
                                <td style="font-weight:<?= $r['rank'] == 1 ? '700' : '400' ?>">
                                    <?= htmlspecialchars($r['candidate_name']) ?>
                                </td>
                                <td style="text-align:right;color:var(--e-primary);font-weight:600">
                                    <?= number_format((float) $r['total_points'], 0) ?>
                                </td>
                            </tr>
                            <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="uk-width-1-2@m">
                    <div class="uk-grid-small" uk-grid>
                        <div class="uk-width-1-1">
                            <div class="e-vote-box" style="padding:20px">
                                <canvas id="<?= $chartBarId ?>" style="max-height:260px"></canvas>
                            </div>
                        </div>
                        <?php if (count($rows) > 1): ?>
                        <div class="uk-width-1-1">
                            <div class="e-vote-box" style="padding:20px">
                                <canvas id="<?= $chartPieId ?>" style="max-height:220px"></canvas>
                            </div>
                        </div>
                        <?php endif ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach ?>

    </div>
</main>

<footer class="e-public-footer">
    <div class="uk-container">
        Powered by <a href="https://github.com/rufusinfabula/electus">Electus</a>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit-icons.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<?php if ($chartInits): ?>
<script><?= $chartInits ?></script>
<?php endif ?>
</body>
</html>
