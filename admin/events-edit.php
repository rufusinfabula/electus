<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Electus\Core\Auth;
use Electus\Core\Csrf;
use Electus\Core\Flash;
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

    $data = [
        'name'               => trim($_POST['name'] ?? ''),
        'slug'               => trim($_POST['slug'] ?? ''),
        'description'        => trim($_POST['description'] ?? ''),
        'type'               => $_POST['type'] ?? 'public',
        'access_mode'        => $_POST['access_mode'] ?? 'anonymous',
        'email_verification' => isset($_POST['email_verification']) ? 1 : 0,
        'results_public'     => isset($_POST['results_public']) ? 1 : 0,
        'status'             => $_POST['status'] ?? 'draft',
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
    <form method="post">
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

            <!-- Description -->
            <div class="uk-width-1-1">
                <label class="uk-form-label"><?= __('description') ?></label>
                <textarea class="uk-textarea" name="description" rows="3"
                ><?= htmlspecialchars($event['description'] ?? '') ?></textarea>
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
