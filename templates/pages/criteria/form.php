<?php
/**
 * Ausschlusskriterium bearbeiten.
 * Erwartet: $criterion, $userCount, $stationCount, $old.
 */

$base = $ctx->url('/admin/kriterien');
$v = static fn (string $key, mixed $default = ''): string => (string) ($old[$key] ?? $criterion[$key] ?? $default);
$isActive = isset($old['name']) ? (int) ($old['is_active'] ?? 0) === 1 : (int) $criterion['is_active'] === 1;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a href="<?= e($base) ?>">Ausschlusskriterien</a></div>
        <h1 class="page-title"><?= e($criterion['name']) ?></h1>
        <p class="page-sub"><?= e((string) $userCount) ?> Schüler:innen · <?= e((string) $stationCount) ?> Stände</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($base . '/' . $criterion['id'] . '/personen') ?>">Personen verwalten</a>
    </div>
</div>

<div class="card" style="max-width:640px;">
    <div class="card-body">
        <form method="post" action="<?= e($base . '/' . $criterion['id']) ?>">
            <?= $csrf->field() ?>
            <div class="field">
                <label for="name">Name *</label>
                <input class="input" type="text" id="name" name="name" required maxlength="150" value="<?= e($v('name')) ?>">
            </div>
            <div class="field">
                <label for="description">Beschreibung</label>
                <textarea class="input" id="description" name="description" rows="3" maxlength="500"><?= e($v('description')) ?></textarea>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="sort_order">Reihenfolge</label>
                    <input class="input" type="number" id="sort_order" name="sort_order" min="0" max="999" value="<?= e($v('sort_order', '0')) ?>">
                </div>
                <div class="field">
                    <label class="checkbox-row mt-2">
                        <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
                        <span>Aktiv</span>
                    </label>
                    <div class="hint">Inaktive Kriterien sperren nicht mehr und werden bei Ständen nicht mehr angeboten; die Zuordnungen bleiben gespeichert.</div>
                </div>
            </div>
            <button class="btn btn-primary" type="submit">Speichern</button>
            <a class="btn btn-ghost" href="<?= e($base) ?>">Abbrechen</a>
        </form>
    </div>
</div>
