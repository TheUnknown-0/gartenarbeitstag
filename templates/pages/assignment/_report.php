<?php
/**
 * Bericht eines Zuteilungslaufs (Probelauf oder echt).
 * Erwartet: $report (Array aus AutoAssign::simulate/run), $day.
 */
$simulated = !empty($report['simulated']);
$stationsBelowMin = array_filter($report['stations'] ?? [], static fn (array $s): bool => (int) $s['min_students'] > 0 && (int) $s['assigned'] < (int) $s['min_students']);
?>
<div class="card mt-2" id="bericht">
    <div class="card-header">
        <h3><?= $simulated ? 'Probelauf-Bericht' : 'Ergebnis der Zuteilung' ?></h3>
        <span class="badge <?= $simulated ? 'badge-info' : 'badge-success' ?>"><?= $simulated ? 'Simulation – nichts gespeichert' : 'gespeichert' ?></span>
    </div>
    <div class="card-body stack">
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-value"><?= (int) $report['assigned_total'] ?></div>
                <div class="stat-label">Plätze vergeben</div>
            </div>
            <div class="stat-card">
                <div class="stat-value<?= (int) $report['unassigned_total'] > 0 ? ' text-danger' : '' ?>"><?= (int) $report['unassigned_total'] ?></div>
                <div class="stat-label">Wünsche ohne Platz</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= (int) $report['filled'] ?></div>
                <div class="stat-label">Pechvögel aufgefüllt</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= (int) ($report['no_wishes_filled'] ?? 0) ?></div>
                <div class="stat-label">ohne Wünsche verteilt</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= (int) $report['seed'] ?></div>
                <div class="stat-label">Seed</div>
            </div>
        </div>

        <?php if (!empty($report['by_priority'])): ?>
            <div class="chip-row">
                <?php foreach ($report['by_priority'] as $prio => $n): ?>
                    <span class="badge badge-primary">Prio <?= (int) $prio ?>: <?= (int) $n ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h4 class="mb-0">Nach Zeitblock</h4>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Zeitblock</th>
                        <th>vergeben</th>
                        <th>nach Priorität</th>
                        <th>aufgefüllt</th>
                        <th>ohne Platz</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['blocks'] as $b): ?>
                        <tr>
                            <td><?= e($b['name']) ?></td>
                            <td><?= (int) $b['assigned'] ?></td>
                            <td class="text-sm text-soft">
                                <?php $parts = []; foreach ($b['by_priority'] as $p => $n) { $parts[] = 'Prio ' . (int) $p . ': ' . (int) $n; } ?>
                                <?= e(implode(' · ', $parts)) ?: '–' ?>
                            </td>
                            <td><?= (int) $b['filled'] ?></td>
                            <td><?= count($b['unassigned']) > 0 ? '<span class="badge badge-danger">' . count($b['unassigned']) . '</span>' : '0' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ((int) $report['unassigned_total'] > 0): ?>
            <h4 class="mb-0">Schüler:innen ohne Platz</h4>
            <p class="hint mt-0">Diese Schüler:innen haben Wünsche abgegeben, konnten aber in dem jeweiligen Block auf keinem gewünschten (oder freien) Stand untergebracht werden. Sie können manuell über „Einschreibungen → Neu“ eingetragen werden.</p>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Zeitblock</th>
                            <th>Name</th>
                            <th>Klasse</th>
                            <th>Grund</th>
                            <?php if (!$simulated): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['blocks'] as $b): ?>
                            <?php foreach ($b['unassigned'] as $u): ?>
                                <tr>
                                    <td class="nowrap"><?= e($b['name']) ?></td>
                                    <td><?= e($u['name']) ?></td>
                                    <td><?= e((string) $u['class']) ?></td>
                                    <td class="text-sm text-soft"><?= e($u['reason']) ?></td>
                                    <?php if (!$simulated): ?>
                                        <td class="nowrap"><a class="btn btn-sm btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen/neu?user=' . (int) $u['user_id'] . '&block=' . (int) $b['id'])) ?>">Einschreiben</a></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if (!empty($report['no_wishes_open'])): ?>
            <h4 class="mb-0">Ohne Wünsche und noch nicht versorgt</h4>
            <p class="hint mt-0">Diese Schüler:innen haben keine Wünsche abgegeben und liegen unter der Mindestanzahl an Blöcken<?= empty($report['no_wishes_filled']) ? ' (Option „ohne Wünsche verteilen“ war aus oder es gab keine freien Plätze)' : '' ?>.</p>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Name</th><th>Klasse</th><th>Blöcke belegt</th><?php if (!$simulated): ?><th></th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['no_wishes_open'] as $u): ?>
                            <tr>
                                <td><?= e($u['name']) ?></td>
                                <td><?= e((string) $u['class']) ?></td>
                                <td><?= (int) $u['blocks'] ?></td>
                                <?php if (!$simulated): ?>
                                    <td class="nowrap"><a class="btn btn-sm btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen/neu?user=' . (int) $u['user_id'])) ?>">Einschreiben</a></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h4 class="mb-0">Belegung der Stände</h4>
        <?php if ($stationsBelowMin !== []): ?>
            <div class="alert alert-warning mt-0"><?= count($stationsBelowMin) ?> Stand-Block-Kombination(en) liegen unter der Mindestteilnehmerzahl.</div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Stand</th>
                        <th>Zeitblock</th>
                        <th>belegt / Kapazität</th>
                        <th>Auslastung</th>
                        <th>Minimum</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['stations'] ?? [] as $s): ?>
                        <?php
                        $cap = (int) $s['capacity'];
                        $assigned = (int) $s['assigned'];
                        $min = (int) $s['min_students'];
                        $pct = $cap > 0 ? (int) round($assigned / $cap * 100) : 0;
                        ?>
                        <tr>
                            <td><?= e($s['station']) ?></td>
                            <td class="nowrap"><?= e($s['block']) ?></td>
                            <td class="nowrap"><?= $assigned ?> / <?= $cap ?></td>
                            <td><div class="progress" title="<?= $pct ?> %"><span style="width:<?= min(100, $pct) ?>%"></span></div><span class="text-sm text-soft"><?= $pct ?> %</span></td>
                            <td>
                                <?php if ($min > 0 && $assigned < $min): ?>
                                    <span class="badge badge-warning">unter Minimum (<?= $min ?>)</span>
                                <?php elseif ($min > 0): ?>
                                    <span class="text-sm text-soft"><?= $min ?> ✓</span>
                                <?php else: ?>
                                    <span class="text-faint">–</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
