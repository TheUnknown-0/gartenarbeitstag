/**
 * Aktionstag-Formular: Felder ein-/ausblenden, die nur für einen
 * Anmeldemodus relevant sind (data-mode-only="direct|wishlist").
 */
(function () {
    var select = document.querySelector('[data-mode-select]');
    if (!select) {
        return;
    }

    function apply() {
        document.querySelectorAll('[data-mode-only]').forEach(function (el) {
            el.hidden = el.getAttribute('data-mode-only') !== select.value;
        });
    }

    select.addEventListener('change', apply);
    apply();
})();
