<?php
/**
 * Klassenübersicht (Lehrkräfte/Orga/Admin).
 * Erwartet: $day (nullable), $classes (list<string>), $selected (string, Filter via GET „klasse“),
 *           $blocks (id => Zeitblock, nur wenn Tag + Klasse gewählt),
 *           $students (list mit id, firstname, lastname, class),
 *           $matrix ([userId][blockId] => ['assigned' => ?string, 'waitlist' => list<string>, 'wishes' => int]).
 *
 * Hinweis: Der Controller zählt Wünsche nur je Block (kein Prioritäts-Detail
 * je Stand) — daher werden Wünsche hier als Anzahl-Badge dargestellt, nicht
 * als Prio-Liste einzelner Stände.
 */
use App\Services\DayQueries;

$base = $ctx->url('/klassen');
$isWishlist = $day !== null && $day['mode'] === 'wishlist';
$minBlocks = $day !== null ? (int) $day['min_blocks_per_student'] : 0;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Lehrkraft<?= $day !== null ? ' · ' . e($day['name']) : '' ?></div>
        <h1 class="page-title">Klassen</h1>
        <p class="page-sub">Wer ist wo eingeschrieben — je Klasse und Zeitblock.</p>
    </div>
</div>

<?php if ($day === null): ?>
    <div class="card">
        <div class="empty-state">
            <div class="empty-icon" aria-hidden="true">🌤️</div>
            <h2>Kein aktiver Aktionstag</h2>
            <p class="text-soft">Sobald ein Aktionstag aktiv ist, siehst du hier die Klassenübersicht.</p>
        </div>
    </div>
<?php elseif ($classes === []): ?>
    <div class="card"><div class="empty-state">Es sind noch keine Klassen mit Schüler:innen angelegt.</div></div>
<?php else: ?>
    <div class="tabs">
        <?php foreach ($classes as $c): ?>
            <a class="tab<?= $selected === $c ? ' active' : '' ?>" href="<?= e($base . '?klasse=' . urlencode($c)) ?>">Klasse <?= e($c) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($selected === ''): ?>
        <div class="card"><div class="empty-state">Bitte oben eine Klasse auswählen.</div></div>
    <?php elseif ($students === []): ?>
        <div class="card"><div class="empty-state">In Klasse <?= e($selected) ?> sind keine aktiven Schüler:innen eingetragen.</div></div>
    <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h3>Klasse <?= e($selected) ?></h3>
                <span class="badge"><?= count($students) ?> Schüler:innen</span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Schüler:in</th>
                            <?php foreach ($blocks as $block): ?>
                                <th><?= e(DayQueries::blockLabel($block)) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <?php
                            $uid = (int) $student['id'];
                            $assignedCount = 0;
                            foreach ($blocks as $bid => $block) {
                                if (($matrix[$uid][$bid]['assigned'] ?? null) !== null) {
                                    $assignedCount++;
                                }
                            }
                            $under = $assignedCount < $minBlocks;
                            ?>
                            <tr>
                                <td class="nowrap">
                                    <strong><?= e(DayQueries::personName($student)) ?></strong>
                                    <?php if ($under): ?>
                                        <br><span class="badge badge-warning" title="Mindestzahl fester Blöcke nicht erreicht"><?= $assignedCount ?> / mind. <?= $minBlocks ?></span>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($blocks as $bid => $block): ?>
                                    <?php $entry = $matrix[$uid][$bid] ?? ['assigned' => null, 'waitlist' => [], 'wishes' => 0]; ?>
                                    <td>
                                        <?php if ($entry['assigned'] !== null): ?>
                                            <?= e($entry['assigned']) ?>
                                        <?php elseif ($entry['waitlist'] !== []): ?>
                                            <?php foreach ($entry['waitlist'] as $name): ?>
                                                <em class="text-soft"><?= e($name) ?> (Warteliste)</em><br>
                                            <?php endforeach; ?>
                                        <?php elseif ($isWishlist && $entry['wishes'] > 0): ?>
                                            <span class="badge badge-info"><?= (int) $entry['wishes'] ?> Wunsch<?= (int) $entry['wishes'] === 1 ? '' : 'wünsche' ?></span>
                                        <?php else: ?>
                                            <span class="text-faint">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
