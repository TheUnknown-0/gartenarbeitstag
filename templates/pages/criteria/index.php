<?php
/**
 * Ausschlusskriterien — Liste mit Inline-Formular zum Anlegen.
 * Erwartet: $criteria (mit user_count, station_count), $old.
 */

use App\Core\Permissions as P;

$canEdit = $auth->can(P::KRITERIEN_BEARBEITEN);
$canAssign = $auth->can(P::KRITERIEN_ZUWEISEN);
$base = $ctx->url('/admin/kriterien');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">🚫 Ausschlusskriterien</h1>
        <p class="page-sub">Kriterien wie Allergien oder Einschränkungen: Ein Stand mit einem Kriterium ist für Schüler:innen mit diesem Kriterium <strong>nie</strong> buchbar — auch nicht durch die Orga.</p>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <?php if ($criteria === []): ?>
            <div class="empty-state">Noch keine Kriterien angelegt.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kriterium</th>
                            <th>Status</th>
                            <th class="nowrap">Schüler:innen</th>
                            <th class="nowrap">Stände</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($criteria as $criterion): ?>
                        <?php $id = (int) $criterion['id']; ?>
                        <tr>
                            <td>
                                <strong><?= e($criterion['name']) ?></strong>
                                <?php if (!empty($criterion['description'])): ?>
                                    <div class="text-sm text-soft"><?= e($criterion['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $criterion['is_active'] === 1 ? '<span class="badge badge-success">Aktiv</span>' : '<span class="badge">Inaktiv</span>' ?></td>
                            <td>
                                <?php if ($canAssign): ?>
                                    <a href="<?= e($base . '/' . $id . '/personen') ?>"><?= e((string) $criterion['user_count']) ?></a>
                                <?php else: ?>
                                    <?= e((string) $criterion['user_count']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $criterion['station_count']) ?></td>
                            <td class="nowrap">
                                <div class="cluster">
                                    <?php if ($canAssign): ?>
                                        <a class="btn btn-sm" href="<?= e($base . '/' . $id . '/personen') ?>">Personen</a>
                                    <?php endif; ?>
                                    <?php if ($canEdit): ?>
                                        <a class="btn btn-sm btn-ghost" href="<?= e($base . '/' . $id) ?>">Bearbeiten</a>
                                        <?php if ((int) $criterion['user_count'] === 0 && (int) $criterion['station_count'] === 0): ?>
                                            <form method="post" action="<?= e($base . '/' . $id . '/loeschen') ?>" data-confirm="Kriterium „<?= e($criterion['name']) ?>“ löschen?">
                                                <?= $csrf->field() ?>
                                                <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($canEdit): ?>
        <div class="card">
            <div class="card-header"><h2>Neues Kriterium</h2></div>
            <div class="card-body">
                <form method="post" action="<?= e($base) ?>">
                    <?= $csrf->field() ?>
                    <div class="field">
                        <label for="name">Name *</label>
                        <input class="input" type="text" id="name" name="name" required maxlength="150" value="<?= e($old['name'] ?? '') ?>" placeholder="z. B. Pollenallergie">
                    </div>
                    <div class="field">
                        <label for="description">Beschreibung</label>
                        <textarea class="input" id="description" name="description" rows="2" maxlength="500" placeholder="Kurz erklären, wann das Kriterium zutrifft."><?= e($old['description'] ?? '') ?></textarea>
                    </div>
                    <div class="field">
                        <label for="sort_order">Reihenfolge</label>
                        <input class="input" type="number" id="sort_order" name="sort_order" min="0" max="999" value="<?= e($old['sort_order'] ?? (string) count($criteria)) ?>">
                    </div>
                    <button class="btn btn-primary" type="submit">Kriterium anlegen</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
