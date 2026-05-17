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
        'results_timing'     => $_POST['results_timing'] ?? 'after_close',
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
    if (!in_array($data['results_timing'],['realtime','manual','after_close'], true))
        $errors[] = 'Invalid results timing.';
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
$timings       = ['realtime','manual','after_close'];
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
            <div class="uk-width-1-2@m">
                <label class="uk-form-label"><?= __('event_results_timing') ?></label>
                <select class="uk-select" name="results_timing">
                    <?php foreach ($timings as $t): ?>
                    <option value="<?= $t ?>" <?= ($event['results_timing'] ?? 'after_close') === $t ? 'selected' : '' ?>>
                        <?= __('results_timing_' . $t) ?>
                    </option>
                    <?php endforeach ?>
                </select>
            </div>

            <div class="uk-width-1-2@m uk-flex uk-flex-middle" style="padding-top:24px">
                <label>
                    <input class="uk-checkbox" type="checkbox" name="results_public"
                           <?= ($event['results_public'] ?? 1) ? 'checked' : '' ?>>
                    &nbsp;<?= __('event_results_public') ?>
                </label>
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
