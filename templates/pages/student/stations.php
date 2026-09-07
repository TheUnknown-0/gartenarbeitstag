<?php
/**
 * Standübersicht (Schüler:innen und Lehrkräfte).
 * Erwartet: $day, $blocks (id => block), $stations (id => station mit 'blocks'),
 *           $availability (stationId => available/reason), $isStudent.
 */
$short = static function (?string $text, int $max = 160): string {
    $text = trim((string) $text);

    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($day['name']) ?></div>
        <h1 class="page-title">Stände</h1>
        <p class="page-sub"><?= $isStudent ? 'Alle Stände des Aktionstags — schau, was dich interessiert, und schreib dich ein.' : 'Alle aktiven Stände des Aktionstags mit ihrer Belegung.' ?></p>
    </div>
    <?php if ($isStudent): ?>
        <div class="page-actions">
            <a class="btn btn-primary" href="<?= e($ctx->url('/einschreibung')) ?>">📝 Zur Einschreibung</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($stations === []): ?>
    <div class="card"><div class="empty-state"><div class="empty-icon" aria-hidden="true">🌱</div>Es sind noch keine Stände eingetragen.</div></div>
<?php else: ?>
    <div class="grid-2">
    <?php foreach ($stations as $stationId => $station): ?>
        <?php $avail = $availability[$stationId] ?? ['available' => true, 'reason' => '']; ?>
        <div class="card">
            <div class="card-header">
                <h3><a href="<?= e($ctx->url('/staende/' . (int) $stationId)) ?>"><?= e($station['name']) ?></a></h3>
                <?php if (!$avail['available']): ?>
                    <span class="badge badge-warning"><?= e($avail['reason']) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($station['location'])): ?>
                    <div class="text-sm"><span aria-hidden="true">📍</span> <?= e($station['location']) ?></div>
                <?php endif; ?>
                <?php if (!empty($station['description'])): ?>
                    <p class="text-soft text-sm"><?= e($short($station['description'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($station['materials'])): ?>
                    <div class="text-sm"><span aria-hidden="true">🧤</span> <?= e($short($station['materials'], 100)) ?></div>
                <?php endif; ?>

                <div class="chip-row mt-2">
                    <?php foreach ($blocks as $blockId => $block): ?>
                        <?php $sb = $station['blocks'][$blockId] ?? null; ?>
                        <?php if ($sb === null): ?>
                            <span class="badge text-faint" title="wird in diesem Zeitblock nicht angeboten"><?= e($block['name']) ?>: —</span>
                        <?php else: ?>
                            <span class="badge <?= $sb['free'] > 0 ? 'badge-success' : 'badge-danger' ?>" title="frei / Kapazität">
                                <?= e($block['name']) ?>: <?= e((string) $sb['free']) ?>/<?= e((string) $sb['capacity']) ?> frei<?= $sb['waitlist'] > 0 ? ' · ' . e((string) $sb['waitlist']) . ' auf Warteliste' : '' ?>
                            </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-footer">
                <a class="btn btn-sm" href="<?= e($ctx->url('/staende/' . (int) $stationId)) ?>">Details</a>
                <?php if ($isStudent && $avail['available']): ?>
                    <a class="btn btn-sm btn-primary" href="<?= e($ctx->url('/einschreibung')) ?>"><?= $day['mode'] === 'wishlist' ? 'Als Wunsch wählen' : 'Einschreiben' ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
