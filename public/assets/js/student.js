/* Schüler:innen-Bereich: Drucken-Button und Wunschauswahl. */
(function () {
    'use strict';

    // "Drucken"-Button auf Mein Plan
    document.querySelectorAll('[data-print]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.print();
        });
    });

    // Wunschmodus: bereits gewählte Stände in den anderen Selects desselben Blocks sperren
    document.querySelectorAll('form[data-wish-form]').forEach(function (form) {
        var selects = Array.prototype.slice.call(form.querySelectorAll('select[data-wish-select]'));
        if (selects.length < 2) {
            return;
        }

        function refresh() {
            var chosen = selects.map(function (s) { return s.value; }).filter(Boolean);
            selects.forEach(function (select) {
                Array.prototype.forEach.call(select.options, function (opt) {
                    if (opt.value === '') {
                        return;
                    }
                    var takenElsewhere = chosen.indexOf(opt.value) !== -1 && select.value !== opt.value;
                    opt.disabled = takenElsewhere;
                });
            });
        }

        selects.forEach(function (select) {
            select.addEventListener('change', refresh);
        });
        refresh();
    });
})();
