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

    Flash::success(__('settings_saved'));
    header('Location: /admin/settings.php');
    exit;
}

$currentPreset = Settings::get('admin_theme', Theme::DEFAULT_PRESET);

$pageTitle  = __('settings_title');
$activeMenu = 'settings';

ob_start();
?>

<h1 class="e-page-title"><?= __('settings_title') ?></h1>

<div class="e-card">
    <form method="post" id="theme-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="admin_theme" id="admin_theme_input" value="<?= htmlspecialchars($currentPreset) ?>">

        <div class="uk-flex uk-flex-middle uk-flex-between" style="margin-bottom:20px">
            <div>
                <h3 style="font-size:1rem;font-weight:700;margin:0 0 2px"><?= __('settings_admin_theme') ?></h3>
                <p style="font-size:.82rem;color:#9a94b8;margin:0">
                    <?= __('settings_admin_theme_desc') ?>
                </p>
            </div>
            <button type="submit" class="uk-button uk-button-primary" style="flex-shrink:0">
                <span uk-icon="icon:check;ratio:.85"></span> <?= __('save') ?>
            </button>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:8px">
        <?php foreach (Theme::PALETTES as $key => $pal):
            $selected = ($currentPreset === $key);
        ?>
        <div class="e-tc" data-key="<?= $key ?>" onclick="selectTheme('<?= $key ?>')"
             style="border:2px solid <?= $selected ? 'var(--e-primary)' : '#e8e6f0' ?>;
                    border-radius:12px;overflow:hidden;cursor:pointer;
                    background:#fff;transition:border-color .15s,box-shadow .15s;
                    box-shadow:<?= $selected ? '0 0 0 3px rgba(76,61,158,.15)' : 'none' ?>">

            <!-- Colour preview strip -->
            <div style="height:56px;background:<?= htmlspecialchars($pal['bg']) ?>;position:relative">
                <!-- Navbar bar -->
                <div style="height:14px;background:<?= htmlspecialchars($pal['primary']) ?>;display:flex;align-items:center;padding:0 8px;gap:4px">
                    <div style="width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.5)"></div>
                    <div style="flex:1;height:3px;border-radius:2px;background:rgba(255,255,255,.3)"></div>
                </div>
                <!-- Fake content -->
                <div style="padding:5px 8px;display:flex;gap:4px;align-items:center">
                    <div style="width:28px;height:26px;border-radius:4px;background:<?= htmlspecialchars($pal['primary']) ?>;opacity:.15"></div>
                    <div style="flex:1">
                        <div style="height:4px;border-radius:2px;background:<?= htmlspecialchars($pal['text']) ?>;opacity:.2;margin-bottom:3px;width:70%"></div>
                        <div style="height:3px;border-radius:2px;background:<?= htmlspecialchars($pal['text']) ?>;opacity:.1;width:50%"></div>
                    </div>
                    <div style="height:16px;padding:0 6px;border-radius:4px;background:<?= htmlspecialchars($pal['primary']) ?>;display:flex;align-items:center">
                        <div style="height:2px;width:18px;border-radius:1px;background:#fff;opacity:.8"></div>
                    </div>
                </div>
            </div>

            <!-- Swatches + label -->
            <div style="padding:10px 12px 12px">
                <div style="display:flex;gap:5px;margin-bottom:8px;align-items:center">
                    <div style="width:16px;height:16px;border-radius:50%;background:<?= htmlspecialchars($pal['primary']) ?>;flex-shrink:0" title="Primary"></div>
                    <div style="width:16px;height:16px;border-radius:50%;background:<?= htmlspecialchars($pal['secondary']) ?>;flex-shrink:0" title="Secondary"></div>
                    <div style="width:16px;height:16px;border-radius:50%;background:<?= htmlspecialchars($pal['accent']) ?>;flex-shrink:0" title="Accent"></div>
                    <div style="width:16px;height:16px;border-radius:50%;background:<?= htmlspecialchars($pal['bg']) ?>;border:1px solid #ddd;flex-shrink:0" title="Background"></div>
                    <?php if ($selected): ?>
                    <div style="margin-left:auto;width:18px;height:18px;border-radius:50%;background:var(--e-primary);display:flex;align-items:center;justify-content:center;flex-shrink:0" id="check_<?= $key ?>">
                        <span style="color:#fff;font-size:10px;font-weight:800">✓</span>
                    </div>
                    <?php else: ?>
                    <div style="margin-left:auto;width:18px;height:18px;border-radius:50%;border:2px solid #e0ddf0;flex-shrink:0" id="check_<?= $key ?>"></div>
                    <?php endif ?>
                </div>
                <div style="font-size:.78rem;font-weight:600;color:#2d2a40;line-height:1.3">
                    <?= htmlspecialchars($pal['label']) ?>
                </div>
                <?php if ($pal['dark']): ?>
                <div style="font-size:.68rem;color:#9a94b8;margin-top:2px"><?= __('settings_dark_theme') ?></div>
                <?php endif ?>
            </div>
        </div>
        <?php endforeach ?>
        </div>

    </form>
</div>

<script>
function selectTheme(key) {
    // Update hidden input
    document.getElementById('admin_theme_input').value = key;

    // Reset all cards
    document.querySelectorAll('.e-tc').forEach(function(card) {
        card.style.borderColor = '#e8e6f0';
        card.style.boxShadow   = 'none';
        var k = card.dataset.key;
        var chk = document.getElementById('check_' + k);
        if (chk) {
            chk.style.background = 'transparent';
            chk.style.border     = '2px solid #e0ddf0';
            chk.innerHTML        = '';
        }
    });

    // Highlight selected card
    var sel = document.querySelector('[data-key="' + key + '"]');
    if (sel) {
        sel.style.borderColor = 'var(--e-primary)';
        sel.style.boxShadow   = '0 0 0 3px rgba(76,61,158,.15)';
        var chk = document.getElementById('check_' + key);
        if (chk) {
            chk.style.background = 'var(--e-primary)';
            chk.style.border     = 'none';
            chk.innerHTML        = '<span style="color:#fff;font-size:10px;font-weight:800">✓</span>';
        }
    }
}
</script>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
