<?php
/**
 * Mein Plan (druckfreundlich).
 * Erwartet: $student, $day, $blocks (id => block), $plan (blockId => station/location/leaders/materials).
 */
$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
$schoolName = (string) $ctx->settings->get('school_name', '');
$materials = [];
foreach ($plan as $row) {
    if ($row['materials'] !== '') {
        $materials[$row['station']] = $row['materials'];
    }
}
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= $schoolName !== '' ? e($schoolName) . ' · ' : '' ?><?= e($day['name']) ?></div>
        <h1 class="page-title">Mein Plan</h1>
        <p class="page-sub"><?= e(trim($student['firstname'] . ' ' . $student['lastname'])) ?><?= !empty($student['class']) ? ' · Klasse ' . e($student['class']) : '' ?> · <?= e(format_date($day['event_date'])) ?></p>
    </div>
    <div class="page-actions">
        <button class="btn" type="button" data-print>🖨️ Drucken</button>
        <a class="btn" href="<?= e($ctx->url('/mein-plan.pdf')) ?>">📄 Als PDF</a>
        <a class="btn btn-ghost" href="<?= e($ctx->url('/einschreibung')) ?>">Einschreibung ändern</a>
    </div>
</div>

<?php if ($plan === []): ?>
    <div class="card">
        <div class="empty-state">
            <div class="empty-icon" aria-hidden="true">📭</div>
            <h2>Noch keine festen Plätze.</h2>
            <p class="text-soft">Sobald du fest eingeschrieben bist, erscheint hier dein Tagesplan.</p>
            <a class="btn btn-primary no-print" href="<?= e($ctx->url('/einschreibung')) ?>">Zur Einschreibung</a>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Zeitblock</th><th>Stand</th><th>Ort</th><th>Standleitung</th></tr>
                </thead>
                <tbody>
                <?php foreach ($blocks as $blockId => $block): ?>
                    <?php $row = $plan[$blockId] ?? null; ?>
                    <tr>
                        <td class="nowrap">
                            <strong><?= e($block['name']) ?></strong><br>
                            <span class="text-soft text-sm"><?= e($time($block['start_time'])) ?>–<?= e($time($block['end_time'])) ?></span>
                        </td>
                        <?php if ($row === null): ?>
                            <td class="text-soft" colspan="3">frei — kein Stand eingetragen</td>
                        <?php else: ?>
                            <td><strong><?= e($row['station']) ?></strong></td>
                            <td><?= e($row['location']) ?></td>
                            <td><?= e($row['leaders']) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($materials !== []): ?>
        <div class="card card-pad">
            <h2 class="mt-0">Mitbringen / Material</h2>
            <?php foreach ($materials as $station => $text): ?>
                <p><strong><?= e($station) ?>:</strong> <?= nl2br(e($text)) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
