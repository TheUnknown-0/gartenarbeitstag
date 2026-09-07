<?php
/**
 * Listen & Druck — Übersicht aller PDF-Berichte und Tabellen-Exporte.
 * Erwartet: $day, $stations, $classes, $base, $canPrint.
 */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung · <?= e($day['name']) ?></div>
        <h1 class="page-title">🖨️ Listen &amp; Druck</h1>
        <p class="page-sub">PDF-Berichte und Tabellen-Exporte für den aktuellen Aktionstag.</p>
    </div>
</div>

<?php if (!$canPrint): ?>
    <div class="alert alert-info">
        Du kannst die Übersicht einsehen, aber keine PDF-Berichte erzeugen.
        Dafür wird die Berechtigung „Listen drucken &amp; exportieren“ benötigt.
        Die Tabellen-Exporte (CSV/XLSX) bleiben verfügbar.
    </div>
<?php endif; ?>

<?php $blocks = page_blocks('admin-druck', [
    'staende' => 'Standlisten',
    'klassen' => 'Klassenlisten',
    'belegung' => 'Belegungsübersicht',
    'exporte' => 'Exporte (CSV / XLSX)',
]); ?>
<div class="grid-2">
<?php foreach ($blocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>

<?php if ($blockKey === 'staende'): ?>
    <section class="card">
        <div class="card-header"><h2>🧑‍🌾 Standlisten</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Je Stand eine Seite: Ort, Standleitung, Material und je Zeitblock eine
                Abhak-Tabelle der festen Teilnehmenden mit Warteliste.
            </p>
            <form method="get" action="<?= e($base . '/staende.pdf') ?>" class="stack">
                <div class="field">
                    <label for="pr-stand">Stand</label>
                    <select class="input" id="pr-stand" name="stand">
                        <option value="">Alle aktiven Stände (je Stand eine Seite)</option>
                        <?php foreach ($stations as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?><?= !empty($s['location']) ? ' · ' . e($s['location']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($canPrint): ?>
                    <button class="btn btn-primary" type="submit">PDF erzeugen</button>
                <?php else: ?>
                    <p class="text-faint text-sm">Keine Berechtigung zum Erzeugen.</p>
                <?php endif; ?>
            </form>
        </div>
    </section>

<?php elseif ($blockKey === 'klassen'): ?>
    <section class="card">
        <div class="card-header"><h2>🏫 Klassenlisten</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Für Klassenlehrkräfte: Tabelle Schüler:in × Zeitblock mit Stand und Ort,
                im Querformat. Schüler:innen ganz ohne feste Einschreibung sind fett markiert.
            </p>
            <form method="get" action="<?= e($base . '/klassen.pdf') ?>" class="stack">
                <div class="field">
                    <label for="pr-klasse">Klasse</label>
                    <select class="input" id="pr-klasse" name="klasse">
                        <option value="">Alle Klassen (je Klasse eine Seite)</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= e($c) ?>"><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($canPrint): ?>
                    <button class="btn btn-primary" type="submit">PDF erzeugen</button>
                <?php else: ?>
                    <p class="text-faint text-sm">Keine Berechtigung zum Erzeugen.</p>
                <?php endif; ?>
            </form>
        </div>
    </section>

<?php elseif ($blockKey === 'belegung'): ?>
    <section class="card">
        <div class="card-header"><h2>📊 Belegungsübersicht</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Kompakte Tabelle Stand × Zeitblock: Belegung, Kapazität, Warteliste und
                Mindestbesetzung. Unterbesetzte Zeilen sind fett markiert.
            </p>
            <form method="get" action="<?= e($base . '/belegung.pdf') ?>" class="stack">
                <?php if ($canPrint): ?>
                    <button class="btn btn-primary" type="submit">PDF erzeugen</button>
                <?php else: ?>
                    <p class="text-faint text-sm">Keine Berechtigung zum Erzeugen.</p>
                <?php endif; ?>
            </form>
        </div>
    </section>

<?php elseif ($blockKey === 'exporte'): ?>
    <section class="card" style="grid-column:1 / -1;">
        <div class="card-header"><h2>📁 Exporte (CSV / XLSX)</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Tabellen zum Weiterverarbeiten. CSV ist semikolon-getrennt und
                UTF-8 mit BOM — Excel öffnet es direkt korrekt.
            </p>
            <div class="grid-3">
                <div class="stack">
                    <strong>Einschreibungen</strong>
                    <p class="text-soft text-sm mb-0">Alle Einschreibungen (fest, Warteliste, Wunsch) mit Stand, Block, Status und Quelle.</p>
                    <div class="cluster">
                        <a class="btn btn-ghost btn-sm" href="<?= e($base . '/einschreibungen.csv') ?>">CSV</a>
                        <a class="btn btn-ghost btn-sm" href="<?= e($base . '/einschreibungen.xlsx') ?>">XLSX</a>
                    </div>
                </div>
                <div class="stack">
                    <strong>Offene Einschreibungen</strong>
                    <p class="text-soft text-sm mb-0">Schüler:innen unter der Mindestzahl fester Blöcke.</p>
                    <div class="cluster">
                        <a class="btn btn-ghost btn-sm" href="<?= e($base . '/offen.csv') ?>">CSV</a>
                    </div>
                </div>
                <div class="stack">
                    <strong>Anwesenheit</strong>
                    <p class="text-soft text-sm mb-0">Alle festen Einschreibungen mit Anwesenheitsstatus, Notiz und Markierung.</p>
                    <div class="cluster">
                        <a class="btn btn-ghost btn-sm" href="<?= e($base . '/anwesenheit.csv') ?>">CSV</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<?= block_close() ?>
<?php endforeach; ?>
</div>
