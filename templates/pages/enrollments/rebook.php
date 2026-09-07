<?php
/**
 * Einschreibung umbuchen.
 * Erwartet: $day, $enrollment, $input, $stations, $blocks, $stationBlocks, $violations, $overridable, $statusLabels.
 */
use App\Services\DayQueries;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung · Einschreibungen</div>
        <h1 class="page-title">Einschreibung umbuchen</h1>
        <p class="page-sub">Die bestehende Buchung wird ersetzt; ein frei werdender Platz geht an die Warteliste.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen')) ?>">Abbrechen</a>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Aktuelle Buchung</h3></div>
        <div class="card-body stack">
            <div><span class="text-soft text-sm">Schüler:in</span><br><strong><?= e(DayQueries::personName($enrollment)) ?></strong> (<?= e((string) $enrollment['class']) ?>)</div>
            <div><span class="text-soft text-sm">Stand</span><br><?= e($enrollment['name']) ?></div>
            <div><span class="text-soft text-sm">Zeitblock</span><br><?= e($enrollment['block_name']) ?></div>
            <div><span class="text-soft text-sm">Status</span><br><span class="badge"><?= e($statusLabels[$enrollment['status']] ?? $enrollment['status']) ?></span></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Neue Buchung</h3></div>
        <div class="card-body">
            <form method="post" action="<?= e($ctx->url('/admin/einschreibungen/' . (int) $enrollment['id'] . '/umbuchen')) ?>"
                  data-enrollment-form
                  data-check-url="<?= e($ctx->url('/api/einschreibungen/pruefen')) ?>"
                  data-ignore-id="<?= (int) $enrollment['id'] ?>"
                  data-station-blocks="<?= e(json_encode($stationBlocks, JSON_THROW_ON_ERROR)) ?>">
                <?= $csrf->field() ?>
                <input type="hidden" name="user_id" value="<?= (int) $enrollment['user_id'] ?>" data-check-field>

                <div class="field">
                    <label for="station_id">Stand</label>
                    <select class="input" id="station_id" name="station_id" required data-check-field data-station-select>
                        <?php foreach ($stations as $st): ?>
                            <option value="<?= (int) $st['id'] ?>"<?= (int) $input['station_id'] === (int) $st['id'] ? ' selected' : '' ?><?= (int) $st['is_active'] === 0 ? ' disabled' : '' ?>>
                                <?= e($st['name']) ?><?= !empty($st['location']) ? ' · ' . e($st['location']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="time_block_id">Zeitblock</label>
                    <select class="input" id="time_block_id" name="time_block_id" required data-check-field data-block-select>
                        <?php foreach ($blocks as $b): ?>
                            <option value="<?= (int) $b['id'] ?>"<?= (int) $input['time_block_id'] === (int) $b['id'] ? ' selected' : '' ?>><?= e(DayQueries::blockLabel($b)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint" data-block-hint hidden>Dieser Stand wird im gewählten Zeitblock nicht angeboten.</div>
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
                    <button class="btn btn-primary" type="submit">Umbuchen</button>
                    <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen')) ?>">Abbrechen</a>
                </div>
            </form>
        </div>
    </div>
</div>
