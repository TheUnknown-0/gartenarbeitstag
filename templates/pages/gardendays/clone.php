<?php
/**
 * Aktionstag klonen.
 * Erwartet: $day (Quelle), $parts (DayCloner::PARTS), $old.
 */
$selected = $old['parts'] ?? array_keys($parts);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a href="<?= e($ctx->url('/admin/aktionstage')) ?>">Aktionstage</a></div>
        <h1 class="page-title">„<?= e($day['name']) ?>“ klonen</h1>
        <p class="page-sub">Übernimmt die Struktur in einen neuen Aktionstag — Einschreibungen, Wünsche und Anwesenheiten werden nie kopiert.</p>
    </div>
</div>

<div class="card" style="max-width:640px;">
    <div class="card-body">
        <form method="post" action="<?= e($ctx->url('/admin/aktionstage/' . $day['id'] . '/klonen')) ?>">
            <?= $csrf->field() ?>
            <div class="form-grid">
                <div class="field">
                    <label for="name">Name des neuen Aktionstags *</label>
                    <input class="input" type="text" id="name" name="name" required maxlength="150"
                           value="<?= e($old['name'] ?? ($day['name'] . ' (Kopie)')) ?>">
                </div>
                <div class="field">
                    <label for="event_date">Datum *</label>
                    <input class="input" type="date" id="event_date" name="event_date" required value="<?= e($old['event_date'] ?? '') ?>">
                </div>
            </div>

            <div class="field">
                <label>Was soll übernommen werden?</label>
                <?php foreach ($parts as $key => $label): ?>
                    <label class="checkbox-row">
                        <input type="checkbox" name="parts[]" value="<?= e($key) ?>" <?= in_array($key, (array) $selected, true) ? 'checked' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
                <div class="hint">Stände setzen Zeitblöcke voraus — sie werden dann automatisch mitkopiert. Der neue Tag startet als Entwurf.</div>
            </div>

            <button class="btn btn-primary" type="submit">Aktionstag klonen</button>
            <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/aktionstage')) ?>">Abbrechen</a>
        </form>
    </div>
</div>
