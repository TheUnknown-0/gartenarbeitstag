<?php
/**
 * Übersicht aller Aktionstage.
 * Erwartet: $days (Liste mit station_count, block_count, assigned_count).
 */

use App\Core\Permissions as P;

$canEdit = $auth->can(P::AKTIONSTAGE_BEARBEITEN);
$isAdmin = $auth->isAdmin();
$statusBadge = static function (string $status): string {
    return match ($status) {
        'active' => '<span class="badge badge-success">Aktiv</span>',
        'archived' => '<span class="badge">Archiviert</span>',
        default => '<span class="badge badge-info">Entwurf</span>',
    };
};
$modeLabel = static fn (string $mode): string => ['direct' => 'Sofortbuchung', 'wishlist' => 'Wunschliste', 'quota' => 'Quote'][$mode] ?? $mode;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Aktionstage</h1>
        <p class="page-sub">Es ist immer genau ein Aktionstag aktiv — nur für diesen können Schüler:innen sich einschreiben.</p>
    </div>
    <?php if ($canEdit): ?>
        <div class="page-actions">
            <a class="btn btn-primary" href="<?= e($ctx->url('/admin/aktionstage/neu')) ?>">➕ Neuer Aktionstag</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($days === []): ?>
    <div class="card"><div class="empty-state">Noch kein Aktionstag angelegt.</div></div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Aktionstag</th>
                        <th>Datum</th>
                        <th>Status</th>
                        <th>Modus</th>
                        <th class="nowrap">Zeitblöcke</th>
                        <th class="nowrap">Stände</th>
                        <th class="nowrap">Feste Plätze</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($days as $day): ?>
                    <?php $id = (int) $day['id']; ?>
                    <tr>
                        <td>
                            <strong><?= e($day['name']) ?></strong>
                            <?php if (!empty($day['registration_start']) || !empty($day['registration_end'])): ?>
                                <div class="text-sm text-soft">
                                    Anmeldung: <?= e(!empty($day['registration_start']) ? format_datetime($day['registration_start']) : 'sofort') ?>
                                    – <?= e(!empty($day['registration_end']) ? format_datetime($day['registration_end']) : 'offen') ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap"><?= e($day['event_date'] !== null ? format_date($day['event_date']) : '–') ?></td>
                        <td><?= $statusBadge((string) $day['status']) ?></td>
                        <td><?= e($modeLabel((string) $day['mode'])) ?></td>
                        <td><?= e((string) $day['block_count']) ?></td>
                        <td><?= e((string) $day['station_count']) ?></td>
                        <td><?= e((string) $day['assigned_count']) ?></td>
                        <td class="nowrap">
                            <div class="cluster">
                                <?php if ($canEdit): ?>
                                    <a class="btn btn-sm" href="<?= e($ctx->url('/admin/aktionstage/' . $id)) ?>">Bearbeiten</a>
                                    <a class="btn btn-sm btn-ghost" href="<?= e($ctx->url('/admin/aktionstage/' . $id . '/klonen')) ?>">Klonen</a>
                                    <?php if ($day['status'] !== 'active'): ?>
                                        <form method="post" action="<?= e($ctx->url('/admin/aktionstage/' . $id . '/status')) ?>"
                                              data-confirm="„<?= e($day['name']) ?>“ aktiv schalten? Der bisher aktive Aktionstag wird abgelöst.">
                                            <?= $csrf->field() ?>
                                            <input type="hidden" name="status" value="active">
                                            <button class="btn btn-sm btn-accent" type="submit">Aktiv schalten</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($day['status'] !== 'archived'): ?>
                                        <form method="post" action="<?= e($ctx->url('/admin/aktionstage/' . $id . '/status')) ?>"
                                              data-confirm="„<?= e($day['name']) ?>“ archivieren? Einschreibungen sind danach nicht mehr möglich.">
                                            <?= $csrf->field() ?>
                                            <input type="hidden" name="status" value="archived">
                                            <button class="btn btn-sm btn-ghost" type="submit">Archivieren</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($isAdmin && $day['status'] !== 'active'): ?>
                                        <form method="post" action="<?= e($ctx->url('/admin/aktionstage/' . $id . '/loeschen')) ?>"
                                              data-confirm="„<?= e($day['name']) ?>“ endgültig löschen? Alle Stände, Einschreibungen und Anwesenheiten dieses Tages gehen verloren.">
                                            <?= $csrf->field() ?>
                                            <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                                        </form>
                                    <?php endif; ?>
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
