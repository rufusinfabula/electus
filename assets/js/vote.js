document.addEventListener('DOMContentLoaded', function () {

    // Borda: prevent duplicate point assignments
    document.querySelectorAll('.e-borda-select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var chosen = Array.from(document.querySelectorAll('.e-borda-select'))
                .map(function (s) { return s.value; })
                .filter(function (v) { return v !== '' && v !== sel.value; });

            document.querySelectorAll('.e-borda-select').forEach(function (other) {
                if (other === sel) return;
                Array.from(other.options).forEach(function (opt) {
                    opt.disabled = chosen.includes(opt.value) && opt.value !== other.value;
                });
            });
        });
    });

    // Multiple choice: enforce max selections
    var maxChoices = parseInt(document.body.dataset.maxChoices || '0', 10);
    if (maxChoices > 0) {
        document.querySelectorAll('.e-choice-check').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var checked = document.querySelectorAll('.e-choice-check:checked').length;
                if (checked >= maxChoices) {
                    document.querySelectorAll('.e-choice-check:not(:checked)').forEach(function (u) {
                        u.disabled = true;
                    });
                } else {
                    document.querySelectorAll('.e-choice-check').forEach(function (u) {
                        u.disabled = false;
                    });
                }
            });
        });
    }

    // Language switcher
    document.querySelectorAll('a[href*="set_lang="]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var lang = new URL(el.href).searchParams.get('set_lang');
            fetch('/vote/set-lang.php?lang=' + lang).then(function () {
                location.reload();
            });
        });
    });

});
