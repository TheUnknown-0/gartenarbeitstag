<?php
/**
 * Stände-Übersicht eines Aktionstags.
 * Erwartet: $day, $days, $stations, $blocks, $occupancy, $criteria, $leaders, $q, $onlyActive, $tagQuery.
 */

use App\Core\Permissions as P;

$canCreate = $auth->can(P::STAENDE_ERSTELLEN);
$canEdit = $auth->can(P::STAENDE_BEARBEITEN);
$canDelete = $auth->can(P::STAENDE_LOESCHEN);
$isActiveDay = $ctx->activeDayId() === (int) $day['id'];
$base = $ctx->url('/admin/staende');
$blocksLayout = page_blocks('admin-staende', [
    'filter' => 'Filter',
    'list' => 'Liste',
]);
$timeShort = static fn (?string $t): string => $t === null || $t === '' ? '' : substr($t, 0, 5);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">🌱 Stände</h1>
        <p class="page-sub">
            <?= e($day['name']) ?>
            <?php if (!empty($day['event_date'])): ?> · <?= e(format_date($day['event_date'])) ?><?php endif; ?>
            <?php if ($isActiveDay): ?><span class="badge badge-success">Aktiver Aktionstag</span>
            <?php else: ?><span class="badge badge-info">Entwurf — nicht der aktive Aktionstag</span><?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <?php if ($canCreate): ?>
            <a class="btn btn-primary" href="<?= e($base . '/neu' . $tagQuery) ?>">➕ Neuer Stand</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($blocks === []): ?>
    <div class="alert alert-warning">
        Dieser Aktionstag hat noch keine Zeitblöcke — Stände können erst angeboten werden, wenn mindestens ein Zeitblock existiert.
        <?php if ($auth->can(P::AKTIONSTAGE_BEARBEITEN)): ?>
            <a href="<?= e($ctx->url('/admin/aktionstage/' . $day['id'])) ?>">Zeitblöcke anlegen</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($blocksLayout as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'filter'): ?>
    <div class="card mb-2">
        <div class="card-body">
            <form method="get" action="<?= e($base) ?>" class="form-grid" data-live="stations">
                <?php if (count($days) > 1): ?>
                    <div class="field">
                        <label for="f-tag">Aktionstag</label>
                        <select class="input" id="f-tag" name="tag">
                            <?php foreach ($days as $d): ?>
                                <option value="<?= e((string) $d['id']) ?>" <?= (int) $d['id'] === (int) $day['id'] ? 'selected' : '' ?>>
                                    <?= e($d['name']) ?><?= $d['status'] === 'active' ? ' (aktiv)' : ' (Entwurf)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif ($tagQuery !== ''): ?>
                    <input type="hidden" name="tag" value="<?= e((string) $day['id']) ?>">
                <?php endif; ?>
                <div class="field">
                    <label for="f-q">Suche</label>
                    <input class="input" type="search" id="f-q" name="q" value="<?= e($q) ?>" placeholder="Name, Ort, Beschreibung">
                </div>
                <div class="field">
                    <label class="checkbox-row mt-2">
                        <input type="checkbox" name="aktiv" value="1" <?= $onlyActive ? 'checked' : '' ?>>
                        <span>Nur aktive Stände</span>
                    </label>
                </div>
                <div class="field">
                    <label>&nbsp;</label>
                    <div class="cluster">
                        <button class="btn" type="submit">Filtern</button>
                        <a class="btn btn-ghost" href="<?= e($base . $tagQuery) ?>">Zurücksetzen</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php elseif ($blockKey === 'list'): ?>
    <div data-live-target="stations">
    <div class="cluster mb-2" style="justify-content:space-between;">
        <span class="text-soft text-sm"><?= count($stations) ?> Stände</span>
        <?php if ($auth->can(P::BERICHTE_DRUCKEN)): ?>
            <a class="btn btn-ghost btn-sm" href="<?= e($ctx->url('/admin/druck/staende-uebersicht.pdf') . '?' . http_build_query(array_filter(['q' => $q, 'aktiv' => $onlyActive ? '1' : '', 'tag' => $tagQuery !== '' ? (int) $day['id'] : ''], static fn ($v) => $v !== ''))) ?>">📄 Als PDF</a>
        <?php endif; ?>
    </div>
    <?php if ($stations === []): ?>
        <div class="card"><div class="empty-state">Keine Stände gefunden.</div></div>
    <?php else: ?>
        <div class="card">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Stand</th>
                            <th>Ort</th>
                            <?php foreach ($blocks as $block): ?>
                                <th class="nowrap" title="<?= e($timeShort($block['start_time']) . ($block['end_time'] ? '–' . $timeShort($block['end_time']) : '')) ?>"><?= e($block['name']) ?></th>
                            <?php endforeach; ?>
                            <th>Limits</th>
                            <th>Standleitung</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stations as $station): ?>
                        <?php
                        $sid = (int) $station['id'];
                        $occ = $occupancy[$sid] ?? [];
                        $totalAssigned = array_sum(array_column($occ, 'assigned'));
                        $offered = count($occ);
                        $underMin = (int) $station['min_students'] > 0 && $offered > 0
                            && array_filter($occ, static fn (array $o): bool => $o['assigned'] < (int) $station['min_students']) !== [];
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($station['name']) ?></strong>
                                <?php if ((int) $station['is_active'] !== 1): ?> <span class="badge">Inaktiv</span><?php endif; ?>
                                <?php if ($underMin): ?>
                                    <span class="badge badge-warning" title="Mindestbesetzung <?= e((string) $station['min_students']) ?> in mindestens einem Zeitblock nicht erreicht">⚠ unter Mindestbesetzung</span>
                                <?php endif; ?>
                                <?php if (!empty($criteria[$sid])): ?>
                                    <div class="chip-row mt-2">
                                        <?php foreach ($criteria[$sid] as $criterionName): ?>
                                            <span class="badge badge-danger" title="Ausschlusskriterium">🚫 <?= e($criterionName) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($station['location'] ?? '–') ?></td>
                            <?php foreach ($blocks as $block): ?>
                                <?php $o = $occ[(int) $block['id']] ?? null; ?>
                                <td class="nowrap">
                                    <?php if ($o === null): ?>
                                        <span class="text-faint">–</span>
                                    <?php else: ?>
                                        <?php $full = $o['assigned'] >= $o['capacity']; ?>
                                        <span class="<?= $full ? 'text-danger' : '' ?>"><?= e((string) $o['assigned']) ?>/<?= e((string) $o['capacity']) ?></span>
                                        <?php if ($o['waitlist'] > 0): ?>
                                            <span class="badge badge-warning" title="Warteliste">+<?= e((string) $o['waitlist']) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-sm">
                                <?php
                                $limits = [];
                                if ($station['max_per_class'] !== null) {
                                    $limits[] = 'max. ' . (int) $station['max_per_class'] . '/Klasse';
                                }
                                if ($station['max_per_grade'] !== null) {
                                    $limits[] = 'max. ' . (int) $station['max_per_grade'] . '/Stufe';
                                }
                                if (!empty($station['allowed_grades'])) {
                                    $limits[] = 'Stufen: ' . $station['allowed_grades'];
                                }
                                if (!empty($station['allowed_classes'])) {
                                    $limits[] = 'Klassen: ' . $station['allowed_classes'];
                                }
                                if ((int) $station['min_students'] > 0) {
                                    $limits[] = 'min. ' . (int) $station['min_students'];
                                }
                                ?>
                                <?= $limits === [] ? '<span class="text-faint">–</span>' : e(implode(' · ', $limits)) ?>
                            </td>
                            <td class="text-sm"><?= empty($leaders[$sid]) ? '<span class="text-faint">–</span>' : e(implode(', ', $leaders[$sid])) ?></td>
                            <td class="nowrap">
                                <div class="cluster">
                                    <a class="btn btn-sm btn-ghost" href="<?= e($base . '/' . $sid . '/teilnehmer') ?>">Teilnehmende<?= $totalAssigned > 0 ? ' (' . e((string) $totalAssigned) . ')' : '' ?></a>
                                    <?php if ($canEdit): ?>
                                        <a class="btn btn-sm" href="<?= e($base . '/' . $sid . $tagQuery) ?>">Bearbeiten</a>
                                        <form method="post" action="<?= e($base . '/' . $sid . '/aktiv') ?>">
                                            <?= $csrf->field() ?>
                                            <button class="btn btn-sm btn-ghost" type="submit"><?= (int) $station['is_active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canDelete && $totalAssigned === 0 && array_sum(array_column($occ, 'waitlist')) === 0): ?>
                                        <form method="post" action="<?= e($base . '/' . $sid . '/loeschen') ?>" data-confirm="Stand „<?= e($station['name']) ?>“ löschen?">
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
    </div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
