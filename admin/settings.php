<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Core\Theme;
use Electus\Models\Settings;

Auth::requireRole('superadmin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();

    $preset = $_POST['admin_theme'] ?? Theme::DEFAULT_PRESET;
    if (!isset(Theme::PALETTES[$preset])) $preset = Theme::DEFAULT_PRESET;
    Settings::set('admin_theme', $preset);

    Flash::success('Tema admin aggiornato.');
    header('Location: /admin/settings.php');
    exit;
}

$currentPreset = Settings::get('admin_theme', Theme::DEFAULT_PRESET);

$pageTitle  = 'Impostazioni sistema';
$activeMenu = 'settings';

ob_start();
?>

<div class="uk-flex uk-flex-middle uk-margin-bottom" style="gap:12px">
    <h1 class="e-page-title uk-margin-remove">Impostazioni sistema</h1>
</div>

<div class="e-card">
    <form method="post">
        <?= Csrf::field() ?>

        <h3 style="font-size:.9rem;font-weight:700;margin:0 0 4px">Tema pannello admin</h3>
        <p style="font-size:.78rem;color:#9a94b8;margin:0 0 20px">
            Scegli la palette di colori applicata a tutte le pagine del pannello di amministrazione.
        </p>

        <div class="e-theme-grid">
        <?php foreach (Theme::PALETTES as $key => $pal): ?>
        <label class="e-theme-card <?= $currentPreset === $key ? 'e-theme-selected' : '' ?>">
            <input type="radio" name="admin_theme" value="<?= $key ?>"
                   <?= $currentPreset === $key ? 'checked' : '' ?>
                   style="position:absolute;opacity:0;pointer-events:none">
            <div class="e-theme-preview" style="background:<?= htmlspecialchars($pal['bg']) ?>">
                <div class="e-theme-preview-bar" style="background:<?= htmlspecialchars($pal['primary']) ?>">
                    <span style="color:#fff;font-size:9px;font-weight:700;padding:0 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block">
                        <?= htmlspecialchars($pal['label']) ?>
                    </span>
                </div>
                <div style="padding:6px 8px 4px;display:flex;gap:4px">
                    <span style="background:<?= htmlspecialchars($pal['primary']) ?>;color:#fff;border-radius:4px;padding:2px 6px;font-size:8px;font-weight:700">Menu</span>
                    <span style="background:<?= htmlspecialchars($pal['accent']) ?>;border-radius:4px;padding:2px 6px;font-size:8px;font-weight:700;color:<?= $pal['dark'] ? '#fff' : '#111' ?>">btn</span>
                </div>
                <div style="padding:0 8px 6px;display:flex;gap:3px">
                    <div style="height:5px;border-radius:3px;flex:2;background:<?= htmlspecialchars($pal['primary']) ?>"></div>
                    <div style="height:5px;border-radius:3px;flex:1;background:<?= htmlspecialchars($pal['secondary']) ?>"></div>
                </div>
            </div>
            <div class="e-theme-label">
                <?= htmlspecialchars($pal['label']) ?>
                <div class="e-theme-swatches">
                    <span style="background:<?= htmlspecialchars($pal['primary']) ?>"></span>
                    <span style="background:<?= htmlspecialchars($pal['secondary']) ?>"></span>
                    <span style="background:<?= htmlspecialchars($pal['accent']) ?>"></span>
                    <span style="background:<?= htmlspecialchars($pal['bg']) ?>;border:1px solid #ddd"></span>
                    <span style="background:<?= htmlspecialchars($pal['text']) ?>"></span>
                </div>
            </div>
        </label>
        <?php endforeach ?>
        </div>

        <div class="uk-margin-top">
            <button type="submit" class="uk-button uk-button-primary">
                <span uk-icon="icon:check;ratio:.85"></span> Salva tema
            </button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
