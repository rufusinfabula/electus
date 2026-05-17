document.addEventListener('DOMContentLoaded', function () {

    // Confirm delete buttons
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            var msg = el.dataset.confirm || 'Are you sure?';
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // Auto-generate slug from name field
    var nameField = document.getElementById('field-name');
    var slugField = document.getElementById('field-slug');
    if (nameField && slugField && slugField.dataset.autoslug === '1') {
        nameField.addEventListener('input', function () {
            slugField.value = nameField.value
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9\s-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-');
        });
    }

    // Language switcher: persist via session
    document.querySelectorAll('a[href*="set_lang="]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var lang = new URL(el.href).searchParams.get('set_lang');
            fetch('/admin/set-lang.php?lang=' + lang).then(function () {
                location.reload();
            });
        });
    });

});
