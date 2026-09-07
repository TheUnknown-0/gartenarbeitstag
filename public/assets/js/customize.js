/**
 * Darstellung: Drag & Drop für die Navigations-Anordnung.
 * Listen: ul[data-sortable] mit li[data-key] (enthalten je ein verstecktes
 * order[]-Feld, das mit dem Element wandert, und ein hidden[]-Feld, das
 * per data-toggle-hidden aktiviert/deaktiviert wird). Gespeichert wird
 * beim normalen Absenden des umgebenden Formulars (#nav-editor).
 */
(function () {
    'use strict';

    var dragged = null;

    document.querySelectorAll('[data-sortable]').forEach(function (list) {
        list.addEventListener('dragstart', function (ev) {
            var item = ev.target.closest('.sort-item');
            if (!item) { return; }
            dragged = item;
            item.classList.add('dragging');
            ev.dataTransfer.effectAllowed = 'move';
        });
        list.addEventListener('dragend', function () {
            if (dragged) { dragged.classList.remove('dragging'); }
            dragged = null;
        });
        list.addEventListener('dragover', function (ev) {
            if (!dragged || dragged.parentElement !== list) { return; }
            ev.preventDefault();
            var after = null;
            list.querySelectorAll('.sort-item:not(.dragging)').forEach(function (item) {
                var rect = item.getBoundingClientRect();
                if (ev.clientY < rect.top + rect.height / 2 && after === null) { after = item; }
            });
            if (after === null) { list.appendChild(dragged); }
            else { list.insertBefore(dragged, after); }
        });
    });

    document.addEventListener('click', function (ev) {
        var toggle = ev.target.closest('[data-toggle-hidden]');
        if (!toggle) { return; }

        var item = toggle.closest('.sort-item');
        var hidden = item.classList.toggle('is-hidden');
        var field = item.querySelector('input[name^="hidden["]');
        if (field) { field.disabled = !hidden; }
        toggle.setAttribute('aria-pressed', hidden ? 'true' : 'false');
        toggle.textContent = hidden ? '🚫 ausgeblendet' : '👁 sichtbar';
    });
})();
