<?php
/**
 * Anwesenheit (Orga): Auswahl Block + Stand/Klasse → Abhak-Liste; ohne Auswahl Übersicht.
 * Erwartet: $day, $blocks, $stations, $classes, $grades, $grade, $block, $station, $class, $mode, $roster, $summary, $canEdit.
 */
use App\Services\DayQueries;

$selfUrl = $ctx->url('/admin/anwesenheit');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung · <?= e($day['name']) ?></div>
        <h1 class="page-title">Anwesenheit</h1>
        <p class="page-sub">Abhaken, wer da ist — je Stand und Zeitblock oder klassenweise.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/druck/anwesenheit.csv')) ?>">CSV-Export</a>
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/druck/anwesenheit.pdf')) ?>">📄 Als PDF (alle)</a>
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/druck')) ?>">Listen &amp; Druck</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="get" action="<?= e($selfUrl) ?>" class="form-grid">
            <div class="field">
                <label for="block">Zeitblock</label>
                <select class="input" id="block" name="block">
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($blocks as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"<?= $block !== null && (int) $block['id'] === (int) $b['id'] ? ' selected' : '' ?>><?= e(DayQueries::blockLabel($b)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="stand">Stand</label>
                <select class="input" id="stand" name="stand">
                    <option value="">– alle / keiner –</option>
                    <?php foreach ($stations as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"<?= $station !== null && (int) $station['id'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?><?= !empty($s['location']) ? ' · ' . e($s['location']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="klasse">oder Klasse</label>
                <select class="input" id="klasse" name="klasse">
                    <option value="">–</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= e($c) ?>"<?= $class === $c ? ' selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="hint">Wird nur genutzt, wenn kein Stand gewählt ist.</div>
            </div>
            <div class="field">
                <label for="stufe">Stufe</label>
                <select class="input" id="stufe" name="stufe">
                    <option value="0">Alle Stufen</option>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?= (int) $g ?>"<?= $grade === $g ? ' selected' : '' ?>><?= e((string) $g) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="hint">Wird nur für den PDF-Export genutzt.</div>
            </div>
            <div class="field">
                <label>&nbsp;</label>
                <div class="cluster">
                    <button class="btn btn-primary" type="submit">Anzeigen</button>
                    <a class="btn btn-ghost" href="<?= e($selfUrl) ?>">Übersicht</a>
                    <button class="btn btn-ghost" type="submit" formaction="<?= e($ctx->url('/admin/druck/anwesenheit.pdf')) ?>">📄 Als PDF</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($roster !== null): ?>
    <?php
    $presentCount = count(array_filter($roster, static fn (array $r): bool => (int) ($r['present'] ?? -1) === 1));
    $openCount = count(array_filter($roster, static fn (array $r): bool => $r['present'] === null));
    ?>
    <div class="card mt-2"
         data-attendance-endpoint="<?= e($ctx->url('/api/anwesenheit')) ?>"
         data-attendance-readonly="<?= $canEdit ? '0' : '1' ?>">
        <div class="card-header">
            <h3>
                <?php if ($mode === 'station'): ?>
                    <?= e($station['name']) ?><?= !empty($station['location']) ? ' <span class="text-soft">· ' . e($station['location']) . '</span>' : '' ?>
                <?php else: ?>
                    Klasse <?= e($class) ?>
                <?php endif; ?>
                <span class="text-soft">— <?= e(DayQueries::blockLabel($block)) ?></span>
            </h3>
            <span class="badge badge-success"><span data-attendance-present><?= $presentCount ?></span> / <?= count($roster) ?> anwesend</span>
            <?php if ($openCount > 0): ?><span class="badge" data-attendance-open-badge><span data-attendance-open><?= $openCount ?></span> offen</span><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($roster === []): ?>
                <div class="empty-state">
                    <h3>Keine festen Einschreibungen</h3>
                    <p class="text-soft">Für diese Auswahl ist niemand fest eingetragen.</p>
                </div>
            <?php else: ?>
                <form method="post" action="<?= e($ctx->url('/admin/anwesenheit/speichern')) ?>" data-attendance-form>
                    <?= $csrf->field() ?>
                    <input type="hidden" name="block" value="<?= (int) $block['id'] ?>">
                    <?php if ($mode === 'station'): ?>
                        <input type="hidden" name="stand" value="<?= (int) $station['id'] ?>">
                    <?php else: ?>
                        <input type="hidden" name="klasse" value="<?= e($class) ?>">
                    <?php endif; ?>

                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="nowrap">anwesend</th>
                                    <th>Name</th>
                                    <th>Klasse</th>
                                    <?php if ($mode === 'class'): ?><th>Stand</th><?php endif; ?>
                                    <th>Notiz</th>
                                    <th class="nowrap">markiert</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($roster as $row): ?>
                                    <?php $eid = (int) $row['enrollment_id']; $present = $row['present']; ?>
                                    <tr data-attendance-row="<?= $eid ?>"<?= $present === null ? '' : ((int) $present === 1 ? ' class="is-present"' : ' class="is-absent"') ?>>
                                        <td>
                                            <input type="hidden" name="ids[]" value="<?= $eid ?>">
                                            <label class="checkbox-row mb-0">
                                                <input type="checkbox" name="present[]" value="<?= $eid ?>" data-enrollment="<?= $eid ?>"
                                                    <?= (int) ($present ?? 0) === 1 ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>
                                                    aria-label="<?= e(DayQueries::personName($row)) ?> anwesend">
                                                <span class="text-sm text-soft" data-attendance-state><?= $present === null ? 'offen' : ((int) $present === 1 ? 'da' : 'fehlt') ?></span>
                                            </label>
                                        </td>
                                        <td><strong><?= e(DayQueries::personName($row)) ?></strong><br><span class="text-faint text-sm"><?= e($row['username']) ?></span></td>
                                        <td><?= e((string) $row['class']) ?></td>
                                        <?php if ($mode === 'class'): ?>
                                            <td><?= e($row['station']) ?><?= !empty($row['location']) ? '<br><span class="text-sm text-soft">' . e($row['location']) . '</span>' : '' ?></td>
                                        <?php endif; ?>
                                        <td>
                                            <?php if ($canEdit): ?>
                                                <input class="input" type="text" name="note[<?= $eid ?>]" value="<?= e((string) ($row['note'] ?? '')) ?>" maxlength="255" placeholder="Notiz …" data-enrollment-note="<?= $eid ?>">
                                            <?php else: ?>
                                                <?= e((string) ($row['note'] ?? '')) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-sm text-soft nowrap" data-attendance-marked>
                                            <?php if (!empty($row['marked_at'])): ?>
                                                <?= e(format_datetime($row['marked_at'])) ?><?= !empty($row['marked_by_name']) ? '<br>' . e($row['marked_by_name']) : '' ?>
                                            <?php else: ?>
                                                –
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($canEdit): ?>
                        <div class="cluster mt-2" data-attendance-nojs>
                            <button class="btn btn-primary" type="submit">Anwesenheit speichern</button>
                            <span class="hint">Mit aktiviertem JavaScript wird jede Änderung sofort gespeichert.</span>
                        </div>
                    <?php else: ?>
                        <p class="hint mt-2">Du hast keine Berechtigung, die Anwesenheit zu ändern.</p>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <?php
    $totals = ['total' => 0, 'present' => 0, 'absent' => 0];
    foreach ($summary as $s) {
        $totals['total'] += (int) $s['total'];
        $totals['present'] += (int) $s['present'];
        $totals['absent'] += (int) $s['absent'];
    }
    ?>
    <div class="stat-grid mt-2">
        <div class="stat-card"><div class="stat-value"><?= $totals['total'] ?></div><div class="stat-label">feste Plätze</div></div>
        <div class="stat-card"><div class="stat-value text-success"><?= $totals['present'] ?></div><div class="stat-label">anwesend</div></div>
        <div class="stat-card"><div class="stat-value text-danger"><?= $totals['absent'] ?></div><div class="stat-label">fehlend</div></div>
        <div class="stat-card"><div class="stat-value"><?= $totals['total'] - $totals['present'] - $totals['absent'] ?></div><div class="stat-label">noch offen</div></div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Übersicht Stand × Zeitblock</h3></div>
        <div class="card-body">
            <?php if ($summary === []): ?>
                <div class="empty-state"><h3>Keine aktiven Stände</h3><p class="text-soft">Für diesen Aktionstag sind keine aktiven Stände mit Zeitblöcken angelegt.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Zeitblock</th>
                                <th>Stand</th>
                                <th>Ort</th>
                                <th class="nowrap">anwesend / fest</th>
                                <th>fehlend</th>
                                <th>offen</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summary as $s): ?>
                                <?php $open = (int) $s['total'] - (int) $s['present'] - (int) $s['absent']; ?>
                                <tr>
                                    <td class="nowrap"><?= e($s['block']) ?></td>
                                    <td><?= e($s['station']) ?></td>
                                    <td class="text-soft"><?= e((string) ($s['location'] ?? '')) ?></td>
                                    <td class="nowrap">
                                        <?= (int) $s['present'] ?> / <?= (int) $s['total'] ?>
                                        <?php if ((int) $s['total'] > 0): ?>
                                            <div class="progress" style="width:90px;display:inline-block;vertical-align:middle;margin-left:6px;"><span style="width:<?= (int) round((int) $s['present'] / (int) $s['total'] * 100) ?>%"></span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int) $s['absent'] > 0 ? '<span class="badge badge-danger">' . (int) $s['absent'] . '</span>' : '0' ?></td>
                                    <td><?= $open > 0 ? '<span class="badge badge-warning">' . $open . '</span>' : '0' ?></td>
                                    <td class="nowrap"><a class="btn btn-sm btn-ghost" href="<?= e($selfUrl . '?block=' . (int) $s['block_id'] . '&stand=' . (int) $s['station_id']) ?>">Liste</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
