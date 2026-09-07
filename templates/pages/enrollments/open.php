<?php
/**
 * Schüler:innen unter der Mindestzahl fester Blöcke.
 * Erwartet: $day, $rows, $class, $classes, $minBlocks, $canEdit.
 */
use App\Services\DayQueries;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung · Einschreibungen</div>
        <h1 class="page-title">Offene Einschreibungen</h1>
        <p class="page-sub">
            Schüler:innen mit weniger als <?= (int) $minBlocks ?> festen Zeitblöcken beim <?= e($day['name']) ?>.
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/druck/offen.csv')) ?><?= $class !== '' ? '?klasse=' . urlencode($class) : '' ?>">CSV exportieren</a>
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen')) ?>">Zurück zur Übersicht</a>
    </div>
</div>

<div class="card mb-2">
    <div class="card-body">
        <form method="get" action="<?= e($ctx->url('/admin/einschreibungen/offen')) ?>" class="cluster" data-live="enrollments-open">
            <div class="field mb-0">
                <label for="f-klasse">Klasse</label>
                <select class="input" id="f-klasse" name="klasse">
                    <option value="">Alle Klassen</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= e($c) ?>"<?= $class === $c ? ' selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field mb-0">
                <label>&nbsp;</label>
                <button class="btn btn-primary" type="submit">Filtern</button>
            </div>
        </form>
    </div>
</div>

<div data-live-target="enrollments-open">
<div class="card">
    <div class="card-header"><h3><?= count($rows) ?> Schüler:innen</h3></div>
    <?php if ($rows === []): ?>
        <div class="card-body"><div class="empty-state">Alle Schüler:innen haben die Mindestzahl an Blöcken erreicht. 🎉</div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Schüler:in</th>
                        <th>Klasse</th>
                        <th>Feste Blöcke</th>
                        <th>Fehlend</th>
                        <?php if ($canEdit): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?= e(DayQueries::personName($row)) ?></strong> <span class="text-soft text-sm"><?= e($row['username']) ?></span></td>
                            <td><?= e((string) $row['class']) ?></td>
                            <td><?= (int) $row['assigned_blocks'] ?></td>
                            <td><span class="badge badge-warning"><?= max(0, $minBlocks - (int) $row['assigned_blocks']) ?></span></td>
                            <?php if ($canEdit): ?>
                                <td class="nowrap"><a class="btn btn-sm btn-primary" href="<?= e($ctx->url('/admin/einschreibungen/neu') . '?user=' . (int) $row['id']) ?>">Einschreiben</a></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</div>
