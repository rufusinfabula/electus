<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\CatTerm;
use Electus\Core\Csrf;
use Electus\Core\Flash;
use Electus\Core\Theme;
use Electus\Models\Event;

$id    = (int) ($_GET['id'] ?? 0);
$event = $id ? Event::find($id) : null;

if ($id && !$event) {
    Flash::error('Event not found.');
    header('Location: /admin/events.php');
    exit;
}

if ($event) {
    Auth::requireEventPermission($id);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();

    // Handle logo upload (takes precedence over URL field)
    $logoUrl = trim($_POST['public_logo_url'] ?? '') ?: null;
    if (!empty($_FILES['public_logo_file']['tmp_name'])) {
        $file    = $_FILES['public_logo_file'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
        if (!in_array($file['type'], $allowed, true)) {
            $errors[] = 'Logo: formato non supportato (usa JPG, PNG, GIF, WEBP o SVG).';
        } elseif ($file['size'] > 4 * 1024 * 1024) {
            $errors[] = 'Logo: file troppo grande (max 4 MB).';
        } else {
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fname   = 'logo_' . ($id ?: 'new') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest    = __DIR__ . '/../assets/uploads/logos/' . $fname;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $appUrl  = rtrim($config['app']['url'] ?? '', '/');
                $logoUrl = $appUrl . '/assets/uploads/logos/' . $fname;
            } else {
                $errors[] = 'Logo: errore nel salvataggio del file.';
            }
        }
    }

    $data = [
        'name'               => trim($_POST['name'] ?? ''),
        'slug'               => trim($_POST['slug'] ?? ''),
        'description'        => trim($_POST['description'] ?? ''),
        'type'               => $_POST['type'] ?? 'public',
        'access_mode'        => $_POST['access_mode'] ?? 'anonymous',
        'email_verification' => isset($_POST['email_verification']) ? 1 : 0,
        'results_public'     => isset($_POST['results_public']) ? 1 : 0,
        'status'             => $_POST['status'] ?? 'draft',
        'theme_preset'       => $_POST['theme_preset'] ?? null,
        'cat_term'           => isset(CatTerm::PRESETS[$_POST['cat_term'] ?? '']) ? $_POST['cat_term'] : null,
        'theme_colors'       => json_encode(array_filter([
            'primary'   => trim($_POST['theme_primary']   ?? ''),
            'secondary' => trim($_POST['theme_secondary'] ?? ''),
            'accent'    => trim($_POST['theme_accent']    ?? ''),
            'bg'        => trim($_POST['theme_bg']        ?? ''),
            'text'      => trim($_POST['theme_text']      ?? ''),
        ])) ?: null,
        'public_logo_url'    => $logoUrl,
        'public_privacy_url' => trim($_POST['public_privacy_url'] ?? '') ?: null,
        'public_info_box'    => trim($_POST['public_info_box']    ?? '') ?: null,
    ];

    // Validation
    if (!$data['name'])  $errors[] = __('name') . ' is required.';
    if (!$data['slug'])  $data['slug'] = Event::generateSlug($data['name']);

    // Sanitize slug
    $data['slug'] = preg_replace('/[^a-z0-9-]/', '', strtolower($data['slug']));

    if (!in_array($data['type'],          ['public','private'], true))
        $errors[] = 'Invalid type.';
    if (!in_array($data['access_mode'],   ['anonymous','voluntary_registration','mandatory_registration','closed_list','registration_with_approval'], true))
        $errors[] = 'Invalid access mode.';
    if (!in_array($data['status'],        ['draft','active','closed','archived'], true))
        $errors[] = 'Invalid status.';

    if (!$errors && Event::slugExists($data['slug'], $id)) {
        $errors[] = 'Slug "' . htmlspecialchars($data['slug']) . '" is already in use.';
    }

    if (!$errors) {
        $user = Auth::currentUser();
        if ($event) {
            Event::update($id, $data);
            Flash::success(__('event_saved'));
            header('Location: /admin/events-edit.php?id=' . $id);
        } else {
            $newId = Event::create($data, $user['id']);
            Flash::success(__('event_saved'));
            header('Location: /admin/events-edit.php?id=' . $newId);
        }
        exit;
    }
    // Re-populate on error
    $event = array_merge($event ?? [], $data);
}

$pageTitle     = $event ? __('event_edit') : __('event_new');
$activeMenu    = 'events';
$currentEventId = $id ?: null;
$useQuill      = true;

$accessModes   = ['anonymous','voluntary_registration','mandatory_registration','closed_list','registration_with_approval'];
$statuses      = ['draft','active','closed','archived'];

ob_start();
?>
<div class="uk-flex uk-flex-middle" style="gap:12px;margin-bottom:24px">
    <a href="/admin/events.php" uk-icon="arrow-left" class="uk-icon-link"></a>
    <h1 class="e-page-title uk-margin-remove"><?= htmlspecialchars($pageTitle) ?></h1>
</div>

<?php if ($errors): ?>
<div class="uk-alert-danger" uk-alert>
    <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach ?>
</div>
<?php endif ?>

<div class="e-card">
    <form method="post" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <div class="uk-grid-medium" uk-grid>

            <!-- Name -->
            <div class="uk-width-2-3@m">
                <label class="uk-form-label"><?= __('name') ?> *</label>
                <input class="uk-input" type="text" id="field-name" name="name"
                       value="<?= htmlspecialchars($event['name'] ?? '') ?>" required autofocus>
            </div>

            <!-- Slug -->
            <div class="uk-width-1-3@m">
                <label class="uk-form-label"><?= __('event_slug') ?></label>
                <input class="uk-input" type="text" id="field-slug" name="slug"
                       data-autoslug="1"
                       value="<?= htmlspecialchars($event['slug'] ?? '') ?>"
                       pattern="[a-z0-9-]+" title="Lowercase letters, numbers and hyphens only">
            </div>

            <!-- Description (Quill rich text) -->
            <div class="uk-width-1-1" style="padding-bottom:24px">
                <label class="uk-form-label"><?= __('description') ?></label>
                <div class="e-quill-editor" data-target="field-description"></div>
                <input type="hidden" name="description" id="field-description"
                       value="<?= htmlspecialchars($event['description'] ?? '') ?>">
            </div>

            <!-- Type -->
            <div class="uk-width-1-3@m">
                <label class="uk-form-label"><?= __('event_type') ?></label>
                <select class="uk-select" name="type">
                    <option value="public"  <?= ($event['type'] ?? 'public')  === 'public'  ? 'selected' : '' ?>>Public</option>
                    <option value="private" <?= ($event['type'] ?? 'public')  === 'private' ? 'selected' : '' ?>>Private</option>
                </select>
            </div>

            <!-- Status -->
            <div class="uk-width-1-3@m">
                <label class="uk-form-label"><?= __('event_status') ?></label>
                <select class="uk-select" name="status">
                    <?php foreach ($statuses as $s): ?>
                    <option value="<?= $s ?>" <?= ($event['status'] ?? 'draft') === $s ? 'selected' : '' ?>>
                        <?= __('event_status_' . $s) ?>
                    </option>
                    <?php endforeach ?>
                </select>
            </div>

            <!-- Access mode -->
            <div class="uk-width-1-3@m">
                <label class="uk-form-label"><?= __('event_access_mode') ?></label>
                <select class="uk-select" name="access_mode" id="access-mode-select">
                    <?php foreach ($accessModes as $am): ?>
                    <option value="<?= $am ?>" <?= ($event['access_mode'] ?? 'anonymous') === $am ? 'selected' : '' ?>>
                        <?= __('access_' . $am) ?>
                    </option>
                    <?php endforeach ?>
                </select>
            </div>

            <!-- Email verification (shown only when access mode requires registration) -->
            <div class="uk-width-1-1" id="email-verification-row"
                 style="<?= in_array($event['access_mode'] ?? 'anonymous', ['mandatory_registration','closed_list','registration_with_approval'], true) ? '' : 'display:none' ?>">
                <label>
                    <input class="uk-checkbox" type="checkbox" name="email_verification"
                           <?= ($event['email_verification'] ?? 0) ? 'checked' : '' ?>>
                    &nbsp;<?= __('event_email_verification') ?>
                </label>
                <p class="uk-text-small uk-text-muted uk-margin-remove-top">
                    If enabled, voters receive a confirmation link before they can vote.
                </p>
            </div>

            <hr class="uk-width-1-1">

            <!-- Category terminology picker -->
            <div class="uk-width-1-1">
                <label class="uk-form-label" style="font-weight:700;font-size:.85rem">
                    <?= __('cat_term_label') ?>
                </label>
                <p style="font-size:.78rem;color:#9a94b8;margin:2px 0 10px">
                    <?= __('cat_term_desc') ?>
                </p>
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                <?php foreach (CatTerm::PRESETS as $ctKey => $ctPal):
                    $ctSel = ($event['cat_term'] ?? CatTerm::DEFAULT_PRESET) === $ctKey;
                ?>
                <label class="e-ctlabel" style="display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;
                              border:2px solid <?= $ctSel ? 'var(--e-primary)' : '#e8e6f0' ?>;cursor:pointer;
                              background:<?= $ctSel ? '#f5f3ff' : '#fff' ?>;transition:border-color .1s;white-space:nowrap"
                       onclick="document.querySelectorAll('.e-ctlabel').forEach(function(l){l.style.borderColor='#e8e6f0';l.style.background='#fff'});this.style.borderColor='var(--e-primary)';this.style.background='#f5f3ff'">
                    <input type="radio" name="cat_term" value="<?= $ctKey ?>"
                           class="uk-radio" <?= $ctSel ? 'checked' : '' ?>>
                    <span style="font-size:.8rem;font-weight:600;color:var(--e-text)">
                        <?php $ctL = $ctPal[\Electus\Core\Lang::current()] ?? $ctPal['it']; ?>
                        <?= htmlspecialchars($ctL['s']) ?> / <?= htmlspecialchars($ctL['p']) ?>
                    </span>
                </label>
                <?php endforeach ?>
                </div>
            </div>

            <hr class="uk-width-1-1">

            <!-- Logo (upload + URL fallback) -->
            <div class="uk-width-1-2@m">
                <label class="uk-form-label"><?= __('public_logo_url_label') ?> <span style="color:#9a94b8">(<?= __('optional') ?>)</span></label>
                <?php if (!empty($event['public_logo_url'])): ?>
                <div style="margin-bottom:8px;display:flex;align-items:center;gap:10px">
                    <img src="<?= htmlspecialchars($event['public_logo_url']) ?>" alt="logo"
                         style="max-height:48px;max-width:120px;object-fit:contain;border-radius:4px;border:1px solid #e8e6f0">
                    <span style="font-size:.75rem;color:#9a94b8">Logo attuale</span>
                </div>
                <?php endif ?>
                <div style="display:flex;flex-direction:column;gap:6px">
                    <label style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:2px dashed #d0cbe8;border-radius:8px;cursor:pointer;font-size:.8rem;color:#6b6494;background:#faf9ff"
                           for="logo-file-input">
                        <span uk-icon="icon:cloud-upload;ratio:.9" style="color:var(--e-primary);flex-shrink:0"></span>
                        <span id="logo-file-label">Carica immagine dal disco (JPG, PNG, SVG — max 4 MB)</span>
                    </label>
                    <input type="file" id="logo-file-input" name="public_logo_file"
                           accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml"
                           style="display:none"
                           onchange="document.getElementById('logo-file-label').textContent = this.files[0]?.name || 'Carica immagine dal disco'">
                    <div style="display:flex;align-items:center;gap:6px">
                        <div style="height:1px;flex:1;background:#e8e6f0"></div>
                        <span style="font-size:.72rem;color:#9a94b8">oppure URL</span>
                        <div style="height:1px;flex:1;background:#e8e6f0"></div>
                    </div>
                    <input class="uk-input uk-form-small" type="url" name="public_logo_url"
                           placeholder="https://..."
                           value="<?= htmlspecialchars($event['public_logo_url'] ?? '') ?>">
                </div>
            </div>

            <!-- Privacy policy URL -->
            <div class="uk-width-1-2@m">
                <label class="uk-form-label"><?= __('public_privacy_url_label') ?> <span style="color:#9a94b8">(<?= __('optional') ?>)</span></label>
                <input class="uk-input" type="url" name="public_privacy_url"
                       placeholder="https://..."
                       value="<?= htmlspecialchars($event['public_privacy_url'] ?? '') ?>">
            </div>

            <!-- Info box (Quill) -->
            <div class="uk-width-1-1" style="padding-bottom:24px">
                <label class="uk-form-label"><?= __('public_info_box_label') ?> <span style="color:#9a94b8">(<?= __('optional') ?>)</span></label>
                <div class="e-quill-editor" data-target="field-public-info-box"></div>
                <input type="hidden" name="public_info_box" id="field-public-info-box"
                       value="<?= htmlspecialchars($event['public_info_box'] ?? '') ?>">
            </div>

            <hr class="uk-width-1-1">

            <!-- Results public -->
            <div class="uk-width-1-1">
                <label>
                    <input class="uk-checkbox" type="checkbox" name="results_public"
                           <?= ($event['results_public'] ?? 1) ? 'checked' : '' ?>>
                    &nbsp;<?= __('event_results_public') ?>
                </label>
                <p class="uk-text-small uk-text-muted uk-margin-remove-top">
                    Results are always published manually per round, using the "Publish results" button on the Results page.
                </p>
            </div>

            <hr class="uk-width-1-1">

            <!-- Theme picker -->
            <div class="uk-width-1-1">
                <label class="uk-form-label" style="font-weight:700;font-size:.85rem">
                    Tema grafico pagine pubbliche
                </label>
                <p style="font-size:.78rem;color:#9a94b8;margin:2px 0 10px">
                    Palette applicata alla scheda di voto e ai risultati che vedono i votanti.
                </p>
                <?php
                $currentPreset = $event['theme_preset'] ?? null;
                $currentColors = !empty($event['theme_colors'])
                    ? (is_array($event['theme_colors']) ? $event['theme_colors'] : json_decode($event['theme_colors'], true))
                    : [];
                ?>
                <input type="hidden" name="theme_preset" id="ev_theme_input" value="<?= htmlspecialchars($currentPreset ?? '') ?>">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:6px;margin-bottom:10px">
                <?php foreach (Theme::PALETTES as $key => $pal):
                    $sel = ($currentPreset === $key);
                ?>
                <div class="e-ptc" data-key="<?= $key ?>" onclick="selectEvTheme('<?= $key ?>')"
                     style="border:2px solid <?= $sel ? 'var(--e-primary)' : '#e8e6f0' ?>;
                            border-radius:8px;overflow:hidden;cursor:pointer;
                            box-shadow:<?= $sel ? '0 0 0 3px rgba(76,61,158,.12)' : 'none' ?>">
                    <!-- Square colour preview -->
                    <div style="aspect-ratio:1;background:<?= htmlspecialchars($pal['bg']) ?>;position:relative;padding:7px;display:flex;flex-direction:column;justify-content:space-between">
                        <div style="height:4px;border-radius:3px;background:<?= htmlspecialchars($pal['primary']) ?>"></div>
                        <div style="display:flex;gap:3px;align-items:flex-end">
                            <div style="height:22px;flex:3;border-radius:3px;background:<?= htmlspecialchars($pal['primary']) ?>"></div>
                            <div style="height:14px;flex:2;border-radius:3px;background:<?= htmlspecialchars($pal['accent']) ?>"></div>
                            <div style="height:9px;flex:2;border-radius:3px;background:<?= htmlspecialchars($pal['secondary']) ?>;opacity:.7"></div>
                        </div>
                        <div style="height:4px;border-radius:2px;background:<?= htmlspecialchars($pal['primary']) ?>;opacity:.25"></div>
                        <?php if ($sel): ?>
                        <div id="ev_check_<?= $key ?>" style="position:absolute;top:4px;right:4px;width:14px;height:14px;border-radius:50%;background:var(--e-primary);display:flex;align-items:center;justify-content:center">
                            <span style="color:#fff;font-size:8px;font-weight:800">✓</span>
                        </div>
                        <?php else: ?>
                        <div id="ev_check_<?= $key ?>" style="position:absolute;top:4px;right:4px;width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,.5)"></div>
                        <?php endif ?>
                    </div>
                    <!-- Label -->
                    <div style="padding:3px 5px 4px;font-size:.65rem;font-weight:600;color:#2d2a40;line-height:1.2;text-align:center;background:#fff">
                        <?= htmlspecialchars($pal['label']) ?>
                    </div>
                </div>
                <?php endforeach ?>
                </div>

                <script>
                function selectEvTheme(key) {
                    document.getElementById('ev_theme_input').value = key;
                    document.querySelectorAll('.e-ptc').forEach(function(c) {
                        var k = c.dataset.key, s = k === key;
                        c.style.borderColor = s ? 'var(--e-primary)' : '#e8e6f0';
                        c.style.boxShadow   = s ? '0 0 0 3px rgba(76,61,158,.12)' : 'none';
                        var chk = document.getElementById('ev_check_' + k);
                        if (!chk) return;
                        chk.style.background = s ? 'var(--e-primary)' : 'transparent';
                        chk.style.border     = s ? 'none' : '2px solid rgba(255,255,255,.5)';
                        chk.innerHTML        = s ? '<span style="color:#fff;font-size:8px;font-weight:800">✓</span>' : '';
                    });
                }
                </script>

                <!-- Custom color overrides -->
                <details style="font-size:.82rem">
                    <summary style="color:var(--e-primary);cursor:pointer;font-weight:600">
                        Personalizza colori (opzionale)
                    </summary>
                    <div class="uk-grid-small uk-margin-small-top" uk-grid>
                        <?php foreach (['primary'=>'Primary','secondary'=>'Secondary','accent'=>'Accent','bg'=>'Background','text'=>'Testo'] as $k => $lbl): ?>
                        <div class="uk-width-1-5@m uk-width-1-3@s">
                            <label class="uk-form-label" style="font-size:.75rem"><?= $lbl ?></label>
                            <div style="display:flex;align-items:center;gap:6px">
                                <input type="color" name="theme_<?= $k ?>"
                                       value="<?= htmlspecialchars($currentColors[$k] ?? '') ?>"
                                       style="width:36px;height:36px;border:none;background:none;cursor:pointer;padding:0">
                                <input type="text" name="theme_<?= $k ?>"
                                       class="uk-input uk-form-small"
                                       value="<?= htmlspecialchars($currentColors[$k] ?? '') ?>"
                                       placeholder="#000000"
                                       style="font-family:monospace;width:90px">
                            </div>
                        </div>
                        <?php endforeach ?>
                    </div>
                </details>
            </div>

        </div>

        <div class="uk-margin-top uk-flex" style="gap:12px">
            <button type="submit" class="uk-button uk-button-primary"><?= __('save') ?></button>
            <a href="/admin/events.php" class="uk-button uk-button-default"><?= __('cancel') ?></a>
            <?php if ($event): ?>
            <a href="/admin/rounds.php?event_id=<?= $id ?>" class="uk-button uk-button-default" style="margin-left:auto">
                <?= __('rounds_title') ?> &rarr;
            </a>
            <?php endif ?>
        </div>
    </form>
</div>

<?php if ($event && !empty($event['slug'])): ?>
<?php
    $appUrl   = rtrim($config['app']['url'] ?? '', '/');
    $voteUrl  = $appUrl . '/vote/event.php?slug=' . urlencode($event['slug']);
    $resultsUrl = $appUrl . '/vote/results.php?slug=' . urlencode($event['slug']);
?>
<div class="e-card uk-margin-top" style="border-left:4px solid var(--e-accent)">
    <p style="margin:0 0 8px;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--e-accent)">
        Public links
    </p>
    <div style="display:flex;flex-direction:column;gap:10px">
        <div>
            <p style="margin:0 0 4px;font-size:.75rem;color:#9a94b8">Voting page (share with voters)</p>
            <div style="display:flex;align-items:center;gap:8px">
                <code style="background:#f0eeff;padding:6px 12px;border-radius:6px;font-size:.85rem;flex:1;word-break:break-all">
                    <?= htmlspecialchars($voteUrl) ?>
                </code>
                <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($voteUrl, ENT_QUOTES) ?>')"
                        class="uk-button uk-button-default uk-button-small" title="Copy">
                    <span uk-icon="icon:copy;ratio:.85"></span>
                </button>
                <a href="<?= htmlspecialchars($voteUrl) ?>" target="_blank"
                   class="uk-button uk-button-default uk-button-small">
                    <span uk-icon="icon:forward;ratio:.85"></span>
                </a>
            </div>
        </div>
        <div>
            <p style="margin:0 0 4px;font-size:.75rem;color:#9a94b8">Results page (public, when released)</p>
            <div style="display:flex;align-items:center;gap:8px">
                <code style="background:#f0eeff;padding:6px 12px;border-radius:6px;font-size:.85rem;flex:1;word-break:break-all">
                    <?= htmlspecialchars($resultsUrl) ?>
                </code>
                <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($resultsUrl, ENT_QUOTES) ?>')"
                        class="uk-button uk-button-default uk-button-small" title="Copy">
                    <span uk-icon="icon:copy;ratio:.85"></span>
                </button>
                <a href="<?= htmlspecialchars($resultsUrl) ?>" target="_blank"
                   class="uk-button uk-button-default uk-button-small">
                    <span uk-icon="icon:forward;ratio:.85"></span>
                </a>
            </div>
        </div>
    </div>
    <?php if (($event['status'] ?? '') !== 'active'): ?>
    <p style="margin:10px 0 0;font-size:.8rem;color:#e67e22">
        <span uk-icon="icon:warning;ratio:.8"></span>
        The voting page only works when the event status is <strong>Active</strong>.
    </p>
    <?php endif ?>
</div>
<?php endif ?>

<script>
document.getElementById('access-mode-select')?.addEventListener('change', function () {
    var needs = ['mandatory_registration','closed_list','registration_with_approval'];
    document.getElementById('email-verification-row').style.display =
        needs.includes(this.value) ? '' : 'none';
});
</script>

<?php
$content = ob_get_clean();
require ROOT . '/templates/admin/layout.php';
