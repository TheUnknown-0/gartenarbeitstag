<?php
/**
 * „Eigene Liste" — freier Report-Baukasten: Datenquelle, Filter, Spalten
 * (inkl. Reihenfolge über Positionsnummern), Gruppierung und Format frei
 * wählbar; Ergebnis als PDF, Zusammenstellung optional als Vorlage speicherbar.
 *
 * Erwartet: $day, $sources, $source, $fields, $groupFields, $columns, $filter,
 * $groupBy, $orientation, $rows, $currentQuery, $templateId, $templates,
 * $classes, $grades, $stations, $blocks, $canPrint, $base.
 */
use App\Services\DayQueries;

$selectedColumns = array_flip($columns);
$sourceFilterFields = [
    'enrollments' => ['stand', 'block', 'klasse', 'stufe', 'status', 'q'],
    'students' => ['klasse', 'stufe', 'aktiv'],
    'stations' => ['q', 'aktiv'],
    'attendance' => ['block', 'stand', 'klasse', 'stufe'],
];
$activeFilters = $sourceFilterFields[$source] ?? [];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung · Listen &amp; Druck</div>
        <h1 class="page-title">🧩 Eigene Liste</h1>
        <p class="page-sub">Datenquelle, Spalten, Reihenfolge, Gruppierung und Format selbst zusammenstellen.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/druck')) ?>">Listen &amp; Druck</a>
    </div>
</div>

<div class="card mb-2">
    <div class="card-body">
        <div class="cluster">
            <?php foreach ($sources as $key => $label): ?>
                <a class="btn <?= $key === $source ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
                   href="<?= e($base . '?quelle=' . urlencode($key)) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<form method="get" action="<?= e($base) ?>" class="stack">
    <input type="hidden" name="quelle" value="<?= e($source) ?>">
    <input type="hidden" name="spalten_submitted" value="1">

    <?php if ($activeFilters !== []): ?>
        <div class="card mb-2">
            <div class="card-header"><h3>Filter</h3></div>
            <div class="card-body form-grid">
                <?php if (in_array('stand', $activeFilters, true)): ?>
                    <div class="field">
                        <label for="f-stand">Stand</label>
                        <select class="input" id="f-stand" name="stand">
                            <option value="0">Alle Stände</option>
                            <?php foreach ($stations as $s): ?>
                                <option value="<?= (int) $s['id'] ?>"<?= (int) $filter['stand'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if (in_array('block', $activeFilters, true)): ?>
                    <div class="field">
                        <label for="f-block">Zeitblock</label>
                        <select class="input" id="f-block" name="block">
                            <option value="0">Alle Blöcke</option>
                            <?php foreach ($blocks as $b): ?>
                                <option value="<?= (int) $b['id'] ?>"<?= (int) $filter['block'] === (int) $b['id'] ? ' selected' : '' ?>><?= e(DayQueries::blockLabel($b)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if (in_array('klasse', $activeFilters, true)): ?>
                    <div class="field">
                        <label for="f-klasse">Klasse</label>
                        <select class="input" id="f-klasse" name="klasse">
                            <option value="">Alle Klassen</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= e($c) ?>"<?= $filter['klasse'] === $c ? ' selected' : '' ?>><?= e($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if (in_array('stufe', $activeFilters, true)): ?>
                    <div class="field">
                        <label for="f-stufe">Stufe</label>
                        <select class="input" id="f-stufe" name="stufe">
                            <option value="0">Alle Stufen</option>
                            <?php foreach ($grades as $g): ?>
                                <option value="<?= (int) $g ?>"<?= (int) $filter['stufe'] === $g ? ' selected' : '' ?>><?= e((string) $g) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if (in_array('status', $activeFilters, true)): ?>
                    <div class="field">
                        <label for="f-status">Status</label>
                        <select class="input" id="f-status" name="status">
                            <?php foreach (['assigned' => 'Fest', 'waitlist' => 'Warteliste', 'wish' => 'Wünsche', 'alle' => 'Alle'] as $key => $label): ?>
                                <option value="<?= e($key) ?>"<?= $filter['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if (in_array('q', $activeFilters, true)): ?>
                    <div class="field">
                        <label for="f-q">Suche</label>
                        <input class="input" type="search" id="f-q" name="q" value="<?= e($filter['q']) ?>">
                    </div>
                <?php endif; ?>
                <?php if (in_array('aktiv', $activeFilters, true)): ?>
                    <div class="field">
                        <label class="checkbox-row mt-2">
                            <input type="checkbox" name="aktiv" value="1"<?= $filter['aktiv'] === '1' ? ' checked' : '' ?>>
                            <span>Nur aktive</span>
                        </label>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card mb-2">
        <div class="card-header"><h3>Spalten &amp; Reihenfolge</h3></div>
        <div class="card-body">
            <p class="text-soft text-sm">Häkchen setzt die Spalte auf die Liste, die Zahl bestimmt die Reihenfolge (kleinere Zahl zuerst).</p>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th></th><th>Feld</th><th style="width:90px;">Position</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fields as $key => $def): ?>
                            <?php $pos = $selectedColumns[$key] ?? null; ?>
                            <tr>
                                <td><input type="checkbox" name="spalten[<?= e($key) ?>]" value="1"<?= $pos !== null ? ' checked' : '' ?>></td>
                                <td><?= e($def['label']) ?></td>
                                <td><input class="input" type="number" min="1" name="position[<?= e($key) ?>]" value="<?= $pos !== null ? (int) $pos + 1 : '' ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-2">
        <div class="card-header"><h3>Gruppierung &amp; Format</h3></div>
        <div class="card-body form-grid">
            <?php if (count($groupFields) > 1): ?>
                <div class="field">
                    <label for="f-gruppieren">Gruppieren nach</label>
                    <select class="input" id="f-gruppieren" name="gruppieren">
                        <?php foreach ($groupFields as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $groupBy === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="field">
                <label>Format</label>
                <div class="cluster">
                    <label class="checkbox-row"><input type="radio" name="format" value="P"<?= $orientation === 'P' ? ' checked' : '' ?>> Hochformat</label>
                    <label class="checkbox-row"><input type="radio" name="format" value="L"<?= $orientation === 'L' ? ' checked' : '' ?>> Querformat</label>
                </div>
            </div>
            <div class="field">
                <label>&nbsp;</label>
                <button class="btn btn-primary" type="submit">Vorschau aktualisieren</button>
            </div>
        </div>
    </div>
</form>

<div class="card mb-2">
    <div class="card-header">
        <h3><?= count($rows) ?> <?= count($rows) === 1 ? 'Eintrag' : 'Einträge' ?> · <?= count($columns) ?> Spalte<?= count($columns) === 1 ? '' : 'n' ?></h3>
        <div class="cluster">
            <?php if ($canPrint): ?>
                <a class="btn btn-primary btn-sm" href="<?= e($base . '.pdf?' . $currentQuery) ?>">📄 Als PDF</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($canPrint): ?>
        <div class="card-body">
            <form method="post" action="<?= e($base . '/speichern?' . $currentQuery) ?>" class="cluster">
                <?= $csrf->field() ?>
                <input type="hidden" name="vorlage_id" value="<?= (int) $templateId ?>">
                <input class="input" type="text" name="name" placeholder="Name der Vorlage" required style="min-width:220px;"
                       value="<?= e((string) ($templates[array_search($templateId, array_column($templates, 'id'), true)]['name'] ?? '')) ?>">
                <button class="btn btn-ghost" type="submit">💾 <?= $templateId > 0 ? 'Vorlage aktualisieren' : 'Als Vorlage speichern' ?></button>
            </form>
        </div>
    <?php endif; ?>
    <?php if ($columns === []): ?>
        <div class="card-body"><div class="empty-state">Bitte mindestens eine Spalte auswählen.</div></div>
    <?php elseif ($rows === []): ?>
        <div class="card-body"><div class="empty-state">Keine Daten für diese Auswahl.</div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $key): ?>
                            <th><?= e($fields[$key]['label'] ?? $key) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($rows, 0, 30) as $row): ?>
                        <tr>
                            <?php foreach ($columns as $key): ?>
                                <td><?= e($row[$key] ?? '') ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($rows) > 30): ?>
            <div class="card-footer text-soft text-sm">Vorschau zeigt die ersten 30 von <?= count($rows) ?> Zeilen — das PDF enthält alle.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($templates !== []): ?>
    <div class="card">
        <div class="card-header"><h3>Gespeicherte Vorlagen</h3></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Datenquelle</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($templates as $t): ?>
                        <tr<?= (int) $t['id'] === $templateId ? ' class="is-active"' : '' ?>>
                            <td><?= e($t['name']) ?></td>
                            <td><?= e($sources[$t['data_source']] ?? $t['data_source']) ?></td>
                            <td class="nowrap">
                                <div class="cluster">
                                    <a class="btn btn-sm btn-ghost" href="<?= e($base . '?vorlage=' . (int) $t['id']) ?>">Laden</a>
                                    <?php if ($canPrint): ?>
                                        <form method="post" action="<?= e($base . '/vorlagen/' . (int) $t['id'] . '/loeschen') ?>" data-confirm="<?= e('Vorlage „' . $t['name'] . '" wirklich löschen?') ?>">
                                            <?= $csrf->field() ?>
                                            <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
