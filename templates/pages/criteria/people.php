<?php
/**
 * Schüler:innen mit einem Ausschlusskriterium verwalten.
 * Erwartet: $criterion, $people, $q, $results.
 */

use App\Core\Permissions as P;

$base = $ctx->url('/admin/kriterien');
$peopleUrl = $base . '/' . $criterion['id'] . '/personen';
$fullName = static function (array $row, string $prefix = ''): string {
    $name = trim(($row[$prefix . 'firstname'] ?? '') . ' ' . ($row[$prefix . 'lastname'] ?? ''));

    return $name !== '' ? $name : (string) ($row[$prefix . 'username'] ?? '–');
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a href="<?= e($base) ?>">Ausschlusskriterien</a></div>
        <h1 class="page-title"><?= e($criterion['name']) ?></h1>
        <p class="page-sub">
            <?= !empty($criterion['description']) ? e($criterion['description']) . ' · ' : '' ?>
            <?= e((string) count($people)) ?> Schüler:innen
            <?php if ((int) $criterion['is_active'] !== 1): ?><span class="badge">Inaktiv — sperrt derzeit nicht</span><?php endif; ?>
        </p>
    </div>
    <?php if ($auth->can(P::KRITERIEN_BEARBEITEN)): ?>
        <div class="page-actions">
            <a class="btn btn-ghost" href="<?= e($base . '/' . $criterion['id']) ?>">Kriterium bearbeiten</a>
        </div>
    <?php endif; ?>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>Zugewiesene Schüler:innen</h2></div>
        <?php if ($people === []): ?>
            <div class="empty-state">Noch niemandem zugewiesen.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Name</th><th>Klasse</th><th>Notiz</th><th>Gesetzt</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($people as $row): ?>
                        <tr>
                            <td>
                                <?= e($fullName($row)) ?>
                                <?php if ((int) $row['is_active'] !== 1): ?> <span class="badge">inaktiv</span><?php endif; ?>
                            </td>
                            <td><?= e($row['class'] ?? '–') ?></td>
                            <td class="text-sm"><?= e($row['note'] ?? '') ?></td>
                            <td class="text-sm text-soft nowrap">
                                <?= e(format_date($row['created_at'])) ?>
                                <?php if (!empty($row['set_by_username'])): ?><br>von <?= e($fullName($row, 'set_by_')) ?><?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <form method="post" action="<?= e($peopleUrl . '/' . $row['user_id'] . '/entfernen') ?>" data-confirm="Kriterium bei <?= e($fullName($row)) ?> entfernen?">
                                    <?= $csrf->field() ?>
                                    <button class="btn btn-sm btn-danger-ghost" type="submit">Entfernen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2>Schüler:in zuweisen</h2></div>
        <div class="card-body">
            <form method="get" action="<?= e($peopleUrl) ?>" class="cluster">
                <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, Benutzername oder Klasse" autofocus>
                <button class="btn" type="submit">Suchen</button>
            </form>

            <?php if ($q !== ''): ?>
                <?php if ($results === []): ?>
                    <div class="empty-state">Keine passenden Schüler:innen (ohne dieses Kriterium) gefunden.</div>
                <?php else: ?>
                    <?php if (count($results) >= 50): ?>
                        <div class="hint mt-2">Nur die ersten 50 Treffer — bitte die Suche eingrenzen.</div>
                    <?php endif; ?>
                    <?php /* Formulare außerhalb der Tabelle; Felder verweisen per form-Attribut. */ ?>
                    <?php foreach ($results as $u): ?>
                        <form method="post" action="<?= e($peopleUrl) ?>" id="assign-<?= e((string) $u['id']) ?>">
                            <?= $csrf->field() ?>
                            <input type="hidden" name="user_id" value="<?= e((string) $u['id']) ?>">
                        </form>
                    <?php endforeach; ?>
                    <div class="table-wrap mt-2">
                        <table class="data-table">
                            <thead><tr><th>Name</th><th>Klasse</th><th>Notiz (optional)</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($results as $u): ?>
                                <?php $fid = 'assign-' . (int) $u['id']; ?>
                                <tr>
                                    <td><?= e($fullName($u)) ?> <span class="text-soft text-sm"><?= e($u['username']) ?></span></td>
                                    <td><?= e($u['class'] ?? '–') ?></td>
                                    <td><input class="input" type="text" name="note" form="<?= e($fid) ?>" maxlength="500" placeholder="z. B. laut Elterninfo"></td>
                                    <td class="nowrap"><button class="btn btn-sm btn-primary" type="submit" form="<?= e($fid) ?>">Zuweisen</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="hint mt-2">Suche nach Schüler:innen, um ihnen das Kriterium zuzuweisen. Bereits zugewiesene Personen erscheinen nicht in den Treffern.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
