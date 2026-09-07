<?php
/**
 * Stand-Detailseite.
 * Erwartet: $day, $blocks, $station (mit 'blocks'), $availability (available/reason),
 *           $leaders, $mine (blockId => assigned/waitlist/wishes), $isStudent.
 */
$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
$isWishlist = $day['mode'] === 'wishlist';
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a href="<?= e($ctx->url('/staende')) ?>">← Alle Stände</a></div>
        <h1 class="page-title"><?= e($station['name']) ?></h1>
        <?php if (!empty($station['location'])): ?>
            <p class="page-sub"><span aria-hidden="true">📍</span> <?= e($station['location']) ?></p>
        <?php endif; ?>
    </div>
    <?php if (!$availability['available']): ?>
        <div class="page-actions"><span class="badge badge-warning"><?= e($availability['reason']) ?></span></div>
    <?php endif; ?>
</div>

<div class="grid-2">
    <div class="stack">
        <div class="card card-pad">
            <h3 class="mt-0">Worum geht es?</h3>
            <?php if (!empty($station['description'])): ?>
                <p><?= nl2br(e($station['description'])) ?></p>
            <?php else: ?>
                <p class="text-soft">Keine Beschreibung hinterlegt.</p>
            <?php endif; ?>

            <?php if (!empty($station['materials'])): ?>
                <h3>Mitbringen / Material</h3>
                <p><?= nl2br(e($station['materials'])) ?></p>
            <?php endif; ?>

            <?php if ($leaders !== []): ?>
                <h3>Standleitung</h3>
                <div class="chip-row">
                    <?php foreach ($leaders as $leader): ?>
                        <span class="badge badge-primary"><?= e(trim($leader['firstname'] . ' ' . $leader['lastname'])) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Zeitblöcke</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Zeitblock</th><th>Plätze</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($blocks as $blockId => $block): ?>
                    <?php
                    $sb = $station['blocks'][$blockId] ?? null;
                    $entry = $mine[$blockId] ?? null;
                    $mineHere = $entry !== null && $entry['assigned'] !== null && (int) $entry['assigned']['station_id'] === (int) $station['id'];
                    $wishHere = $entry !== null && array_filter($entry['wishes'], static fn (array $w): bool => (int) $w['station_id'] === (int) $station['id']) !== [];
                    ?>
                    <tr>
                        <td class="nowrap">
                            <strong><?= e($block['name']) ?></strong><br>
                            <span class="text-soft text-sm"><?= e($time($block['start_time'])) ?>–<?= e($time($block['end_time'])) ?></span>
                        </td>
                        <?php if ($sb === null): ?>
                            <td class="text-soft" colspan="2">wird in diesem Zeitblock nicht angeboten</td>
                        <?php else: ?>
                            <td>
                                <span class="badge <?= $sb['free'] > 0 ? 'badge-success' : 'badge-danger' ?>"><?= e((string) $sb['free']) ?> von <?= e((string) $sb['capacity']) ?> frei</span>
                                <?php if ($sb['waitlist'] > 0): ?>
                                    <span class="badge badge-warning"><?= e((string) $sb['waitlist']) ?> auf Warteliste</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <?php if (!$isStudent): ?>
                                    <span class="text-soft text-sm"><?= e((string) $sb['assigned']) ?> belegt</span>
                                <?php elseif ($mineHere): ?>
                                    <span class="badge badge-success">✔ Du bist hier eingeschrieben</span>
                                <?php elseif ($wishHere): ?>
                                    <span class="badge badge-info">als Wunsch gewählt</span>
                                <?php elseif ($entry !== null && $entry['assigned'] !== null): ?>
                                    <span class="text-soft text-sm">Du bist in diesem Block bereits woanders eingeschrieben.</span>
                                <?php elseif ($availability['available']): ?>
                                    <a class="btn btn-sm btn-primary" href="<?= e($ctx->url('/einschreibung')) ?>#block-<?= e((string) $blockId) ?>">
                                        <?= $isWishlist ? 'Als Wunsch wählen' : ($sb['free'] > 0 ? 'Einschreiben' : ((int) $day['waitlist_enabled'] === 1 ? 'Auf Warteliste' : 'voll')) ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
