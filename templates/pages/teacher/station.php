<?php
/**
 * Stand-Detailseite (Standleitung / Lehrkraft).
 * Erwartet: $day, $station, $leaders, $blocks (id => Zeitblock),
 *           $offered (blockId => Zeitblock + capacity/assigned/waitlist — nur angebotene Blöcke),
 *           $participants (blockId => list fest Eingeschriebener: id, user_id, time_block_id,
 *                          status, source, created_at, firstname, lastname, class, present, note, marked_at),
 *           $waitlist (blockId => list Wartelisten-Einträge, gleiche Felder),
 *           $canMark (Anwesenheit erfassen), $canEdit (Ein-/Austragen, Nachrücken),
 *           $students (Klasse => list, nur wenn $canEdit).
 *
 * Anwesenheits-Markup ist bewusst identisch zu templates/pages/attendance/index.php
 * (Container [data-attendance-endpoint], input[data-enrollment], Notizfelder
 * [data-enrollment-note], Zähler [data-attendance-present]/[data-attendance-open]),
 * damit public/assets/js/attendance.js unverändert greift. Der Controller liefert
 * hier keinen Benutzernamen und keine „markiert von“-Angabe — beides fehlt daher
 * gegenüber der Verwaltungsseite.
 */
use App\Services\DayQueries;

$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
$minStudents = $station['min_students'] !== null ? (int) $station['min_students'] : null;
$attendanceEndpoint = $ctx->url('/api/anwesenheit');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a href="<?= e($ctx->url('/meine-staende')) ?>">← Meine Stände</a> · <?= e($day['name']) ?></div>
        <h1 class="page-title"><?= e($station['name']) ?></h1>
        <?php if (!empty($station['location'])): ?>
            <p class="page-sub"><span aria-hidden="true">📍</span> <?= e($station['location']) ?></p>
        <?php endif; ?>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->url('/meine-staende/' . (int) $station['id'] . '/liste.pdf')) ?>">🖨️ Liste als PDF</a>
    </div>
</div>

<div class="card card-pad mb-2">
    <?php if ($leaders !== []): ?>
        <div class="chip-row">
            <?php foreach ($leaders as $leader): ?>
                <span class="badge badge-primary"><?= e(trim($leader['firstname'] . ' ' . $leader['lastname'])) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($station['description'])): ?>
        <p><?= nl2br(e($station['description'])) ?></p>
    <?php endif; ?>
    <?php if (!empty($station['materials'])): ?>
        <p class="text-soft text-sm"><span aria-hidden="true">🧤</span> <?= nl2br(e($station['materials'])) ?></p>
    <?php endif; ?>
</div>

<?php if ($canEdit): ?>
    <div class="card mb-2">
        <div class="card-header"><h3>Schüler:in eintragen</h3></div>
        <div class="card-body">
            <?php if ($offered === []): ?>
                <p class="text-soft">Für diesen Stand sind keine Zeitblöcke hinterlegt.</p>
            <?php else: ?>
                <form method="post" action="<?= e($ctx->url('/meine-staende/' . (int) $station['id'] . '/einschreiben')) ?>" class="form-grid">
                    <?= $csrf->field() ?>
                    <div class="field">
                        <label for="enroll-user">Schüler:in</label>
                        <select class="input" id="enroll-user" name="user_id" required
                                data-combobox="Name oder Klasse eintippen …">
                            <option value="">– bitte wählen –</option>
                            <?php foreach ($students as $class => $group): ?>
                                <optgroup label="<?= e($class) ?>">
                                    <?php foreach ($group as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>"><?= e(DayQueries::personName($s)) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="enroll-block">Zeitblock</label>
                        <select class="input" id="enroll-block" name="time_block_id" required>
                            <option value="">– bitte wählen –</option>
                            <?php foreach ($offered as $blockId => $block): ?>
                                <option value="<?= (int) $blockId ?>"><?= e(DayQueries::blockLabel($block)) ?> (<?= (int) $block['assigned'] ?>/<?= (int) $block['capacity'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>&nbsp;</label>
                        <button class="btn btn-primary" type="submit">Eintragen</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($offered === []): ?>
    <div class="card"><div class="empty-state">Dieser Stand wird in keinem Zeitblock angeboten.</div></div>
<?php endif; ?>

<?php foreach ($offered as $blockId => $block): ?>
    <?php
    $rows = $participants[$blockId] ?? [];
    $waiting = $waitlist[$blockId] ?? [];
    $presentCount = count(array_filter($rows, static fn (array $r): bool => (int) ($r['present'] ?? -1) === 1));
    $openCount = count(array_filter($rows, static fn (array $r): bool => $r['present'] === null));
    $underMin = $minStudents !== null && (int) $block['assigned'] < $minStudents;
    ?>
    <div class="card mb-2" id="block-<?= (int) $blockId ?>">
        <div class="card-header">
            <h3><?= e($block['name']) ?> <span class="text-soft text-sm"><?= e($time($block['start_time'])) ?>–<?= e($time($block['end_time'])) ?></span></h3>
            <span class="badge <?= (int) $block['assigned'] >= (int) $block['capacity'] ? 'badge-danger' : 'badge-success' ?>"><?= (int) $block['assigned'] ?> / <?= (int) $block['capacity'] ?> belegt</span>
            <?php if ($rows !== []): ?>
                <span class="badge badge-success"><span data-attendance-present><?= $presentCount ?></span> / <?= count($rows) ?> anwesend</span>
                <?php if ($openCount > 0): ?><span class="badge" data-attendance-open-badge><span data-attendance-open><?= $openCount ?></span> offen</span><?php endif; ?>
            <?php endif; ?>
            <?php if ($underMin): ?>
                <span class="badge badge-warning">unter Mindestbesetzung (<?= $minStudents ?>)</span>
            <?php endif; ?>
        </div>
        <div class="card-body" data-attendance-endpoint="<?= e($attendanceEndpoint) ?>" data-attendance-readonly="<?= $canMark ? '0' : '1' ?>">
            <?php if ($rows === []): ?>
                <div class="empty-state"><p class="text-soft mb-0">Noch niemand fest eingetragen.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="nowrap">anwesend</th>
                                <th>Name</th>
                                <th>Klasse</th>
                                <th>Notiz</th>
                                <th class="nowrap">markiert</th>
                                <?php if ($canEdit): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $eid = (int) $row['id']; $present = $row['present']; ?>
                                <tr data-attendance-row="<?= $eid ?>"<?= $present === null ? '' : ((int) $present === 1 ? ' class="is-present"' : ' class="is-absent"') ?>>
                                    <td>
                                        <label class="checkbox-row mb-0">
                                            <input type="checkbox" name="present[]" value="<?= $eid ?>" data-enrollment="<?= $eid ?>"
                                                <?= (int) ($present ?? 0) === 1 ? ' checked' : '' ?><?= $canMark ? '' : ' disabled' ?>
                                                aria-label="<?= e(DayQueries::personName($row)) ?> anwesend">
                                            <span class="text-sm text-soft" data-attendance-state><?= $present === null ? 'offen' : ((int) $present === 1 ? 'da' : 'fehlt') ?></span>
                                        </label>
                                    </td>
                                    <td><strong><?= e(DayQueries::personName($row)) ?></strong></td>
                                    <td><?= e((string) $row['class']) ?></td>
                                    <td>
                                        <?php if ($canMark): ?>
                                            <input class="input" type="text" name="note[<?= $eid ?>]" value="<?= e((string) ($row['note'] ?? '')) ?>" maxlength="255" placeholder="Notiz …" data-enrollment-note="<?= $eid ?>">
                                        <?php else: ?>
                                            <?= e((string) ($row['note'] ?? '')) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-sm text-soft nowrap" data-attendance-marked>
                                        <?= !empty($row['marked_at']) ? e(format_datetime($row['marked_at'])) : '–' ?>
                                    </td>
                                    <?php if ($canEdit): ?>
                                        <td class="nowrap">
                                            <form method="post" action="<?= e($ctx->url('/meine-staende/' . (int) $station['id'] . '/einschreibungen/' . $eid . '/austragen')) ?>" data-confirm="<?= e(DayQueries::personName($row)) ?> wirklich austragen?">
                                                <?= $csrf->field() ?>
                                                <button class="btn btn-sm btn-danger-ghost" type="submit">Austragen</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($waiting !== []): ?>
        <div class="card mb-2">
            <div class="card-header"><h3>Warteliste</h3><span class="badge badge-warning"><?= count($waiting) ?></span></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Klasse</th>
                            <th>seit</th>
                            <?php if ($canEdit): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($waiting as $row): ?>
                            <?php $eid = (int) $row['id']; ?>
                            <tr>
                                <td><em><?= e(DayQueries::personName($row)) ?></em></td>
                                <td><?= e((string) $row['class']) ?></td>
                                <td class="text-soft text-sm"><?= e(format_datetime($row['created_at'])) ?></td>
                                <?php if ($canEdit): ?>
                                    <td class="nowrap">
                                        <div class="cluster">
                                            <form method="post" action="<?= e($ctx->url('/meine-staende/' . (int) $station['id'] . '/einschreibungen/' . $eid . '/nachruecken')) ?>">
                                                <?= $csrf->field() ?>
                                                <button class="btn btn-sm btn-primary" type="submit">Nachrücken</button>
                                            </form>
                                            <form method="post" action="<?= e($ctx->url('/meine-staende/' . (int) $station['id'] . '/einschreibungen/' . $eid . '/austragen')) ?>" data-confirm="<?= e(DayQueries::personName($row)) ?> von der Warteliste austragen?">
                                                <?= $csrf->field() ?>
                                                <button class="btn btn-sm btn-ghost" type="submit">Austragen</button>
                                            </form>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
