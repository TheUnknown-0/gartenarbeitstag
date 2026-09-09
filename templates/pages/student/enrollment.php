<?php
/**
 * Einschreibung (Schüler:innen) — Direkt- oder Wunschmodus.
 * Erwartet: $day, $blocks (id => block), $stations (id => station mit 'blocks'),
 *           $availability (stationId => available/reason), $mine (blockId => assigned/waitlist/wishes),
 *           $assignedBlocks, $selfService (bool), $window (state/start/end), $assignmentDone (bool).
 */
$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
$isWishlist = $day['mode'] === 'wishlist';
$isQuota = $day['mode'] === 'quota';
$minBlocks = (int) $day['min_blocks_per_student'];
$maxBlocks = $day['max_blocks_per_student'] !== null ? (int) $day['max_blocks_per_student'] : null;
$wishesPerBlock = max(1, (int) $day['wishes_per_block']);
$windowOpen = $window['state'] === 'open';
$canAct = $selfService && $windowOpen && !($isWishlist && $assignmentDone);
$waitlistEnabled = (int) $day['waitlist_enabled'] === 1;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($day['name']) ?> · <?= e(format_date($day['event_date'])) ?></div>
        <h1 class="page-title">Einschreibung</h1>
        <p class="page-sub">
            <?php if ($isQuota): ?>
                Die Verteilung erfolgt zentral durch die Orga anhand von Klassenstufen-Quoten — du wählst nichts selbst aus.
            <?php elseif ($isWishlist): ?>
                Wähle je Zeitblock bis zu <?= e((string) $wishesPerBlock) ?> Wünsche in deiner Reihenfolge — die Plätze werden danach automatisch verteilt.
            <?php else: ?>
                Schreib dich je Zeitblock bei einem Stand ein. Du kannst dich jederzeit wieder austragen, solange die Anmeldung geöffnet ist.
            <?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <span class="badge <?= $assignedBlocks >= $minBlocks ? 'badge-success' : 'badge-warning' ?>"><?= e((string) $assignedBlocks) ?> / mind. <?= e((string) $minBlocks) ?> Blöcke belegt</span>
    </div>
</div>

<?php if ($isQuota): ?>
    <div class="alert alert-info"><span aria-hidden="true">🧮</span><div>Die Verteilung erfolgt zentral durch die Orga. Sobald sie feststeht, erscheint dein Ergebnis hier.</div></div>
<?php elseif (!$selfService): ?>
    <div class="alert alert-info"><span aria-hidden="true">ℹ️</span><div>Die Einschreibung läuft über deine Lehrkraft. Hier siehst du, was für dich eingetragen ist.</div></div>
<?php elseif ($window['state'] === 'before'): ?>
    <div class="alert alert-info"><span aria-hidden="true">⏳</span><div>Die Einschreibung öffnet am <?= e(format_datetime($window['start'])) ?>. Du kannst dir die Stände schon einmal anschauen.</div></div>
<?php elseif ($window['state'] === 'after'): ?>
    <div class="alert alert-warning"><span aria-hidden="true">🔒</span><div>Die Einschreibung ist seit <?= e(format_datetime($window['end'])) ?> beendet. Änderungen sind nur noch über deine Lehrkraft möglich.</div></div>
<?php endif; ?>

<?php if ($isWishlist && $assignmentDone): ?>
    <div class="alert alert-success"><span aria-hidden="true">🎯</span><div>Die Zuteilung ist erfolgt (<?= e(format_datetime($day['assignment_done_at'])) ?>). Deine festen Plätze siehst du unten und in <a href="<?= e($ctx->url('/mein-plan')) ?>">Mein Plan</a>.</div></div>
<?php endif; ?>

<div class="alert alert-info">
    <span aria-hidden="true">📌</span>
    <div>
        Du musst mindestens <strong><?= e((string) $minBlocks) ?></strong> Zeitblock<?= $minBlocks === 1 ? '' : 'e' ?> belegen<?php if ($maxBlocks !== null): ?>, höchstens <strong><?= e((string) $maxBlocks) ?></strong><?php endif; ?>.
        In einem Zeitblock kannst du nur an <strong>einem</strong> Stand sein.
    </div>
</div>

<?php foreach ($blocks as $blockId => $block): ?>
    <?php
    $entry = $mine[$blockId] ?? ['assigned' => null, 'waitlist' => [], 'wishes' => []];
    $assigned = $entry['assigned'];
    $offered = array_filter($stations, static fn (array $s): bool => isset($s['blocks'][$blockId]));
    ?>
    <div class="card" id="block-<?= e((string) $blockId) ?>">
        <div class="card-header">
            <h2><?= e($block['name']) ?> <span class="text-soft text-sm"><?= e($time($block['start_time'])) ?>–<?= e($time($block['end_time'])) ?></span></h2>
            <?php if ($assigned !== null): ?>
                <span class="badge badge-success">✔ fest eingeschrieben</span>
            <?php elseif ($entry['waitlist'] !== []): ?>
                <span class="badge badge-warning">Warteliste</span>
            <?php elseif ($entry['wishes'] !== []): ?>
                <span class="badge badge-info"><?= e((string) count($entry['wishes'])) ?> Wünsche</span>
            <?php else: ?>
                <span class="badge">offen</span>
            <?php endif; ?>
        </div>
        <div class="card-body">

        <?php if ($assigned !== null): ?>
            <?php // ---------- feste Einschreibung (beide Modi) ---------- ?>
            <div class="cluster" style="justify-content:space-between;">
                <div>
                    <strong><a href="<?= e($ctx->url('/staende/' . (int) $assigned['station_id'])) ?>"><?= e($assigned['station_name']) ?></a></strong>
                    <?php if (!empty($assigned['location'])): ?><span class="text-soft"> · 📍 <?= e($assigned['location']) ?></span><?php endif; ?>
                    <?php if ($assigned['source'] !== 'self'): ?>
                        <div class="text-soft text-sm">Eingetragen durch die <?= $assigned['source'] === 'auto' ? 'automatische Zuteilung' : 'Orga/Lehrkraft' ?>.</div>
                    <?php endif; ?>
                </div>
                <?php if (!$isWishlist && $canAct): ?>
                    <form method="post" action="<?= e($ctx->url('/einschreibung/' . (int) $assigned['id'] . '/austragen')) ?>" data-confirm="Willst du dich wirklich bei „<?= e($assigned['station_name']) ?>“ austragen? Dein Platz wird frei.">
                        <?= $csrf->field() ?>
                        <button class="btn btn-sm btn-danger-ghost" type="submit">Austragen</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if (!$isWishlist && $canAct): ?>
                <p class="text-soft text-sm mt-2">Zum Wechseln zuerst austragen, dann beim neuen Stand einschreiben.</p>
            <?php endif; ?>

        <?php elseif (!$isWishlist): ?>
            <?php // ---------- Direktmodus ---------- ?>
            <?php foreach ($entry['waitlist'] as $w): ?>
                <div class="alert alert-warning">
                    <span aria-hidden="true">⏳</span>
                    <div class="cluster" style="flex:1;justify-content:space-between;">
                        <div>Du stehst auf der Warteliste bei <strong><?= e($w['station_name']) ?></strong>. Wird ein Platz frei, rückst du automatisch nach.</div>
                        <?php if ($canAct): ?>
                            <form method="post" action="<?= e($ctx->url('/einschreibung/' . (int) $w['id'] . '/austragen')) ?>" data-confirm="Von der Warteliste bei „<?= e($w['station_name']) ?>“ austragen?">
                                <?= $csrf->field() ?>
                                <button class="btn btn-sm btn-ghost" type="submit">Von Warteliste austragen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($offered === []): ?>
                <p class="text-soft">In diesem Zeitblock wird kein Stand angeboten.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Stand</th><th>Ort</th><th>Plätze</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($offered as $stationId => $station): ?>
                            <?php
                            $sb = $station['blocks'][$blockId];
                            $avail = $availability[$stationId] ?? ['available' => true, 'reason' => ''];
                            $onWaitlist = array_filter($entry['waitlist'], static fn (array $w): bool => (int) $w['station_id'] === (int) $stationId) !== [];
                            ?>
                            <tr>
                                <td><a href="<?= e($ctx->url('/staende/' . (int) $stationId)) ?>"><?= e($station['name']) ?></a></td>
                                <td class="text-soft"><?= e((string) ($station['location'] ?? '')) ?></td>
                                <td class="nowrap">
                                    <span class="badge <?= $sb['free'] > 0 ? 'badge-success' : 'badge-danger' ?>"><?= e((string) $sb['free']) ?>/<?= e((string) $sb['capacity']) ?> frei</span>
                                    <?php if ($sb['waitlist'] > 0): ?><span class="text-soft text-sm"><?= e((string) $sb['waitlist']) ?> auf Warteliste</span><?php endif; ?>
                                </td>
                                <td class="nowrap">
                                    <?php if (!$avail['available']): ?>
                                        <span class="badge badge-warning"><?= e($avail['reason']) ?></span>
                                    <?php elseif ($onWaitlist): ?>
                                        <span class="badge badge-warning">auf Warteliste</span>
                                    <?php elseif (!$canAct): ?>
                                        <span class="text-faint text-sm">—</span>
                                    <?php elseif ($sb['free'] > 0): ?>
                                        <form method="post" action="<?= e($ctx->url('/einschreibung')) ?>">
                                            <?= $csrf->field() ?>
                                            <input type="hidden" name="station_id" value="<?= e((string) $stationId) ?>">
                                            <input type="hidden" name="time_block_id" value="<?= e((string) $blockId) ?>">
                                            <button class="btn btn-sm btn-primary" type="submit">Einschreiben</button>
                                        </form>
                                    <?php elseif ($waitlistEnabled): ?>
                                        <form method="post" action="<?= e($ctx->url('/einschreibung')) ?>">
                                            <?= $csrf->field() ?>
                                            <input type="hidden" name="station_id" value="<?= e((string) $stationId) ?>">
                                            <input type="hidden" name="time_block_id" value="<?= e((string) $blockId) ?>">
                                            <button class="btn btn-sm" type="submit">Auf Warteliste</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge badge-danger">voll</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <?php // ---------- Wunschmodus ---------- ?>
            <?php
            $chosen = [];
            foreach ($entry['wishes'] as $w) {
                $chosen[(int) $w['priority']] = (int) $w['station_id'];
            }
            $selectable = array_filter($offered, static fn (array $s, int $id): bool => ($availability[$id]['available'] ?? true), ARRAY_FILTER_USE_BOTH);
            ?>
            <?php if ($assignmentDone): ?>
                <?php if ($entry['wishes'] !== []): ?>
                    <p class="text-soft">Deine Wünsche waren:
                        <?php foreach ($entry['wishes'] as $w): ?>
                            <span class="badge"><?= e((string) $w['priority']) ?>. <?= e($w['station_name']) ?></span>
                        <?php endforeach; ?>
                    </p>
                    <p>Leider konnte dir in diesem Zeitblock kein Platz zugeteilt werden. Wende dich bitte an deine Lehrkraft.</p>
                <?php else: ?>
                    <p class="text-soft">Für diesen Zeitblock hast du keine Wünsche abgegeben.</p>
                <?php endif; ?>
            <?php elseif ($offered === []): ?>
                <p class="text-soft">In diesem Zeitblock wird kein Stand angeboten.</p>
            <?php else: ?>
                <form method="post" action="<?= e($ctx->url('/einschreibung/wuensche')) ?>" data-wish-form>
                    <?= $csrf->field() ?>
                    <input type="hidden" name="time_block_id" value="<?= e((string) $blockId) ?>">
                    <div class="form-grid">
                        <?php for ($prio = 1; $prio <= $wishesPerBlock; $prio++): ?>
                            <div class="field">
                                <label for="wish-<?= e((string) $blockId) ?>-<?= e((string) $prio) ?>"><?= e((string) $prio) ?>. Wunsch</label>
                                <select class="input" id="wish-<?= e((string) $blockId) ?>-<?= e((string) $prio) ?>" name="stations[]" data-wish-select <?= $canAct ? '' : 'disabled' ?>>
                                    <option value="">— kein Wunsch —</option>
                                    <?php foreach ($selectable as $stationId => $station): ?>
                                        <?php $sb = $station['blocks'][$blockId]; ?>
                                        <option value="<?= e((string) $stationId) ?>" <?= ($chosen[$prio] ?? 0) === (int) $stationId ? 'selected' : '' ?>>
                                            <?= e($station['name']) ?><?= !empty($station['location']) ? ' (' . e($station['location']) . ')' : '' ?> · <?= e((string) $sb['capacity']) ?> Plätze
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <?php
                    $blocked = array_filter($offered, static fn (array $s, int $id): bool => !($availability[$id]['available'] ?? true), ARRAY_FILTER_USE_BOTH);
                    ?>
                    <?php if ($blocked !== []): ?>
                        <p class="text-soft text-sm">Nicht wählbar:
                            <?php foreach ($blocked as $id => $s): ?>
                                <span class="badge"><?= e($s['name']) ?> — <?= e($availability[$id]['reason']) ?></span>
                            <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($canAct): ?>
                        <div class="cluster">
                            <button class="btn btn-primary" type="submit">Wünsche speichern</button>
                            <?php if ($entry['wishes'] !== []): ?>
                                <span class="text-soft text-sm">Zuletzt gespeichert: <?= e(format_datetime($entry['wishes'][0]['created_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
