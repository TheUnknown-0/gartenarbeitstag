/**
 * Guided Tour — startet AUSSCHLIESSLICH per Knopfdruck (Topbar-Button
 * data-start-tour), nie automatisch. Fortschritt nur lokal (localStorage).
 *
 * Die Schritte zeigen auf stabile Sidebar-Elemente; nicht vorhandene
 * Ziele (fehlende Rechte) werden übersprungen.
 */
(function () {
    'use strict';

    var steps = [
        { sel: '.sidebar-brand', text: 'Willkommen zur Gartenarbeitstag-App! Über das Logo kommst du jederzeit zur Startseite.' },
        { sel: 'a.nav-link[href$="/staende"]', text: 'Hier siehst du alle Stände mit freien Plätzen und Beschreibung.' },
        { sel: 'a.nav-link[href$="/einschreibung"]', text: 'In der Einschreibung wählst du deinen Stand je Zeitblock — oder deine Wünsche mit Priorität.' },
        { sel: 'a.nav-link[href$="/mein-plan"]', text: 'Dein Plan zeigt dir, wo du am Aktionstag eingeteilt bist.' },
        { sel: 'a.nav-link[href$="/meine-staende"]', text: 'Als Standleitung findest du hier deine Teilnehmerlisten und die Anwesenheit.' },
        { sel: 'a.nav-link[href$="/klassen"]', text: 'Klassenübersicht: Wer ist wo eingeschrieben, wer noch nicht?' },
        { sel: 'a.nav-link[href*="/admin/dashboard"]', text: 'Das Dashboard zeigt Auslastung, offene Einschreibungen und Warnungen.' },
        { sel: 'a.nav-link[href*="/admin/staende"]', text: 'Hier verwaltest du Stände, Kapazitäten, Limits, Leitung und Ausschlüsse.' },
        { sel: 'a.nav-link[href*="/admin/einschreibungen"]', text: 'Einschreibungen ansehen, anlegen, umbuchen — mit Limit-Prüfung.' },
        { sel: 'a.nav-link[href*="/admin/benutzer"]', text: 'Benutzerverwaltung inkl. CSV-Import ganzer Klassen und Ausschlusskriterien.' },
        { sel: 'a.nav-link[href*="/admin/aktionstage"]', text: 'Aktionstage, Zeitblöcke, Anmeldemodus und Einschreibezeitraum stellst du hier ein.' },
        { sel: '[data-toggle-theme]', text: 'Zu hell? Zu dunkel? Hier wechselst du das Design.' },
        { sel: '.user-chip', text: 'Über dein Profil kannst du das Passwort ändern und dich abmelden. Viel Erfolg! 🌱' }
    ];

    var overlay = null;
    var tip = null;
    var idx = 0;
    var visible = [];

    function cleanup() {
        if (overlay) { overlay.remove(); overlay = null; }
        if (tip) { tip.remove(); tip = null; }
        document.querySelectorAll('.tour-highlight').forEach(function (el) {
            el.classList.remove('tour-highlight');
            el.style.position = '';
            el.style.zIndex = '';
        });
    }

    function show(i) {
        cleanup();
        if (i < 0 || i >= visible.length) { return; }
        idx = i;
        var step = visible[i];
        var el = step.el;

        overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,20,19,.6);z-index:500;';
        overlay.addEventListener('click', cleanup);
        document.body.appendChild(overlay);

        el.classList.add('tour-highlight');
        if (getComputedStyle(el).position === 'static') { el.style.position = 'relative'; }
        el.style.zIndex = '501';

        var r = el.getBoundingClientRect();
        tip = document.createElement('div');
        tip.setAttribute('role', 'dialog');
        tip.style.cssText = 'position:fixed;z-index:502;max-width:300px;background:var(--surface);color:var(--ink);'
            + 'border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-lg);padding:14px 16px;font-size:.92rem;';

        var text = document.createElement('p');
        text.style.margin = '0 0 10px';
        text.textContent = step.text;

        var row = document.createElement('div');
        row.style.cssText = 'display:flex;gap:8px;justify-content:space-between;align-items:center;';

        var count = document.createElement('span');
        count.style.cssText = 'font-size:.78rem;color:var(--ink-faint);';
        count.textContent = (i + 1) + ' / ' + visible.length;

        var btns = document.createElement('span');
        btns.style.cssText = 'display:flex;gap:6px;';
        [['Beenden', cleanup, ''], ['Zurück', function () { show(idx - 1); }, i === 0 ? 'none' : ''],
         [i === visible.length - 1 ? 'Fertig' : 'Weiter', function () { i === visible.length - 1 ? cleanup() : show(idx + 1); }, '']]
            .forEach(function (def) {
                var b = document.createElement('button');
                b.className = 'btn btn-sm' + (def[0] === 'Weiter' || def[0] === 'Fertig' ? ' btn-primary' : '');
                b.textContent = def[0];
                b.style.display = def[2];
                b.addEventListener('click', def[1]);
                btns.appendChild(b);
            });

        row.appendChild(count);
        row.appendChild(btns);
        tip.appendChild(text);
        tip.appendChild(row);
        document.body.appendChild(tip);

        var top = r.bottom + 10;
        var left = Math.min(Math.max(10, r.left), window.innerWidth - tip.offsetWidth - 10);
        if (top + tip.offsetHeight > window.innerHeight) { top = Math.max(10, r.top - tip.offsetHeight - 10); }
        tip.style.top = top + 'px';
        tip.style.left = left + 'px';

        el.scrollIntoView({ block: 'nearest' });
    }

    function start() {
        visible = steps
            .map(function (s) { return { el: document.querySelector(s.sel), text: s.text }; })
            .filter(function (s) { return s.el !== null && s.el.offsetParent !== null; });
        if (visible.length === 0) { return; }
        try { localStorage.setItem('bm-tour-seen', '1'); } catch (e) { /* egal */ }
        show(0);
    }

    document.addEventListener('click', function (ev) {
        if (ev.target.closest('[data-start-tour]')) { start(); }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { cleanup(); }
    });
})();
