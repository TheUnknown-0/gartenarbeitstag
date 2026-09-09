<?php
/**
 * Schüler:innen-Übersicht.
 * Erwartet: $student, $day, $blocks (id => block), $mine (blockId => assigned/waitlist/wishes),
 *           $assignedBlocks (int), $window (state/start/end).
 */
$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
$minBlocks = (int) $day['min_blocks_per_student'];
$maxBlocks = $day['max_blocks_per_student'] !== null ? (int) $day['max_blocks_per_student'] : null;
$isWishlist = $day['mode'] === 'wishlist';
$isQuota = $day['mode'] === 'quota';
$progress = $minBlocks > 0 ? min(100, (int) round($assignedBlocks / $minBlocks * 100)) : 100;
$firstName = $student['firstname'] !== '' ? $student['firstname'] : $student['username'];

$widgets = page_blocks('uebersicht', [
    'aktionstag' => 'Aktionstag',
    'status' => 'Mein Status',
    'plan' => 'Meine Einschreibungen',
]);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($day['name']) ?></div>
        <h1 class="page-title">Hallo, <?= e($firstName) ?>! 👋</h1>
        <p class="page-sub">Hier siehst du auf einen Blick, wie es um deinen Gartenarbeitstag steht.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e($ctx->url('/einschreibung')) ?>">📝 Einschreibung</a>
        <a class="btn" href="<?= e($ctx->url('/mein-plan')) ?>">🗓️ Mein Plan</a>
    </div>
</div>

<?php foreach ($widgets as $blockKey => $blockLabel): ?>
    <?= block_open($blockKey, $blockLabel) ?>
    <?php if ($blockKey === 'aktionstag'): ?>
        <div class="card card-pad">
            <div class="cluster">
                <div>
                    <div class="text-soft text-sm">Datum</div>
                    <strong><?= e(format_date($day['event_date'])) ?></strong>
                </div>
                <div>
                    <div class="text-soft text-sm">Anmeldung</div>
                    <strong>
                        <?php if ($window['start'] === null && $window['end'] === null): ?>
                            jederzeit
                        <?php else: ?>
                            <?= $window['start'] !== null ? e(format_datetime($window['start'])) : '…' ?>
                            –
                            <?= $window['end'] !== null ? e(format_datetime($window['end'])) : '…' ?>
                        <?php endif; ?>
                    </strong>
                    <?php if ($window['state'] === 'before'): ?>
                        <span class="badge badge-info">noch nicht geöffnet</span>
                    <?php elseif ($window['state'] === 'after'): ?>
                        <span class="badge badge-warning">beendet</span>
                    <?php else: ?>
                        <span class="badge badge-success">geöffnet</span>
                    <?php endif; ?>
                </div>
            </div>
            <p class="text-soft text-sm mt-2">
                <?php if ($isQuota): ?>
                    Die Verteilung erfolgt zentral durch die Orga — du musst nichts auswählen, dein Ergebnis erscheint hier.
                <?php elseif ($isWishlist): ?>
                    Du gibst pro Zeitblock deine Wünsche ab (<?= e((string) $day['wishes_per_block']) ?> Prioritäten) — die Plätze werden danach automatisch verteilt.
                <?php else: ?>
                    Du schreibst dich direkt bei einem Stand ein — wer zuerst kommt, bekommt den Platz<?= (int) $day['waitlist_enabled'] === 1 ? '; ist ein Stand voll, kannst du auf die Warteliste' : '' ?>.
                <?php endif; ?>
            </p>
        </div>

    <?php elseif ($blockKey === 'status'): ?>
        <div class="card card-pad">
            <div class="cluster" style="justify-content:space-between;">
                <strong>Du hast <?= e((string) $assignedBlocks) ?> von mindestens <?= e((string) $minBlocks) ?> Zeitblöcken belegt.</strong>
                <?php if ($assignedBlocks >= $minBlocks): ?>
                    <span class="badge badge-success">✔ vollständig</span>
                <?php else: ?>
                    <span class="badge badge-warning">noch <?= e((string) ($minBlocks - $assignedBlocks)) ?> offen</span>
                <?php endif; ?>
            </div>
            <div class="progress mt-2" role="progressbar" aria-valuenow="<?= e((string) $progress) ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= e((string) $progress) ?>%"></span></div>
            <?php if ($maxBlocks !== null): ?>
                <div class="text-soft text-sm mt-2">Höchstens <?= e((string) $maxBlocks) ?> Zeitblöcke sind möglich.</div>
            <?php endif; ?>
            <?php if (($isWishlist || $isQuota) && !empty($day['assignment_done_at'])): ?>
                <div class="text-soft text-sm mt-2">Zuteilung erfolgt am <?= e(format_datetime($day['assignment_done_at'])) ?>.</div>
            <?php endif; ?>
        </div>

    <?php elseif ($blockKey === 'plan'): ?>
        <div class="card">
            <div class="card-header"><h2>Meine Einschreibungen</h2></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Zeitblock</th><th>Stand</th><th>Ort</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($blocks as $blockId => $block): ?>
                        <?php $entry = $mine[$blockId] ?? ['assigned' => null, 'waitlist' => [], 'wishes' => []]; ?>
                        <tr>
                            <td class="nowrap">
                                <strong><?= e($block['name']) ?></strong><br>
                                <span class="text-soft text-sm"><?= e($time($block['start_time'])) ?>–<?= e($time($block['end_time'])) ?></span>
                            </td>
                            <?php if ($entry['assigned'] !== null): ?>
                                <td><a href="<?= e($ctx->url('/staende/' . (int) $entry['assigned']['station_id'])) ?>"><?= e($entry['assigned']['station_name']) ?></a></td>
                                <td><?= e((string) ($entry['assigned']['location'] ?? '')) ?></td>
                                <td><span class="badge badge-success">fest</span></td>
                            <?php elseif ($entry['waitlist'] !== []): ?>
                                <td>
                                    <?php foreach ($entry['waitlist'] as $w): ?>
                                        <div><?= e($w['station_name']) ?></div>
                                    <?php endforeach; ?>
                                </td>
                                <td><?= e((string) ($entry['waitlist'][0]['location'] ?? '')) ?></td>
                                <td><span class="badge badge-warning">Warteliste</span></td>
                            <?php elseif ($entry['wishes'] !== []): ?>
                                <td>
                                    <?php foreach ($entry['wishes'] as $w): ?>
                                        <div><span class="text-soft text-sm"><?= e((string) $w['priority']) ?>.</span> <?= e($w['station_name']) ?></div>
                                    <?php endforeach; ?>
                                </td>
                                <td></td>
                                <td><span class="badge badge-info">Wünsche abgegeben</span></td>
                            <?php else: ?>
                                <td class="text-soft">—</td>
                                <td></td>
                                <td>
                                    <?php if ($isQuota): ?>
                                        <span class="text-faint text-sm">wird zentral verteilt</span>
                                    <?php else: ?>
                                        <a class="btn btn-sm" href="<?= e($ctx->url('/einschreibung')) ?>#block-<?= e((string) $blockId) ?>"><?= $isWishlist ? 'Wünsche wählen' : 'Einschreiben' ?></a>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <?= block_close() ?>
<?php endforeach; ?>
