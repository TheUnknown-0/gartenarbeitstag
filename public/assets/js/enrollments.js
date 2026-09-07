/**
 * Einschreibungs-Formulare (Orga): Live-Vorschau der Limit-Verstöße.
 *
 * Erwartet ein <form data-enrollment-form data-check-url="…" [data-ignore-id]
 * data-station-blocks='{"stationId":[blockIds…]}'> mit Feldern
 * [data-check-field] (user_id, station_id, time_block_id) sowie den
 * Anzeige-Elementen [data-check-result], [data-check-ok], [data-check-list],
 * [data-check-occupancy] und [data-block-hint].
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-enrollment-form]');
    if (!form) { return; }

    var url = form.getAttribute('data-check-url');
    var ignoreId = form.getAttribute('data-ignore-id') || '';
    var stationBlocks = {};
    try { stationBlocks = JSON.parse(form.getAttribute('data-station-blocks') || '{}'); } catch (e) { stationBlocks = {}; }

    var result = form.querySelector('[data-check-result]');
    var okBox = form.querySelector('[data-check-ok]');
    var listBox = form.querySelector('[data-check-list]');
    var occupancy = form.querySelector('[data-check-occupancy]');
    var blockHint = form.querySelector('[data-block-hint]');
    var stationSelect = form.querySelector('[data-station-select]');
    var blockSelect = form.querySelector('[data-block-select]');

    var timer = null;
    var seq = 0;

    function value(name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? el.value : '';
    }

    /** Nicht angebotene Blöcke des gewählten Stands ausgrauen. */
    function syncBlocks() {
        if (!stationSelect || !blockSelect) { return; }
        var offered = stationBlocks[stationSelect.value] || null;
        Array.prototype.forEach.call(blockSelect.options, function (opt) {
            if (!opt.value) { return; }
            var ok = offered === null || offered.indexOf(parseInt(opt.value, 10)) !== -1;
            opt.disabled = !ok;
        });
        var current = blockSelect.value;
        var currentOk = !current || offered === null || offered.indexOf(parseInt(current, 10)) !== -1;
        if (blockHint) { blockHint.hidden = currentOk; }
    }

    function render(data) {
        if (!result) { return; }
        result.hidden = false;
        var violations = (data && data.violations) || [];
        if (violations.length === 0) {
            okBox.hidden = false;
            listBox.hidden = true;
            if (occupancy) { occupancy.textContent = data.occupancy ? 'Belegung ' + data.occupancy : ''; }
            return;
        }
        okBox.hidden = true;
        listBox.hidden = false;
        listBox.className = 'alert ' + (data.hard ? 'alert-error' : 'alert-warning');
        listBox.textContent = '';
        var title = document.createElement('strong');
        title.textContent = data.hard ? 'Nicht möglich:' : 'Limits würden überschritten:';
        listBox.appendChild(title);
        var ul = document.createElement('ul');
        ul.className = 'mb-0';
        violations.forEach(function (v) {
            var li = document.createElement('li');
            li.textContent = v.message + (v.hard ? ' (nicht übersteuerbar)' : '');
            ul.appendChild(li);
        });
        listBox.appendChild(ul);
    }

    function check() {
        var user = value('user_id');
        var station = value('station_id');
        var block = value('time_block_id');
        if (!user || !station || !block) {
            if (result) { result.hidden = true; }
            return;
        }
        var mySeq = ++seq;
        var query = '?user=' + encodeURIComponent(user) + '&stand=' + encodeURIComponent(station) + '&block=' + encodeURIComponent(block)
            + (ignoreId ? '&ignore=' + encodeURIComponent(ignoreId) : '');
        BM.fetchJson(url + query, { method: 'GET' }).then(function (data) {
            if (mySeq !== seq) { return; }
            if (!data || data.success === false) {
                if (result) { result.hidden = true; }
                return;
            }
            render(data);
        });
    }

    function schedule() {
        syncBlocks();
        window.clearTimeout(timer);
        timer = window.setTimeout(check, 250);
    }

    form.querySelectorAll('[data-check-field]').forEach(function (el) {
        el.addEventListener('change', schedule);
    });

    syncBlocks();
    check();
})();
