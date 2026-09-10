/**
 * Quotenmodus, Block „Bedarf je Stand": Live-Gegenrechnung.
 *
 * Summiert je Zeitblock die eingetippten Bedarfszahlen pro Klassenstufe (und
 * insgesamt) und stellt sie der Zahl der überhaupt einteilbaren Schüler:innen
 * gegenüber (data-available, vom Server vorberechnet). Übersteigt der Bedarf
 * das Angebot, wird der Zähler rot markiert.
 */
(function () {
    var inputs = document.querySelectorAll('[data-quota-input]');
    var balances = document.querySelectorAll('[data-quota-balance]');
    if (!inputs.length || !balances.length) {
        return;
    }

    function update(item, sum) {
        var available = parseInt(item.getAttribute('data-available'), 10) || 0;
        var out = item.querySelector('[data-quota-sum]');
        if (out) {
            out.textContent = String(sum);
        }
        item.classList.toggle('badge-danger', sum > available);
        item.classList.toggle('badge-success', sum > 0 && sum <= available);
    }

    function recalc() {
        balances.forEach(function (panel) {
            var blockId = panel.getAttribute('data-quota-balance');
            var perGrade = {};
            var total = 0;

            document.querySelectorAll('[data-quota-input][data-block="' + blockId + '"]').forEach(function (input) {
                var value = parseInt(input.value, 10);
                if (isNaN(value) || value < 0) {
                    value = 0;
                }
                var grade = input.getAttribute('data-grade');
                perGrade[grade] = (perGrade[grade] || 0) + value;
                total += value;
            });

            panel.querySelectorAll('[data-quota-grade]').forEach(function (item) {
                update(item, perGrade[item.getAttribute('data-quota-grade')] || 0);
            });
            var totalItem = panel.querySelector('[data-quota-total]');
            if (totalItem) {
                update(totalItem, total);
            }
        });
    }

    inputs.forEach(function (input) {
        input.addEventListener('input', recalc);
    });
    recalc();
})();
