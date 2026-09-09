<?php
/**
 * Einschreibungen — Übersicht mit Filter.
 * Erwartet: $day, $rows, $stats, $filter, $stations, $blocks, $classes, $grades, $statusLabels, $canEdit.
 */
use App\Services\DayQueries;

$badgeClass = ['assigned' => 'badge-success', 'waitlist' => 'badge-warning', 'wish' => 'badge-info'];
$sourceLabels = ['self' => 'selbst', 'auto' => 'automatisch', 'orga' => 'Orga', 'quota' => 'Quote'];
$currentUrl = $ctx->url('/admin/einschreibungen') . '?' . http_build_query(array_filter($filter, static fn ($v) => $v !== '' && $v !== 0));
$blocksLayout = page_blocks('admin-einschreibungen', [
    'stats' => 'Kennzahlen',
    'filter' => 'Filter',
    'list' => 'Liste',
]);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">📝 Einschreibungen</h1>
        <p class="page-sub"><?= e($day['name']) ?> · <?= e(format_date($day['event_date'])) ?> · Modus: <?= e(['direct' => 'Sofortbuchung', 'wishlist' => 'Wunschliste', 'quota' => 'Quote'][$day['mode']] ?? $day['mode']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen/offen')) ?>">Offene Einschreibungen</a>
        <?php if ($canEdit): ?>
            <a class="btn btn-primary" href="<?= e($ctx->url('/admin/einschreibungen/neu')) ?>">+ Einschreibung anlegen</a>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($blocksLayout as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'stats'): ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value"><?= e((string) (int) ($stats['assigned'] ?? 0)) ?></div>
            <div class="stat-label">Feste Einschreibungen</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= e((string) (int) ($stats['waitlist'] ?? 0)) ?></div>
            <div class="stat-label">Warteliste</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= e((string) (int) ($stats['wish'] ?? 0)) ?></div>
            <div class="stat-label">Wünsche</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= e((string) $stats['under']) ?> <span class="text-soft text-sm">/ <?= e((string) $stats['students']) ?></span></div>
            <div class="stat-label">Schüler:innen unter Mindestzahl Blöcke</div>
        </div>
    </div>
<?php elseif ($blockKey === 'filter'): ?>
    <div class="card mb-2">
        <div class="card-body">
            <form method="get" action="<?= e($ctx->url('/admin/einschreibungen')) ?>" class="form-grid" data-live="enrollments">
                <div class="field">
                    <label for="f-stand">Stand</label>
                    <select class="input" id="f-stand" name="stand">
                        <option value="">Alle Stände</option>
                        <?php foreach ($stations as $station): ?>
                            <option value="<?= (int) $station['id'] ?>"<?= $filter['stand'] === (int) $station['id'] ? ' selected' : '' ?>><?= e($station['name']) ?><?= (int) $station['is_active'] === 0 ? ' (inaktiv)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f-block">Zeitblock</label>
                    <select class="input" id="f-block" name="block">
                        <option value="">Alle Blöcke</option>
                        <?php foreach ($blocks as $block): ?>
                            <option value="<?= (int) $block['id'] ?>"<?= $filter['block'] === (int) $block['id'] ? ' selected' : '' ?>><?= e(DayQueries::blockLabel($block)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f-klasse">Klasse</label>
                    <select class="input" id="f-klasse" name="klasse">
                        <option value="">Alle Klassen</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= e($class) ?>"<?= $filter['klasse'] === $class ? ' selected' : '' ?>><?= e($class) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f-stufe">Stufe</label>
                    <select class="input" id="f-stufe" name="stufe">
                        <option value="0">Alle Stufen</option>
                        <?php foreach ($grades as $g): ?>
                            <option value="<?= (int) $g ?>"<?= $filter['stufe'] === $g ? ' selected' : '' ?>><?= e((string) $g) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f-status">Status</label>
                    <select class="input" id="f-status" name="status">
                        <?php foreach (['assigned' => 'Fest', 'waitlist' => 'Warteliste', 'wish' => 'Wünsche', 'alle' => 'Alle'] as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $filter['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f-q">Suche</label>
                    <input class="input" type="search" id="f-q" name="q" value="<?= e($filter['q']) ?>" placeholder="Name oder Benutzername">
                </div>
                <div class="field">
                    <label>&nbsp;</label>
                    <div class="cluster">
                        <button class="btn btn-primary" type="submit">Filtern</button>
                        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen')) ?>">Zurücksetzen</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php elseif ($blockKey === 'list'): ?>
    <div data-live-target="enrollments">
    <div class="card">
        <div class="card-header">
            <h3><?= count($rows) ?> Einträge</h3>
            <a class="btn btn-ghost btn-sm" href="<?= e($ctx->url('/admin/druck/einschreibungen.pdf') . '?' . http_build_query(array_filter($filter, static fn ($v) => $v !== '' && $v !== 0))) ?>">📄 Als PDF</a>
        </div>
        <?php if ($rows === []): ?>
            <div class="card-body">
                <div class="empty-state">Keine Einschreibungen für diese Auswahl.</div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Schüler:in</th>
                            <th>Klasse</th>
                            <th>Stand</th>
                            <th>Zeitblock</th>
                            <th>Status</th>
                            <th>Quelle</th>
                            <th>Angelegt</th>
                            <?php if ($canEdit): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= e(DayQueries::personName($row)) ?></strong>
                                    <div class="text-soft text-sm"><?= e($row['username']) ?></div>
                                </td>
                                <td class="nowrap"><?= e((string) $row['class']) ?><?= $row['grade'] !== null ? ' <span class="text-soft text-sm">(' . (int) $row['grade'] . ')</span>' : '' ?></td>
                                <td>
                                    <?= e($row['station_name']) ?>
                                    <?php if (!empty($row['location'])): ?><div class="text-soft text-sm"><?= e($row['location']) ?></div><?php endif; ?>
                                </td>
                                <td class="nowrap"><?= e(DayQueries::blockLabel(['name' => $row['block_name'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time']])) ?></td>
                                <td class="nowrap">
                                    <span class="badge <?= e($badgeClass[$row['status']] ?? '') ?>"><?= e($statusLabels[$row['status']] ?? $row['status']) ?></span>
                                    <?php if ($row['status'] === 'wish' && $row['priority'] !== null): ?>
                                        <span class="badge">Prio <?= (int) $row['priority'] ?></span>
                                    <?php endif; ?>
                                    <?php if ($row['override_note'] !== null): ?>
                                        <span class="badge badge-danger" title="<?= e('Übersteuert: ' . $row['override_note']) ?>">übersteuert</span>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap">
                                    <?= e($sourceLabels[$row['source']] ?? $row['source']) ?>
                                    <?php if (!empty($row['created_by_name'])): ?><div class="text-soft text-sm"><?= e($row['created_by_name']) ?></div><?php endif; ?>
                                </td>
                                <td class="nowrap text-sm"><?= e(format_datetime($row['created_at'])) ?></td>
                                <?php if ($canEdit): ?>
                                    <td class="nowrap">
                                        <div class="cluster">
                                            <?php if ($row['status'] === 'waitlist'): ?>
                                                <form method="post" action="<?= e($ctx->url('/admin/einschreibungen/' . (int) $row['id'] . '/nachruecken')) ?>">
                                                    <?= $csrf->field() ?>
                                                    <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
                                                    <button class="btn btn-sm btn-ghost" type="submit">Nachrücken</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($row['status'] !== 'wish'): ?>
                                                <a class="btn btn-sm btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen/' . (int) $row['id'] . '/umbuchen')) ?>">Umbuchen</a>
                                            <?php endif; ?>
                                            <form method="post" action="<?= e($ctx->url('/admin/einschreibungen/' . (int) $row['id'] . '/loeschen')) ?>" data-confirm="<?= e('Einschreibung von ' . DayQueries::personName($row) . ' wirklich löschen?') ?>">
                                                <?= $csrf->field() ?>
                                                <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
                                                <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                                            </form>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($rows) >= 1000): ?>
                <div class="card-footer text-soft text-sm">Es werden höchstens 1000 Einträge angezeigt — bitte Filter eingrenzen.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    </div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
