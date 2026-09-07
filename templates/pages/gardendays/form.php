<?php
/**
 * Aktionstag anlegen/bearbeiten inkl. Zeitblöcke.
 * Erwartet: $day (null beim Anlegen), $blocks, $old, optional $stationCount.
 */

$isNew = $day === null;
$v = static fn (string $key, mixed $default = ''): string => (string) ($old[$key] ?? $day[$key] ?? $default);
$dtLocal = static function (string $value): string {
    if ($value === '') {
        return '';
    }
    $ts = strtotime($value);

    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
};
$timeShort = static fn (?string $t): string => $t === null || $t === '' ? '' : substr($t, 0, 5);
$mode = $v('mode', 'direct');
$action = $isNew ? $ctx->url('/admin/aktionstage/neu') : $ctx->url('/admin/aktionstage/' . $day['id']);
$stationCount = $stationCount ?? 0;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a href="<?= e($ctx->url('/admin/aktionstage')) ?>">Aktionstage</a></div>
        <h1 class="page-title"><?= $isNew ? 'Neuer Aktionstag' : e($day['name']) ?></h1>
        <?php if (!$isNew): ?>
            <p class="page-sub">
                Status:
                <?php if ($day['status'] === 'active'): ?><span class="badge badge-success">Aktiv</span>
                <?php elseif ($day['status'] === 'archived'): ?><span class="badge">Archiviert</span>
                <?php else: ?><span class="badge badge-info">Entwurf</span><?php endif; ?>
                <?php if (!empty($day['assignment_done_at'])): ?>
                    · Zuteilung durchgeführt am <?= e(format_datetime($day['assignment_done_at'])) ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <?php if (!$isNew): ?>
        <div class="page-actions">
            <a class="btn" href="<?= e($ctx->url('/admin/staende?tag=' . $day['id'])) ?>">🌱 Stände (<?= e((string) $stationCount) ?>)</a>
            <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/aktionstage/' . $day['id'] . '/klonen')) ?>">Klonen</a>
        </div>
    <?php endif; ?>
</div>

<div class="grid-2">
<div class="card">
    <div class="card-header"><h2>Grunddaten</h2></div>
    <div class="card-body">
        <form method="post" action="<?= e($action) ?>" data-gardenday-form>
            <?= $csrf->field() ?>
            <div class="form-grid">
                <div class="field">
                    <label for="name">Bezeichnung *</label>
                    <input class="input" type="text" id="name" name="name" required maxlength="150" value="<?= e($v('name')) ?>" placeholder="z. B. Gartenarbeitstag <?= e(date('Y')) ?>">
                </div>
                <div class="field">
                    <label for="event_date">Datum</label>
                    <input class="input" type="date" id="event_date" name="event_date" value="<?= e($v('event_date')) ?>">
                </div>
            </div>

            <div class="field">
                <label for="mode">Anmeldemodus</label>
                <select class="input" id="mode" name="mode" data-mode-select>
                    <option value="direct" <?= $mode === 'direct' ? 'selected' : '' ?>>Sofortbuchung — Schüler:innen buchen Plätze direkt (mit Warteliste)</option>
                    <option value="wishlist" <?= $mode === 'wishlist' ? 'selected' : '' ?>>Wunschliste — Schüler:innen geben Prioritäten ab, die Orga teilt automatisch zu</option>
                </select>
                <div class="hint">
                    <strong>Sofortbuchung:</strong> Wer zuerst kommt, bekommt den Platz; volle Stände führen auf die Warteliste.
                    <strong>Wunschliste:</strong> Alle geben Wünsche ab, danach verteilt die automatische Zuteilung fair nach Prioritäten.
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="registration_start">Anmeldung ab</label>
                    <input class="input" type="datetime-local" id="registration_start" name="registration_start" value="<?= e($dtLocal($v('registration_start'))) ?>">
                    <div class="hint">Leer = sofort möglich.</div>
                </div>
                <div class="field">
                    <label for="registration_end">Anmeldung bis</label>
                    <input class="input" type="datetime-local" id="registration_end" name="registration_end" value="<?= e($dtLocal($v('registration_end'))) ?>">
                    <div class="hint">Leer = kein Ende. Gilt nur für die Selbstbuchung der Schüler:innen.</div>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="min_blocks_per_student">Mindestens Zeitblöcke je Schüler:in</label>
                    <input class="input" type="number" id="min_blocks_per_student" name="min_blocks_per_student" min="0" max="50" value="<?= e($v('min_blocks_per_student', '1')) ?>">
                    <div class="hint">So viele Zeitblöcke soll jede:r belegen (Hinweis für Schüler:innen und Zuteilung).</div>
                </div>
                <div class="field">
                    <label for="max_blocks_per_student">Höchstens Zeitblöcke je Schüler:in</label>
                    <input class="input" type="number" id="max_blocks_per_student" name="max_blocks_per_student" min="1" max="50" value="<?= e($v('max_blocks_per_student')) ?>" placeholder="unbegrenzt">
                    <div class="hint">Leer = unbegrenzt.</div>
                </div>
            </div>

            <div class="form-grid">
                <div class="field" data-mode-only="wishlist">
                    <label for="wishes_per_block">Wünsche je Zeitblock</label>
                    <input class="input" type="number" id="wishes_per_block" name="wishes_per_block" min="1" max="10" value="<?= e($v('wishes_per_block', '3')) ?>">
                    <div class="hint">So viele Prioritäten (1 = Lieblingsstand) gibt jede:r pro Zeitblock ab.</div>
                </div>
                <div class="field" data-mode-only="direct">
                    <label class="checkbox-row">
                        <input type="checkbox" name="waitlist_enabled" value="1" <?= (int) $v('waitlist_enabled', '1') === 1 ? 'checked' : '' ?>>
                        <span>Warteliste bei vollen Ständen</span>
                    </label>
                    <div class="hint">Wird ein Platz frei, rückt die nächste Person automatisch nach.</div>
                </div>
            </div>

            <div class="field">
                <label for="notes">Hinweise (intern)</label>
                <textarea class="input" id="notes" name="notes" rows="3"><?= e($v('notes')) ?></textarea>
            </div>

            <button class="btn btn-primary" type="submit"><?= $isNew ? 'Aktionstag anlegen' : 'Speichern' ?></button>
            <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/aktionstage')) ?>">Zurück</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Zeitblöcke</h2></div>
    <div class="card-body">
        <?php if ($isNew): ?>
            <div class="empty-state">Zeitblöcke legst du an, sobald der Aktionstag gespeichert ist.</div>
        <?php else: ?>
            <p class="text-sm text-soft">Ein Stand kann in mehreren Zeitblöcken angeboten werden; Schüler:innen belegen je Zeitblock höchstens einen Stand.</p>

            <?php if ($blocks === []): ?>
                <div class="alert alert-warning">Noch kein Zeitblock — ohne Zeitblöcke kann kein Stand angeboten werden.</div>
            <?php else: ?>
                <?php /* Formulare außerhalb der Tabelle (ein <form> im <tr> ist ungültiges HTML); Felder verweisen per form-Attribut. */ ?>
                <?php foreach ($blocks as $block): ?>
                    <form method="post" action="<?= e($ctx->url('/admin/aktionstage/' . $day['id'] . '/zeitbloecke/' . $block['id'])) ?>" id="block-form-<?= e((string) $block['id']) ?>">
                        <?= $csrf->field() ?>
                    </form>
                <?php endforeach; ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Bezeichnung</th><th>Von</th><th>Bis</th><th>Reihenfolge</th><th>Stände</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($blocks as $block): ?>
                            <?php $bid = (int) $block['id']; $blockUrl = $ctx->url('/admin/aktionstage/' . $day['id'] . '/zeitbloecke/' . $bid); ?>
                            <tr>
                                <td>
                                    <input class="input" type="text" name="block_name" form="block-form-<?= e((string) $bid) ?>" required maxlength="100" value="<?= e($block['name']) ?>">
                                </td>
                                <td><input class="input" type="time" name="start_time" form="block-form-<?= e((string) $bid) ?>" value="<?= e($timeShort($block['start_time'])) ?>"></td>
                                <td><input class="input" type="time" name="end_time" form="block-form-<?= e((string) $bid) ?>" value="<?= e($timeShort($block['end_time'])) ?>"></td>
                                <td><input class="input" type="number" name="sort_order" form="block-form-<?= e((string) $bid) ?>" min="0" max="999" style="width:5rem;" value="<?= e((string) $block['sort_order']) ?>"></td>
                                <td class="nowrap">
                                    <?= e((string) $block['station_count']) ?>
                                    <span class="text-soft text-sm">· <?= e((string) $block['enrollment_count']) ?> Einschr.</span>
                                </td>
                                <td class="nowrap">
                                    <div class="cluster">
                                        <button class="btn btn-sm" type="submit" form="block-form-<?= e((string) $bid) ?>">Speichern</button>
                                        <?php if ((int) $block['enrollment_count'] === 0): ?>
                                            <form method="post" action="<?= e($blockUrl . '/loeschen') ?>" data-confirm="Zeitblock „<?= e($block['name']) ?>“ löschen?">
                                                <?= $csrf->field() ?>
                                                <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <hr class="divider">
            <h3>Zeitblock hinzufügen</h3>
            <form method="post" action="<?= e($ctx->url('/admin/aktionstage/' . $day['id'] . '/zeitbloecke')) ?>">
                <?= $csrf->field() ?>
                <div class="form-grid">
                    <div class="field">
                        <label for="new_block_name">Bezeichnung *</label>
                        <input class="input" type="text" id="new_block_name" name="block_name" required maxlength="100" placeholder="z. B. Vormittag">
                    </div>
                    <div class="field">
                        <label for="new_sort_order">Reihenfolge</label>
                        <input class="input" type="number" id="new_sort_order" name="sort_order" min="0" max="999" value="<?= e((string) (count($blocks) + 1)) ?>">
                    </div>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="new_start_time">Von</label>
                        <input class="input" type="time" id="new_start_time" name="start_time">
                    </div>
                    <div class="field">
                        <label for="new_end_time">Bis</label>
                        <input class="input" type="time" id="new_end_time" name="end_time">
                    </div>
                </div>
                <?php if ($stationCount > 0): ?>
                    <div class="form-grid">
                        <div class="field">
                            <label class="checkbox-row">
                                <input type="checkbox" name="offer_all" value="1">
                                <span>Für alle <?= e((string) $stationCount) ?> bestehenden Stände anbieten</span>
                            </label>
                        </div>
                        <div class="field">
                            <label for="offer_capacity">Kapazität je Stand</label>
                            <input class="input" type="number" id="offer_capacity" name="offer_capacity" min="0" max="999" value="10">
                        </div>
                    </div>
                <?php endif; ?>
                <button class="btn btn-primary" type="submit">Zeitblock hinzufügen</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</div>
