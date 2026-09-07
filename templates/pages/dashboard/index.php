<?php
/**
 * Verwaltungs-Dashboard.
 * Ohne aktiven Aktionstag: nur $day (null) und $can.
 * Mit aktivem Aktionstag zusätzlich: $stats, $blockRows, $stations,
 * $occupancy, $classes, $auditRows, $canRunAssignment.
 */

$statusBadge = static function (string $status): string {
    return match ($status) {
        'active' => '<span class="badge badge-success">Aktiv</span>',
        'archived' => '<span class="badge">Archiviert</span>',
        default => '<span class="badge badge-info">Entwurf</span>',
    };
};
$modeLabel = static fn (string $mode): string => $mode === 'wishlist' ? 'Wunschliste' : 'Sofortbuchung';
$severityBadge = static fn (string $severity): string => match ($severity) {
    'critical' => '<span class="badge badge-danger">kritisch</span>',
    'warning' => '<span class="badge badge-warning">Warnung</span>',
    default => '<span class="badge badge-info">Info</span>',
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">📊 Dashboard</h1>
        <p class="page-sub">Überblick über den aktiven Aktionstag.</p>
    </div>
</div>

<?php if ($day === null): ?>
    <div class="empty-state">
        <div class="empty-icon">📅</div>
        <p>Es ist aktuell kein Aktionstag aktiv — es gibt daher noch nichts zu überblicken.</p>
        <?php if ($canSeeDays): ?>
            <a class="btn btn-primary" href="<?= e($ctx->url('/admin/aktionstage')) ?>">Zu den Aktionstagen</a>
        <?php endif; ?>
    </div>
<?php else: ?>

<?php
$blockCatalog = [];
if ($can['day']) { $blockCatalog['day'] = 'Aktionstag'; }
if ($can['stats']) { $blockCatalog['stats'] = 'Kennzahlen'; }
if ($can['blocks']) { $blockCatalog['blocks'] = 'Zeitblöcke'; }
if ($can['stations']) { $blockCatalog['stations'] = 'Stände'; }
if ($can['classes']) { $blockCatalog['classes'] = 'Klassen'; }
if ($can['audit']) { $blockCatalog['audit'] = 'Letzte Audit-Einträge'; }
$dashboardBlocks = page_blocks('admin-dashboard', $blockCatalog);
?>

<?php foreach ($dashboardBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>

<?php if ($blockKey === 'day'): ?>
    <div class="card card-pad mb-2">
        <div class="cluster" style="justify-content:space-between;">
            <div>
                <h3 class="mt-0 mb-0"><?= e($day['name']) ?></h3>
                <p class="text-sm text-soft mb-0">
                    <?= !empty($day['event_date']) ? e(format_date($day['event_date'])) : 'Datum offen' ?>
                    · <?= $statusBadge($day['status']) ?>
                    · Modus: <?= e($modeLabel($day['mode'])) ?>
                </p>
            </div>
            <?php if ($can['blocks'] || $can['stations']): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e($ctx->url('/admin/aktionstage/' . (int) $day['id'])) ?>">Bearbeiten</a>
            <?php endif; ?>
        </div>
        <div class="grid-3 mt-2">
            <div>
                <div class="text-sm text-soft">Anmeldefenster</div>
                <div>
                    <?= e(!empty($day['registration_start']) ? format_datetime($day['registration_start']) : 'sofort') ?>
                    – <?= e(!empty($day['registration_end']) ? format_datetime($day['registration_end']) : 'offen') ?>
                </div>
            </div>
            <div>
                <div class="text-sm text-soft">Blöcke je Schüler:in</div>
                <div>
                    min. <?= e((string) $day['min_blocks_per_student']) ?><?php if (!empty($day['max_blocks_per_student'])): ?> · max. <?= e((string) $day['max_blocks_per_student']) ?><?php endif; ?>
                </div>
            </div>
            <div>
                <div class="text-sm text-soft">Zuteilung</div>
                <div>
                    <?php if ($day['mode'] !== 'wishlist'): ?>
                        <span class="text-faint">nicht erforderlich (Sofortbuchung)</span>
                    <?php elseif (!empty($day['assignment_done_at'])): ?>
                        <span class="badge badge-success">erfolgt</span> <?= e(format_datetime($day['assignment_done_at'])) ?>
                    <?php else: ?>
                        <span class="badge badge-warning">noch nicht ausgeführt</span>
                        <?php if ($canRunAssignment): ?>
                            <a class="text-sm" href="<?= e($ctx->url('/admin/zuteilung')) ?>">Jetzt zuteilen</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($blockKey === 'stats'): ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value"><?= e((string) $stats['students']) ?></div>
            <div class="stat-label">Schüler:innen aktiv</div>
        </div>
        <div class="stat-card stat-accent">
            <div class="stat-value"><?= e((string) $stats['assigned']) ?></div>
            <div class="stat-label">Feste Einschreibungen</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= e((string) $stats['waitlist']) ?></div>
            <div class="stat-label">Warteliste</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= e((string) $stats['wish']) ?></div>
            <div class="stat-label">Wünsche</div>
        </div>
        <div class="stat-card<?= $stats['under'] > 0 ? ' stat-danger' : '' ?>">
            <div class="stat-value"><?= e((string) $stats['under']) ?> <span class="text-soft text-sm">/ <?= e((string) $stats['students']) ?></span></div>
            <div class="stat-label">Unter Mindestzahl Blöcke</div>
        </div>
    </div>

<?php elseif ($blockKey === 'blocks'): ?>
    <?php if ($blockRows === []): ?>
        <div class="empty-state"><p>Für diesen Aktionstag sind noch keine Zeitblöcke angelegt.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Zeitblock</th>
                    <th class="nowrap">Kapazität</th>
                    <th class="nowrap">Belegt</th>
                    <th class="nowrap">Frei</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($blockRows as $row): ?>
                    <tr>
                        <td><?= e(\App\Services\DayQueries::blockLabel($row['block'])) ?></td>
                        <td><?= e((string) $row['capacity']) ?></td>
                        <td><?= e((string) $row['assigned']) ?></td>
                        <td class="<?= $row['free'] === 0 && $row['capacity'] > 0 ? 'text-danger' : '' ?>"><?= e((string) $row['free']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'stations'): ?>
    <?php if ($stations === []): ?>
        <div class="empty-state"><p>Keine aktiven Stände an diesem Aktionstag.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Stand</th>
                    <th>Belegung je Block</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($stations as $station): ?>
                    <?php
                    $sid = (int) $station['id'];
                    $occ = $occupancy[$sid] ?? [];
                    $underMin = (int) $station['min_students'] > 0 && $occ !== []
                        && array_filter($occ, static fn (array $o): bool => $o['assigned'] < (int) $station['min_students']) !== [];
                    ?>
                    <tr>
                        <td><?= e($station['name']) ?></td>
                        <td>
                            <?php if ($occ === []): ?>
                                <span class="text-faint">— nicht angeboten</span>
                            <?php else: ?>
                                <div class="chip-row">
                                    <?php foreach ($occ as $o): ?>
                                        <span class="badge<?= $o['assigned'] >= $o['capacity'] ? ' badge-danger' : '' ?>"><?= e((string) $o['assigned']) ?>/<?= e((string) $o['capacity']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($underMin): ?>
                                <span class="badge badge-warning" title="Mindestbesetzung <?= e((string) $station['min_students']) ?> in mindestens einem Block nicht erreicht">⚠ unter Mindestbesetzung</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'classes'): ?>
    <?php if ($classes === []): ?>
        <div class="empty-state"><p>Keine Klassen mit aktiven Schüler:innen gefunden.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Klasse</th>
                    <th class="nowrap">Schüler:innen</th>
                    <th class="nowrap">Vollständig eingeschrieben</th>
                    <th>Fortschritt</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($classes as $row): ?>
                    <?php $total = (int) $row['total']; $complete = (int) $row['complete']; $pct = $total > 0 ? (int) round($complete / $total * 100) : 0; ?>
                    <tr>
                        <td><?= e($row['class']) ?></td>
                        <td><?= e((string) $total) ?></td>
                        <td><?= e((string) $complete) ?></td>
                        <td>
                            <div class="progress" style="max-width:160px;"><span style="width:<?= e((string) $pct) ?>%;"></span></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'audit'): ?>
    <?php if ($auditRows === []): ?>
        <div class="empty-state"><p>Noch keine Audit-Einträge.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th class="nowrap">Zeitpunkt</th>
                    <th>Benutzer</th>
                    <th>Aktion</th>
                    <th>Schweregrad</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($auditRows as $row): ?>
                    <tr>
                        <td class="nowrap text-sm"><?= e(format_datetime($row['created_at'])) ?></td>
                        <td><?= e($row['username'] ?? 'System') ?></td>
                        <td class="mono"><?= e($row['action']) ?></td>
                        <td><?= $severityBadge($row['severity']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="cluster mt-2">
            <a class="btn btn-sm btn-ghost" href="<?= e($ctx->url('/admin/audit-log')) ?>">Alle Einträge anzeigen</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?= block_close() ?>
<?php endforeach; ?>
<?php endif; ?>
