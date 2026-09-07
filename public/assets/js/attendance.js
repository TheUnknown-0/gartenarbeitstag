/**
 * Anwesenheit (Orga/Standleitung): Abhak-Liste live speichern.
 *
 * Erwartet einen Container [data-attendance-endpoint="…"] (Vorlage:
 * templates/pages/attendance/index.php), darin je Zeile
 * tr[data-attendance-row="<enrollment_id>"] mit
 *   - input[type=checkbox][data-enrollment="<id>"]  (an/abwesend)
 *   - span[data-attendance-state]                    (Text "offen"/"da"/"fehlt")
 *   - input[data-enrollment-note="<id>"]              (Notizfeld)
 *   - td[data-attendance-marked]                      (wer/wann markiert)
 * sowie Zähler span[data-attendance-present] / span[data-attendance-open]
 * und optional span[data-attendance-open-badge] (ganzes Badge, wird
 * ausgeblendet, sobald nichts mehr offen ist).
 *
 * Ist der Container mit data-attendance-readonly="1" markiert, wird nicht
 * gespeichert (die Formularfelder sind serverseitig ohnehin disabled).
 */
(function () {
    'use strict';

    var containers = document.querySelectorAll('[data-attendance-endpoint]');
    if (!containers.length) { return; }

    containers.forEach(function (container) {
        if (container.getAttribute('data-attendance-readonly') === '1') { return; }

        var endpoint = container.getAttribute('data-attendance-endpoint');
        if (!endpoint) { return; }

        var presentEl = container.querySelector('[data-attendance-present]');
        var openEl = container.querySelector('[data-attendance-open]');
        var openBadge = container.querySelector('[data-attendance-open-badge]');

        function rowOf(id) {
            return container.querySelector('[data-attendance-row="' + id + '"]');
        }

        function checkboxOf(id) {
            return container.querySelector('[data-enrollment="' + id + '"]');
        }

        /** Zeile visuell auf einen Zustand setzen: 1 = da, 0 = fehlt, null = offen. */
        function applyState(row, present) {
            row.classList.remove('is-present', 'is-absent');
            if (present === true) {
                row.classList.add('is-present');
            } else if (present === false) {
                row.classList.add('is-absent');
            }
            var stateEl = row.querySelector('[data-attendance-state]');
            if (stateEl) {
                stateEl.textContent = present === true ? 'da' : (present === false ? 'fehlt' : 'offen');
            }
        }

        function updateMarked(row, label) {
            var markedEl = row.querySelector('[data-attendance-marked]');
            if (markedEl) {
                markedEl.textContent = label || '–';
            }
        }

        /** Zähler „anwesend / gesamt“ und „offen“ aus dem aktuellen DOM neu berechnen. */
        function recount() {
            var rows = container.querySelectorAll('[data-attendance-row]');
            var present = 0;
            var open = 0;
            rows.forEach(function (row) {
                if (row.classList.contains('is-present')) { present++; }
                if (!row.classList.contains('is-present') && !row.classList.contains('is-absent')) { open++; }
            });
            if (presentEl) { presentEl.textContent = String(present); }
            if (openEl) { openEl.textContent = String(open); }
            if (openBadge) { openBadge.hidden = open === 0; }
        }

        /** Aktuellen Notiztext einer Zeile ermitteln (für den present-Change-Request). */
        function noteOf(id) {
            var field = container.querySelector('[data-enrollment-note="' + id + '"]');
            return field ? field.value : null;
        }

        function send(id, present, note, onError) {
            var payload = { enrollment_id: id, present: present };
            if (note !== null && note !== undefined) {
                payload.note = note;
            }
            return BM.fetchJson(endpoint, { json: payload }).then(function (data) {
                if (!data || data.success !== true) {
                    var message = (data && data.error) ? data.error : 'Anwesenheit konnte nicht gespeichert werden.';
                    BM.flash('error', message);
                    if (onError) { onError(); }
                    return;
                }

                var row = rowOf(id);
                if (row) {
                    applyState(row, data.present === true);
                    updateMarked(row, data.marked_at_label || '');
                }
                recount();
            }, function () {
                BM.flash('error', 'Anwesenheit konnte nicht gespeichert werden (keine Verbindung).');
                if (onError) { onError(); }
            });
        }

        container.addEventListener('change', function (ev) {
            var checkbox = ev.target.closest('[data-enrollment]');
            if (checkbox && checkbox.matches('input[type="checkbox"]')) {
                var id = parseInt(checkbox.getAttribute('data-enrollment'), 10);
                if (!id) { return; }
                var present = checkbox.checked;
                var wasChecked = !present;
                send(id, present, noteOf(id), function () {
                    checkbox.checked = wasChecked;
                });
                return;
            }

            var note = ev.target.closest('[data-enrollment-note]');
            if (note) {
                var noteId = parseInt(note.getAttribute('data-enrollment-note'), 10);
                if (!noteId) { return; }
                var noteCheckbox = checkboxOf(noteId);
                var notePresent = noteCheckbox ? noteCheckbox.checked : false;
                send(noteId, notePresent, note.value);
            }
        });

        // Notiz auch beim Verlassen des Feldes speichern (falls kein change-Event
        // gefeuert wurde, z. B. bei unverändertem, aber neu fokussiertem Feld).
        container.addEventListener('blur', function (ev) {
            var note = ev.target.closest && ev.target.closest('[data-enrollment-note]');
            if (!note) { return; }
            var noteId = parseInt(note.getAttribute('data-enrollment-note'), 10);
            if (!noteId) { return; }
            var noteCheckbox = checkboxOf(noteId);
            var notePresent = noteCheckbox ? noteCheckbox.checked : false;
            send(noteId, notePresent, note.value);
        }, true);
    });
})();
