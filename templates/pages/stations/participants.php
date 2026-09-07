<?php
/**
 * Teilnehmende eines Stands je Zeitblock.
 * Erwartet: $day, $station, $blocks (mit capacity), $byBlock, $tagQuery.
 */

use App\Core\Permissions as P;

$base = $ctx->url('/admin/staende');
$statusLabels = ['assigned' => 'Fest', 'waitlist' => 'Warteliste', 'wish' => 'Wunsch'];
$badgeClass = ['assigned' => 'badge-success', 'waitlist' => 'badge-warning', 'wish' => 'badge-info'];
$sourceLabels = ['self' => 'selbst', 'auto' => 'automatisch', 'orga' => 'Orga'];
$timeShort = static fn (?string $t): string => $t === null || $t === '' ? '' : substr($t, 0, 5);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a href="<?= e($base . $tagQuery) ?>">Stände</a> · <?= e($day['name']) ?></div>
        <h1 class="page-title"><?= e($station['name']) ?></h1>
        <p class="page-sub">Teilnehmende je Zeitblock<?php if (!empty($station['location'])): ?> · <?= e($station['location']) ?><?php endif; ?></p>
    </div>
    <div class="page-actions">
        <?php if ($auth->can(P::EINSCHREIBUNGEN_SEHEN)): ?>
            <a class="btn" href="<?= e($ctx->url('/admin/einschreibungen?stand=' . $station['id'])) ?>">Einschreibungen verwalten</a>
        <?php endif; ?>
        <?php if ($auth->can(P::STAENDE_BEARBEITEN)): ?>
            <a class="btn btn-ghost" href="<?= e($base . '/' . $station['id'] . $tagQuery) ?>">Stand bearbeiten</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($blocks === []): ?>
    <div class="card"><div class="empty-state">Dieser Stand wird in keinem Zeitblock angeboten.</div></div>
<?php endif; ?>

<?php foreach ($blocks as $block): ?>
    <?php
    $rows = $byBlock[(int) $block['id']] ?? [];
    $assigned = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'assigned'));
    ?>
    <div class="card mb-2">
        <div class="card-header">
            <h2><?= e($block['name']) ?>
                <span class="text-soft text-sm"><?= e($timeShort($block['start_time'])) ?><?= $block['end_time'] ? '–' . e($timeShort($block['end_time'])) : '' ?></span>
            </h2>
            <span class="badge <?= $assigned >= (int) $block['capacity'] ? 'badge-danger' : 'badge-success' ?>"><?= e((string) $assigned) ?> / <?= e((string) $block['capacity']) ?> belegt</span>
        </div>
        <?php if ($rows === []): ?>
            <div class="empty-state">Noch niemand eingeschrieben.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>#</th><th>Name</th><th>Klasse</th><th>Status</th><th>Quelle</th><th>Seit</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $i => $row): ?>
                        <tr>
                            <td class="text-soft"><?= e((string) ($i + 1)) ?></td>
                            <td><?= e(trim($row['lastname'] . ', ' . $row['firstname']) ?: $row['username']) ?></td>
                            <td><?= e($row['class'] ?? '–') ?></td>
                            <td>
                                <span class="badge <?= e($badgeClass[$row['status']] ?? '') ?>"><?= e($statusLabels[$row['status']] ?? $row['status']) ?></span>
                                <?php if ($row['status'] === 'wish'): ?><span class="text-soft text-sm">Prio <?= e((string) $row['priority']) ?></span><?php endif; ?>
                            </td>
                            <td><?= e($sourceLabels[$row['source']] ?? $row['source']) ?></td>
                            <td class="nowrap text-sm text-soft"><?= e(format_datetime($row['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
