<!DOCTYPE html>
<html lang="<?= \Electus\Core\Lang::current() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? __('app_name')) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3/dist/css/uikit.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <?php if (!empty($event)):
        echo \Electus\Core\Theme::cssBlock(\Electus\Core\Theme::forEvent($event, $config ?? []));
    endif ?>
</head>
<body class="e-public-body">

<header class="e-public-header">
    <div class="uk-container">
        <a href="/" class="e-public-logo">
            <span class="e-logo-mark">E</span>
            <span class="e-logo-text">Electus</span>
        </a>
        <!-- Language switcher -->
        <div class="e-lang-switcher">
            <?php foreach (\Electus\Core\Lang::available() as $lang): ?>
            <a href="?set_lang=<?= $lang ?>"
               class="<?= $lang === \Electus\Core\Lang::current() ? 'e-lang-active' : '' ?>">
                <?= strtoupper($lang) ?>
            </a>
            <?php endforeach ?>
        </div>
    </div>
</header>

<main class="e-public-main">
    <div class="uk-container uk-container-small">

        <?php
        foreach (\Electus\Core\Flash::get() as $msg):
        ?>
        <div class="uk-alert-<?= htmlspecialchars($msg['type']) ?> uk-margin-top" uk-alert>
            <a class="uk-alert-close" uk-close></a>
            <p><?= htmlspecialchars($msg['message']) ?></p>
        </div>
        <?php endforeach ?>

        <?= $content ?? '' ?>
    </div>
</main>

<footer class="e-public-footer">
    <div class="uk-container">
        <p>Powered by <a href="https://github.com/rufusinfabula/electus" target="_blank" rel="noopener">Electus</a></p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit-icons.min.js"></script>
<script src="/assets/js/vote.js"></script>
</body>
</html>
