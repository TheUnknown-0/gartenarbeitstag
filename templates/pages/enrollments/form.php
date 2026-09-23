<?php
/**
 * Einschreibung anlegen (Orga).
 * Erwartet: $day, $input, $students, $stations, $blocks, $stationBlocks, $violations, $overridable, $waitlistEnabled.
 */
use App\Services\DayQueries;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung · Einschreibungen</div>
        <h1 class="page-title">Einschreibung anlegen</h1>
        <p class="page-sub"><?= e($day['name']) ?> — Limits werden beim Speichern geprüft; die Vorschau zeigt Verstöße bereits vorab.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen')) ?>">Abbrechen</a>
    </div>
</div>

<div class="card" style="max-width:820px;">
    <div class="card-body">
        <form method="post" action="<?= e($ctx->url('/admin/einschreibungen/neu')) ?>"
              data-enrollment-form
              data-check-url="<?= e($ctx->url('/api/einschreibungen/pruefen')) ?>"
              data-station-blocks="<?= e(json_encode($stationBlocks, JSON_THROW_ON_ERROR)) ?>">
            <?= $csrf->field() ?>

            <div class="field">
                <label for="user_id">Schüler:in</label>
                <select class="input" id="user_id" name="user_id" required data-check-field
                        data-combobox="Name, Klasse oder Benutzername eintippen …">
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"<?= (int) $input['user_id'] === (int) $s['id'] ? ' selected' : '' ?>>
                            <?= e((string) $s['class']) ?> · <?= e(DayQueries::personName($s)) ?> (<?= e($s['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="station_id">Stand</label>
                    <select class="input" id="station_id" name="station_id" required data-check-field data-station-select>
                        <option value="">– bitte wählen –</option>
                        <?php foreach ($stations as $st): ?>
                            <option value="<?= (int) $st['id'] ?>"<?= (int) $input['station_id'] === (int) $st['id'] ? ' selected' : '' ?><?= (int) $st['is_active'] === 0 ? ' disabled' : '' ?>>
                                <?= e($st['name']) ?><?= !empty($st['location']) ? ' · ' . e($st['location']) : '' ?><?= (int) $st['is_active'] === 0 ? ' (inaktiv)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="time_block_id">Zeitblock</label>
                    <select class="input" id="time_block_id" name="time_block_id" required data-check-field data-block-select>
                        <option value="">– bitte wählen –</option>
                        <?php foreach ($blocks as $b): ?>
                            <option value="<?= (int) $b['id'] ?>"<?= (int) $input['time_block_id'] === (int) $b['id'] ? ' selected' : '' ?>><?= e(DayQueries::blockLabel($b)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint" data-block-hint hidden>Dieser Stand wird im gewählten Zeitblock nicht angeboten.</div>
                </div>
            </div>

            <div class="field">
                <label for="status">Status</label>
                <select class="input" id="status" name="status">
                    <option value="assigned"<?= $input['status'] === 'assigned' ? ' selected' : '' ?>>Fest eingeschrieben</option>
                    <option value="waitlist"<?= $input['status'] === 'waitlist' ? ' selected' : '' ?>>Warteliste<?= $waitlistEnabled ? '' : ' (für diesen Tag deaktiviert – rückt nicht automatisch nach)' ?></option>
                </select>
            </div>

            <div data-check-result hidden>
                <div class="alert alert-info" data-check-ok hidden>✅ Keine Verstöße — <span data-check-occupancy></span></div>
                <div class="alert alert-warning" data-check-list hidden></div>
            </div>

            <?= $view->renderPartial('pages/enrollments/_override', [
                'violations' => $violations,
                'overridable' => $overridable,
                'note' => $input['override_note'] ?? '',
            ]) ?>

            <div class="cluster mt-2">
                <button class="btn btn-primary" type="submit">Einschreibung anlegen</button>
                <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen')) ?>">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
