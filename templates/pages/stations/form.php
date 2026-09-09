<?php
/**
 * Stand anlegen/bearbeiten.
 * Erwartet: $day, $station (null beim Anlegen), $blocks, $stationBlocks, $blockEnrollments,
 *           $criteria, $selectedCriteria, $leaderCandidates, $selectedLeaders, $old, $tagQuery.
 */

$isNew = $station === null;
$v = static fn (string $key, mixed $default = ''): string => (string) ($old[$key] ?? $station[$key] ?? $default);
$base = $ctx->url('/admin/staende');
$action = $isNew ? $base . '/neu' . $tagQuery : $base . '/' . $station['id'] . $tagQuery;
$timeShort = static fn (?string $t): string => $t === null || $t === '' ? '' : substr($t, 0, 5);
$roleLabel = ['teacher' => 'Lehrkraft', 'orga' => 'Orga', 'admin' => 'Admin'];

// Auswahlzustände: bei Validierungsfehlern die eingegebenen Werte, sonst DB
$blockChecked = static function (int $blockId) use ($old, $stationBlocks): bool {
    if (isset($old['block'])) {
        return (int) ($old['block'][$blockId] ?? 0) === 1;
    }

    return array_key_exists($blockId, $stationBlocks);
};
$blockCapacity = static function (int $blockId) use ($old, $stationBlocks): string {
    if (isset($old['capacity'][$blockId])) {
        return (string) $old['capacity'][$blockId];
    }

    return (string) ($stationBlocks[$blockId] ?? 10);
};
$criteriaSelected = isset($old['name']) ? array_map('intval', (array) ($old['criteria'] ?? [])) : $selectedCriteria;
$leadersSelected = isset($old['name']) ? array_map('intval', (array) ($old['leaders'] ?? [])) : $selectedLeaders;
$isActive = isset($old['name']) ? (int) ($old['is_active'] ?? 0) === 1 : ($isNew || (int) $station['is_active'] === 1);
$manualOnly = isset($old['name']) ? (int) ($old['manual_only'] ?? 0) === 1 : (!$isNew && (int) $station['manual_only'] === 1);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a href="<?= e($base . $tagQuery) ?>">Stände</a> · <?= e($day['name']) ?></div>
        <h1 class="page-title"><?= $isNew ? 'Neuer Stand' : e($station['name']) ?></h1>
    </div>
    <?php if (!$isNew): ?>
        <div class="page-actions">
            <a class="btn btn-ghost" href="<?= e($base . '/' . $station['id'] . '/teilnehmer') ?>">Teilnehmende</a>
        </div>
    <?php endif; ?>
</div>

<form method="post" action="<?= e($action) ?>">
    <?= $csrf->field() ?>
    <div class="grid-2">
        <div class="stack">
            <div class="card">
                <div class="card-header"><h2>Stammdaten</h2></div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="field">
                            <label for="name">Name *</label>
                            <input class="input" type="text" id="name" name="name" required maxlength="150" value="<?= e($v('name')) ?>" placeholder="z. B. Hochbeete anlegen">
                        </div>
                        <div class="field">
                            <label for="location">Ort</label>
                            <input class="input" type="text" id="location" name="location" maxlength="150" value="<?= e($v('location')) ?>" placeholder="z. B. Schulgarten Nord">
                        </div>
                    </div>
                    <div class="field">
                        <label for="description">Beschreibung</label>
                        <textarea class="input" id="description" name="description" rows="4" placeholder="Was wird gemacht? Wird den Schüler:innen angezeigt."><?= e($v('description')) ?></textarea>
                    </div>
                    <div class="field">
                        <label for="materials">Material / Mitbringen</label>
                        <textarea class="input" id="materials" name="materials" rows="2" placeholder="z. B. Gartenhandschuhe, festes Schuhwerk"><?= e($v('materials')) ?></textarea>
                    </div>
                    <div class="form-grid">
                        <div class="field">
                            <label for="sort_order">Reihenfolge</label>
                            <input class="input" type="number" id="sort_order" name="sort_order" min="0" max="999" value="<?= e($v('sort_order', '0')) ?>">
                        </div>
                        <div class="field">
                            <label class="checkbox-row mt-2">
                                <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
                                <span>Stand ist aktiv (für Schüler:innen buchbar)</span>
                            </label>
                        </div>
                        <div class="field">
                            <label class="checkbox-row mt-2">
                                <input type="checkbox" name="manual_only" value="1" <?= $manualOnly ? 'checked' : '' ?>>
                                <span>Nur manuell besetzbar</span>
                            </label>
                            <div class="hint">
                                Schüler:innen können sich nicht selbst fest einschreiben und werden nicht automatisch
                                zugeteilt — nur Standleitung oder Orga/Admin mit Berechtigung können hier fest
                                einschreiben. Wünsche äußern bleibt weiterhin möglich.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>Limits</h2></div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="field">
                            <label for="max_per_class">Max. je Klasse</label>
                            <input class="input" type="number" id="max_per_class" name="max_per_class" min="1" max="999" value="<?= e($v('max_per_class')) ?>" placeholder="kein Limit">
                            <div class="hint">Pro Zeitblock. Weiche Grenze — Orga kann mit Begründung überschreiben.</div>
                        </div>
                        <div class="field">
                            <label for="max_per_grade">Max. je Jahrgangsstufe</label>
                            <input class="input" type="number" id="max_per_grade" name="max_per_grade" min="1" max="999" value="<?= e($v('max_per_grade')) ?>" placeholder="kein Limit">
                            <div class="hint">Pro Zeitblock. Weiche Grenze.</div>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="field">
                            <label for="allowed_grades">Erlaubte Jahrgangsstufen</label>
                            <input class="input" type="text" id="allowed_grades" name="allowed_grades" value="<?= e($v('allowed_grades')) ?>" placeholder="alle — z. B. 5, 6, 7">
                        </div>
                        <div class="field">
                            <label for="allowed_classes">Erlaubte Klassen</label>
                            <input class="input" type="text" id="allowed_classes" name="allowed_classes" value="<?= e($v('allowed_classes')) ?>" placeholder="alle — z. B. 5a, 5b">
                        </div>
                    </div>
                    <div class="field">
                        <label for="min_students">Mindestbesetzung je Zeitblock</label>
                        <input class="input" type="number" id="min_students" name="min_students" min="0" max="999" value="<?= e($v('min_students', '0')) ?>">
                        <div class="hint">0 = keine. Wird in der Übersicht als Warnung angezeigt, blockiert nichts.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="stack">
            <div class="card">
                <div class="card-header"><h2>Zeitblöcke &amp; Kapazität</h2></div>
                <div class="card-body">
                    <?php if ($blocks === []): ?>
                        <div class="alert alert-warning">Der Aktionstag hat noch keine Zeitblöcke. <a href="<?= e($ctx->url('/admin/aktionstage/' . $day['id'])) ?>">Jetzt anlegen</a></div>
                    <?php else: ?>
                        <p class="text-sm text-soft">In welchen Zeitblöcken wird der Stand angeboten und wie viele Plätze gibt es jeweils?</p>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead><tr><th>Zeitblock</th><th>Zeit</th><th>Kapazität</th></tr></thead>
                                <tbody>
                                <?php foreach ($blocks as $block): ?>
                                    <?php $bid = (int) $block['id']; $n = $blockEnrollments[$bid] ?? 0; ?>
                                    <tr>
                                        <td>
                                            <label class="checkbox-row">
                                                <input type="checkbox" name="block[<?= e((string) $bid) ?>]" value="1" <?= $blockChecked($bid) ? 'checked' : '' ?>>
                                                <span><?= e($block['name']) ?></span>
                                            </label>
                                            <?php if ($n > 0): ?>
                                                <div class="hint"><?= e((string) $n) ?> Einschreibung(en) — nicht abwählbar</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="nowrap text-sm"><?= e($timeShort($block['start_time'])) ?><?= $block['end_time'] ? '–' . e($timeShort($block['end_time'])) : '' ?></td>
                                        <td>
                                            <input class="input" type="number" name="capacity[<?= e((string) $bid) ?>]" min="0" max="999" style="width:6rem;" value="<?= e($blockCapacity($bid)) ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>Ausschlusskriterien</h2></div>
                <div class="card-body">
                    <?php if ($criteria === []): ?>
                        <div class="empty-state">Keine Kriterien angelegt.</div>
                    <?php else: ?>
                        <p class="text-sm text-soft">Schüler:innen mit einem dieser Kriterien können diesen Stand <strong>nicht</strong> belegen — auch nicht durch die Orga.</p>
                        <?php foreach ($criteria as $criterion): ?>
                            <label class="checkbox-row">
                                <input type="checkbox" name="criteria[]" value="<?= e((string) $criterion['id']) ?>" <?= in_array((int) $criterion['id'], $criteriaSelected, true) ? 'checked' : '' ?>>
                                <span><?= e($criterion['name']) ?><?php if (!empty($criterion['description'])): ?> <span class="text-soft text-sm">— <?= e($criterion['description']) ?></span><?php endif; ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>Standleitung</h2></div>
                <div class="card-body">
                    <?php if ($leaderCandidates === []): ?>
                        <div class="empty-state">Keine Lehrkräfte im System.</div>
                    <?php else: ?>
                        <div class="scroll-box">
                            <?php foreach ($leaderCandidates as $user): ?>
                                <?php $label = trim($user['firstname'] . ' ' . $user['lastname']); ?>
                                <label class="checkbox-row">
                                    <input type="checkbox" name="leaders[]" value="<?= e((string) $user['id']) ?>" <?= in_array((int) $user['id'], $leadersSelected, true) ? 'checked' : '' ?>>
                                    <span><?= e($label !== '' ? $label : $user['username']) ?> <span class="text-soft text-sm">(<?= e($roleLabel[$user['role']] ?? $user['role']) ?>)</span></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="cluster mt-2">
        <button class="btn btn-primary btn-lg" type="submit"><?= $isNew ? 'Stand anlegen' : 'Speichern' ?></button>
        <a class="btn btn-ghost" href="<?= e($base . $tagQuery) ?>">Abbrechen</a>
    </div>
</form>
