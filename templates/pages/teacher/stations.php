<?php
/**
 * Meine Stände (Standleitung).
 * Erwartet: $day (nullable), $blocks (id => Zeitblock), $stations (id => Stand
 *           mit 'blocks'[blockId] => capacity/assigned/waitlist).
 */
use App\Services\DayQueries;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Lehrkraft<?= $day !== null ? ' · ' . e($day['name']) : '' ?></div>
        <h1 class="page-title">Meine Stände</h1>
        <p class="page-sub">Deine Stände als Standleitung — Belegung, Anwesenheit und Warteliste.</p>
    </div>
</div>

<?php if ($day === null): ?>
    <div class="card">
        <div class="empty-state">
            <div class="empty-icon" aria-hidden="true">🌤️</div>
            <h2>Kein aktiver Aktionstag</h2>
            <p class="text-soft">Sobald ein Aktionstag aktiv ist, siehst du hier deine Stände.</p>
        </div>
    </div>
<?php elseif ($stations === []): ?>
    <div class="card">
        <div class="empty-state">
            <div class="empty-icon" aria-hidden="true">🌱</div>
            <h2>Du leitest aktuell keinen Stand</h2>
            <p class="text-soft">Wenn dich die Orga als Standleitung einträgt, erscheint der Stand hier.</p>
        </div>
    </div>
<?php else: ?>
    <div class="grid-2">
        <?php foreach ($stations as $stationId => $station): ?>
            <?php
            $minStudents = $station['min_students'] !== null ? (int) $station['min_students'] : null;
            $totalAssigned = array_sum(array_column($station['blocks'], 'assigned'));
            $totalWaitlist = array_sum(array_column($station['blocks'], 'waitlist'));
            $underMin = $minStudents !== null && $totalAssigned < $minStudents;
            ?>
            <div class="card">
                <div class="card-header">
                    <h3><a href="<?= e($ctx->url('/meine-staende/' . $stationId)) ?>"><?= e($station['name']) ?></a></h3>
                    <?php if ($underMin): ?>
                        <span class="badge badge-warning">unter Mindestbesetzung (<?= $totalAssigned ?>/<?= $minStudents ?>)</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($station['location'])): ?>
                        <div class="text-sm"><span aria-hidden="true">📍</span> <?= e($station['location']) ?></div>
                    <?php endif; ?>

                    <?php if ($station['blocks'] === []): ?>
                        <p class="text-soft text-sm mt-2">Für diesen Stand sind keine Zeitblöcke hinterlegt.</p>
                    <?php else: ?>
                        <div class="table-wrap mt-2">
                            <table class="data-table">
                                <thead><tr><th>Zeitblock</th><th>Belegung</th></tr></thead>
                                <tbody>
                                <?php foreach ($blocks as $blockId => $block): ?>
                                    <?php $sb = $station['blocks'][$blockId] ?? null; ?>
                                    <?php if ($sb === null): ?>
                                        <tr><td class="nowrap"><?= e($block['name']) ?></td><td class="text-faint">wird nicht angeboten</td></tr>
                                    <?php else: ?>
                                        <tr>
                                            <td class="nowrap"><?= e(DayQueries::blockLabel($block)) ?></td>
                                            <td class="nowrap">
                                                <span class="badge <?= $sb['assigned'] >= $sb['capacity'] ? 'badge-danger' : 'badge-success' ?>"><?= (int) $sb['assigned'] ?> / <?= (int) $sb['capacity'] ?></span>
                                                <?php if ($sb['waitlist'] > 0): ?>
                                                    <span class="badge badge-warning"><?= (int) $sb['waitlist'] ?> Warteliste</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($totalWaitlist > 0): ?>
                        <p class="text-soft text-sm mt-2"><?= $totalWaitlist ?> Person<?= $totalWaitlist === 1 ? '' : 'en' ?> insgesamt auf der Warteliste.</p>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <a class="btn btn-sm btn-primary" href="<?= e($ctx->url('/meine-staende/' . $stationId)) ?>">Zum Stand</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
