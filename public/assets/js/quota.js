/**
 * Quotenmodus, Block „Bedarf je Stand": Live-Gegenrechnung.
 *
 * Summiert je Zeitblock die eingetippten Bedarfszahlen pro Klassenstufe (und
 * insgesamt) und stellt sie der Zahl der einteilbaren Schüler:innen gegenüber.
 * Basiszahl (data-available) kommt vom Server; der Bedarf aus ANDEREN Blöcken
 * wird hier live abgezogen:
 *   - zeitgleiche Blöcke (data-quota-overlaps): voll abgezogen
 *   - übrige Blöcke: nur anteilig, wenn eine Höchstzahl Zeitblöcke je
 *     Schüler:in gilt (data-quota-max-blocks) — dann ~ Bedarf / Höchstzahl.
 * Übersteigt der Bedarf das Angebot, wird der Zähler rot markiert.
 */
(function () {
    var inputs = document.querySelectorAll('[data-quota-input]');
    var balances = document.querySelectorAll('[data-quota-balance]');
    if (!inputs.length || !balances.length) {
        return;
    }

    var form = document.querySelector('[data-quota-max-blocks]');
    var rawMax = form ? form.getAttribute('data-quota-max-blocks') : '';
    var maxBlocks = rawMax === '' ? null : parseInt(rawMax, 10);
    if (maxBlocks !== null && (isNaN(maxBlocks) || maxBlocks < 1)) {
        maxBlocks = null;
    }

    /** demand[blockId][grade] = Summe */
    function collectDemand() {
        var demand = {};
        inputs.forEach(function (input) {
            var block = input.getAttribute('data-block');
            var grade = input.getAttribute('data-grade');
            var value = parseInt(input.value, 10);
            if (isNaN(value) || value < 0) {
                value = 0;
            }
            demand[block] = demand[block] || {};
            demand[block][grade] = (demand[block][grade] || 0) + value;
        });
        return demand;
    }

    function demandOf(demand, block, grade) {
        return (demand[block] && demand[block][grade]) || 0;
    }

    function setBadge(item, sum, available) {
        var sumOut = item.querySelector('[data-quota-sum]');
        if (sumOut) {
            sumOut.textContent = String(sum);
        }
        var availOut = item.querySelector('[data-quota-avail]');
        if (availOut) {
            availOut.textContent = String(available);
        }
        item.classList.toggle('badge-danger', sum > available);
        item.classList.toggle('badge-success', sum > 0 && sum <= available);
    }

    function recalc() {
        var demand = collectDemand();

        balances.forEach(function (panel) {
            var blockId = panel.getAttribute('data-quota-balance');
            var overlapIds = (panel.getAttribute('data-quota-overlaps') || '')
                .split(',')
                .filter(Boolean);

            var totalSum = 0;
            var totalAvail = 0;

            panel.querySelectorAll('[data-quota-grade]').forEach(function (gradeItem) {
                var grade = gradeItem.getAttribute('data-quota-grade');
                var base = parseInt(gradeItem.getAttribute('data-available'), 10) || 0;
                var own = demandOf(demand, blockId, grade);

                var overlapOther = 0;
                overlapIds.forEach(function (id) {
                    overlapOther += demandOf(demand, id, grade);
                });

                var allOther = 0;
                Object.keys(demand).forEach(function (block) {
                    if (block !== blockId) {
                        allOther += demandOf(demand, block, grade);
                    }
                });
                var nonOverlapOther = Math.max(0, allOther - overlapOther);

                var consumed = overlapOther;
                if (maxBlocks !== null) {
                    consumed += Math.floor(nonOverlapOther / maxBlocks);
                }

                var available = Math.max(0, base - consumed);
                setBadge(gradeItem, own, available);
                totalSum += own;
                totalAvail += available;
            });

            var totalItem = panel.querySelector('[data-quota-total]');
            if (totalItem) {
                setBadge(totalItem, totalSum, totalAvail);
            }
        });
    }

    inputs.forEach(function (input) {
        input.addEventListener('input', recalc);
    });
    recalc();
})();
