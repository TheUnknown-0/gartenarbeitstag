<?php
/**
 * Darstellung — Farben, Login-Hintergrund und Navigation.
 * Erwartet: $theme (primary/bg/login_image), $navLayout, $navSections
 * (aus CustomizeController::navCatalog()).
 */

/** Rendert eine sortierbare Liste für den Navigations-Editor. */
$sortableList = static function (string $section, array $items, array $layout, array $locked = []) {
    $order = is_array($layout['order'] ?? null) ? $layout['order'] : [];
    $hidden = is_array($layout['hidden'] ?? null) ? $layout['hidden'] : [];
    $sorted = [];
    foreach ($order as $key) {
        if (isset($items[$key])) {
            $sorted[$key] = $items[$key];
        }
    }
    foreach ($items as $key => $item) {
        if (!isset($sorted[$key])) {
            $sorted[$key] = $item;
        }
    }
    foreach ($sorted as $key => [$icon, $label]) {
        $isHidden = in_array($key, $hidden, true);
        $isLocked = in_array($key, $locked, true);
        echo '<li class="sort-item' . ($isHidden ? ' is-hidden' : '') . '" draggable="true" data-key="' . e($key) . '">'
            . '<input type="hidden" name="order[' . e($section) . '][]" value="' . e($key) . '">'
            . '<input type="hidden" name="hidden[' . e($section) . '][]" value="' . e($key) . '"' . ($isHidden ? '' : ' disabled') . '>'
            . '<span class="drag-handle" aria-hidden="true">⠿</span>'
            . '<span class="nav-icon" aria-hidden="true">' . $icon . '</span>'
            . '<span class="sort-label">' . e($label) . '</span>'
            . ($isLocked
                ? '<span class="badge">immer sichtbar</span>'
                : '<button type="button" class="btn btn-sm" data-toggle-hidden aria-pressed="' . ($isHidden ? 'true' : 'false') . '">'
                    . ($isHidden ? '🚫 ausgeblendet' : '👁 sichtbar') . '</button>')
            . '</li>';
    }
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">🎨 Darstellung</h1>
        <p class="page-sub">Farben, Login-Hintergrund und die Anordnung der Navigation.</p>
    </div>
    <div class="page-actions">
        <form method="post" action="<?= e($ctx->url('/admin/darstellung/zuruecksetzen')) ?>"
              data-confirm="Farben und Navigation wirklich auf den Standard zurücksetzen? Auch alle Seiten-Anordnungen (🧩 Anordnen) werden entfernt.">
            <?= $csrf->field() ?>
            <button class="btn btn-danger-ghost" type="submit">↺ Alles zurücksetzen</button>
        </form>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>🎨 Farben</h3></div>
        <div class="card-body">
            <form method="post" action="<?= e($ctx->url('/admin/darstellung')) ?>">
                <?= $csrf->field() ?>
                <input type="hidden" name="section" value="farben">
                <div class="form-grid">
                    <div class="field">
                        <label for="primary">Primärfarbe (Buttons, Links, Akzente)</label>
                        <div class="cluster">
                            <input type="color" id="primary" name="primary" value="<?= e($theme['primary'] ?? '#2f7d4f') ?>"
                                   style="width:48px;height:38px;padding:2px;border:1px solid var(--border-strong);border-radius:6px;background:var(--surface);cursor:pointer;">
                            <label class="checkbox-row mb-0">
                                <input type="checkbox" name="primary_default" value="1" <?= $theme['primary'] === null ? 'checked' : '' ?>>
                                <span class="text-sm">Standard</span>
                            </label>
                        </div>
                    </div>
                    <div class="field">
                        <label for="bg">Seitenhintergrund (helles Design)</label>
                        <div class="cluster">
                            <input type="color" id="bg" name="bg" value="<?= e($theme['bg'] ?? '#f3f4f6') ?>"
                                   style="width:48px;height:38px;padding:2px;border:1px solid var(--border-strong);border-radius:6px;background:var(--surface);cursor:pointer;">
                            <label class="checkbox-row mb-0">
                                <input type="checkbox" name="bg_default" value="1" <?= $theme['bg'] === null ? 'checked' : '' ?>>
                                <span class="text-sm">Standard</span>
                            </label>
                        </div>
                        <div class="hint">Der Dunkelmodus behält seinen neutralen Hintergrund.</div>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">Farben speichern</button>
            </form>
            <hr class="divider">
            <form method="post" action="<?= e($ctx->url('/admin/darstellung')) ?>" enctype="multipart/form-data">
                <?= $csrf->field() ?>
                <input type="hidden" name="section" value="hintergrund">
                <div class="field">
                    <label for="background">Login-Hintergrundbild (JPG, PNG oder WebP, max. 4 MB)</label>
                    <input class="input" type="file" id="background" name="background" accept=".jpg,.jpeg,.png,.webp">
                    <div class="hint">Erscheint leicht abgedunkelt hinter der Anmelde-Karte.</div>
                </div>
                <div class="cluster">
                    <button class="btn btn-primary" type="submit">Hintergrund speichern</button>
                    <?php if ($theme['login_image'] !== null): ?>
                        <button class="btn btn-danger-ghost" type="submit" name="remove" value="1">Bild entfernen</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>🧩 Seiten-Inhalte anordnen</h3></div>
        <div class="card-body">
            <p class="text-sm text-soft mb-0">
                Jede Seite lässt sich <strong>direkt auf der Seite selbst</strong> anordnen: öffnen, oben rechts
                <span class="badge">🧩 Anordnen</span> klicken, Abschnitte ziehen oder ausblenden. In der Leiste
                unten wählst du, ob die Anordnung für <strong>alle Rollen</strong> oder nur für eine bestimmte
                Rolle gelten soll.
            </p>
        </div>
    </div>
</div>

<form class="card mt-2" method="post" action="<?= e($ctx->url('/admin/darstellung')) ?>" id="nav-editor">
    <?= $csrf->field() ?>
    <input type="hidden" name="section" value="navigation">
    <div class="card-header">
        <h3>📋 Navigation anordnen</h3>
        <button class="btn btn-primary btn-sm" type="submit">Anordnung speichern</button>
    </div>
    <div class="card-body">
        <p class="text-sm text-soft">
            Ziehe Einträge in die gewünschte Reihenfolge und blende aus, was nicht gebraucht wird. Ausgeblendete
            Seiten bleiben über ihre Adresse erreichbar — Berechtigungen filtern immer zusätzlich.
        </p>
        <div class="grid-3">
            <?php foreach ($navSections as $sectionKey => [$sectionTitle, $items]): ?>
                <div>
                    <h4 class="text-sm" style="text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft);"><?= e($sectionTitle) ?></h4>
                    <ul class="sort-list" data-sortable data-section="<?= e($sectionKey) ?>">
                        <?php $sortableList($sectionKey, $items, $navLayout[$sectionKey] ?? [], $sectionKey === 'admin' ? ['darstellung'] : []) ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</form>
