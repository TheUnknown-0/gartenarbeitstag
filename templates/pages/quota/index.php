<?php
/**
 * Quotenmodus: Bedarf je Stand/Zeitblock/Stufe, optionale Klassen-Präferenz
 * und Prioritätsklasse, Zuteilung erzeugen/zurücksetzen.
 * Erwartet: $day, $stations, $blocks, $grades, $classes, $demand, $preference,
 * $priority, $studentCounts, $assignedCount, $availability (blockId => grade =>
 * einteilbare Schüler:innen), $blockOverlaps (blockId => Liste sich zeitlich
 * überschneidender Block-IDs, inkl. sich selbst), $maxBlocks, $report,
 * $canEdit, $canRun, $canReset, $base.
 */
use App\Services\DayQueries;

$done = !empty($day['assignment_done_at']);
$blocksLayout = page_blocks('admin-quote', [
    'referenz' => 'Klassen-Referenz',
    'bedarf' => 'Bedarf je Stand',
    'praeferenzen' => 'Klassen-Präferenzen',
    'prioritaet' => 'Prioritätsklassen',
    'zuteilung' => 'Zuteilung',
]);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung · <?= e($day['name']) ?></div>
        <h1 class="page-title">🧮 Quote</h1>
        <p class="page-sub">
            Lege je Stand, Zeitblock und Klassenstufe eine Zielzahl fest — der Algorithmus verteilt zufällig
            passende Schüler:innen. Schüler:innen wählen in diesem Modus nichts selbst.
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen?status=assigned')) ?>">Einschreibungen ansehen</a>
    </div>
</div>

<?php if ($stations === []): ?>
    <div class="alert alert-warning">
        Es gibt noch keine aktiven, nicht-manuellen Stände für diesen Aktionstag — lege zuerst Stände an.
    </div>
<?php endif; ?>

<?php if ($done): ?>
    <div class="alert alert-info">
        Die Zuteilung wurde am <?= e(format_datetime($day['assignment_done_at'])) ?> ausgeführt
        (<?= (int) $assignedCount ?> Plätze per Quote). Ein erneuter Lauf ergänzt nur noch freie Blöcke;
        zum Neustart zuerst „Zurücksetzen“ verwenden.
    </div>
<?php endif; ?>

<?php foreach ($blocksLayout as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>

<?php if ($blockKey === 'referenz'): ?>
    <section class="card mb-2">
        <div class="card-header"><h2>👥 Schüler:innen je Klasse und Stufe</h2></div>
        <div class="card-body">
            <?php if ($grades === []): ?>
                <div class="empty-state">Keine aktiven Schüler:innen mit hinterlegter Klasse/Stufe gefunden.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Stufe</th><th>Klassen (Anzahl Schüler:innen)</th><th>Gesamt</th></tr></thead>
                        <tbody>
                            <?php foreach ($grades as $grade): ?>
                                <?php $byClass = $studentCounts[$grade] ?? []; ?>
                                <tr>
                                    <td class="mono"><?= e((string) $grade) ?></td>
                                    <td>
                                        <?php foreach ($byClass as $class => $n): ?>
                                            <span class="badge"><?= e($class) ?>: <?= (int) $n ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="mono"><?= array_sum($byClass) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

<?php elseif ($blockKey === 'bedarf'): ?>
    <section class="card mb-2">
        <div class="card-header"><h2>🎯 Bedarf je Stand</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Wie viele Schüler:innen je Klassenstufe sollen in diesem Zeitblock an diesem Stand mitmachen?
                0 oder leer = kein Bedarf.
            </p>
            <p class="text-soft text-sm">
                Die Zähler unter jeder Tabelle rechnen live gegen: <em>Summe Bedarf / einteilbare Schüler:innen</em>
                der Stufe in diesem Zeitblock — gezählt werden aktive Schüler:innen mit Klasse/Stufe, die dort noch
                nicht fest eingeteilt sind
                <?php if ($maxBlocks !== null): ?>
                    und die erlaubten <?= (int) $maxBlocks ?> Zeitblöcke je Schüler:in noch nicht ausgeschöpft haben<?php endif; ?>.
                Bedarf, den du in anderen Zeitblöcken einträgst, wird mitgerechnet: verplante Schüler:innen zählen in
                zeitgleichen Blöcken<?php if ($maxBlocks !== null): ?> und darüber hinaus (Höchstzahl Zeitblöcke)<?php endif; ?>
                nicht mehr mit. Rot = Bedarf übersteigt das Angebot.
            </p>
            <?php if ($canEdit && $stations !== [] && $blocks !== []): ?>
                <form method="post" action="<?= e($base . '/bedarf') ?>" data-quota-max-blocks="<?= $maxBlocks !== null ? (int) $maxBlocks : '' ?>">
                    <?= $csrf->field() ?>
                    <?php foreach ($blocks as $block): ?>
                        <?php $bid = (int) $block['id']; ?>
                        <h3 class="mt-2"><?= e(DayQueries::blockLabel($block)) ?></h3>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Stand</th>
                                        <?php foreach ($grades as $grade): ?><th>Stufe <?= e((string) $grade) ?></th><?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stations as $station): ?>
                                        <?php $sid = (int) $station['id']; ?>
                                        <tr>
                                            <td><?= e($station['name']) ?></td>
                                            <?php foreach ($grades as $grade): ?>
                                                <td>
                                                    <input class="input" type="number" min="0" max="999" style="width:70px;"
                                                           data-quota-input data-block="<?= $bid ?>" data-grade="<?= $grade ?>"
                                                           name="demand[<?= $sid ?>][<?= $bid ?>][<?= $grade ?>]"
                                                           value="<?= e((string) ($demand[$sid][$bid][$grade] ?? 0)) ?>">
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php
                        $blockAvail = 0;
                        $overlapOthers = array_values(array_filter($blockOverlaps[$bid] ?? [], static fn (int $x): bool => $x !== $bid));
                        ?>
                        <div class="chip-row mb-2" data-quota-balance="<?= $bid ?>" data-quota-overlaps="<?= e(implode(',', $overlapOthers)) ?>" style="margin-top:8px;">
                            <?php foreach ($grades as $grade): ?>
                                <?php $av = (int) ($availability[$bid][$grade] ?? 0); $blockAvail += $av; ?>
                                <span class="badge" data-quota-grade="<?= $grade ?>" data-available="<?= $av ?>"><span>Stufe <?= e((string) $grade) ?>: <span data-quota-sum>0</span>&#8239;/&#8239;<span data-quota-avail><?= $av ?></span></span></span>
                            <?php endforeach; ?>
                            <span class="badge" data-quota-total data-available="<?= $blockAvail ?>"><span>Block gesamt: <span data-quota-sum>0</span>&#8239;/&#8239;<span data-quota-avail><?= $blockAvail ?></span></span></span>
                        </div>
                    <?php endforeach; ?>
                    <button class="btn btn-primary mt-2" type="submit">Bedarf speichern</button>
                </form>
            <?php elseif (!$canEdit): ?>
                <p class="text-faint text-sm">Keine Berechtigung zum Bearbeiten.</p>
            <?php endif; ?>
        </div>
    </section>

<?php elseif ($blockKey === 'praeferenzen'): ?>
    <section class="card mb-2">
        <div class="card-header"><h2>⚖️ Klassen-Präferenzen (optional)</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Ohne Eintrag verteilt ein Stand gleichmäßig (Round-Robin) über alle Klassen einer Stufe.
                Mit Gewicht &gt; 0 werden <strong>nur</strong> die hier eingetragenen Klassen für diesen Stand
                berücksichtigt, anteilig nach Gewicht (z. B. 2 vs. 1 = doppelt so oft).
            </p>
            <?php if ($canEdit && $stations !== []): ?>
                <form method="post" action="<?= e($base . '/praeferenzen') ?>">
                    <?= $csrf->field() ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Klasse</th>
                                    <?php foreach ($stations as $station): ?><th><?= e($station['name']) ?></th><?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class): ?>
                                    <tr>
                                        <td><?= e($class) ?></td>
                                        <?php foreach ($stations as $station): ?>
                                            <?php $sid = (int) $station['id']; ?>
                                            <td>
                                                <input class="input" type="number" min="0" max="999" style="width:60px;"
                                                       name="preference[<?= $sid ?>][<?= e($class) ?>]"
                                                       value="<?= e((string) ($preference[$sid][$class] ?? 0)) ?>">
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-primary mt-2" type="submit">Präferenzen speichern</button>
                </form>
            <?php elseif (!$canEdit): ?>
                <p class="text-faint text-sm">Keine Berechtigung zum Bearbeiten.</p>
            <?php endif; ?>
        </div>
    </section>

<?php elseif ($blockKey === 'prioritaet'): ?>
    <section class="card mb-2">
        <div class="card-header"><h2>🔖 Prioritätsklassen je Zeitblock (optional)</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Reserviert Schüler:innen der gewählten Klasse vorab für diesen Zeitblock (z. B. Klassenausflug),
                bevor der Rest per Round-Robin verteilt wird.
            </p>
            <?php if ($canEdit && $blocks !== []): ?>
                <form method="post" action="<?= e($base . '/prioritaet') ?>">
                    <?= $csrf->field() ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Zeitblock</th>
                                    <?php foreach ($grades as $grade): ?><th>Stufe <?= e((string) $grade) ?></th><?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($blocks as $block): ?>
                                    <?php $bid = (int) $block['id']; ?>
                                    <tr>
                                        <td><?= e(DayQueries::blockLabel($block)) ?></td>
                                        <?php foreach ($grades as $grade): ?>
                                            <td>
                                                <select class="input" name="priority[<?= $bid ?>][<?= $grade ?>]">
                                                    <option value="">– keine –</option>
                                                    <?php foreach ($classes as $class): ?>
                                                        <option value="<?= e($class) ?>" <?= ($priority[$bid][$grade] ?? '') === $class ? 'selected' : '' ?>><?= e($class) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-primary mt-2" type="submit">Prioritäten speichern</button>
                </form>
            <?php elseif (!$canEdit): ?>
                <p class="text-faint text-sm">Keine Berechtigung zum Bearbeiten.</p>
            <?php endif; ?>
        </div>
    </section>

<?php elseif ($blockKey === 'zuteilung'): ?>
    <div class="<?= $canReset ? 'grid-2' : '' ?>">
        <section class="card">
            <div class="card-header"><h3>Zuteilung generieren</h3></div>
            <div class="card-body">
                <?php if ($canRun): ?>
                    <form method="post" action="<?= e($base . '/probelauf') ?>">
                        <?= $csrf->field() ?>
                        <label class="checkbox-row">
                            <input type="checkbox" name="confirm" value="1">
                            <span>Ich habe den Probelauf geprüft.</span>
                        </label>
                        <div class="cluster mt-2">
                            <button class="btn btn-ghost" type="submit">Probelauf</button>
                            <button class="btn btn-primary" type="submit" formaction="<?= e($base . '/generieren') ?>"
                                    data-confirm="Zuteilung jetzt verbindlich ausführen? Die vergebenen Plätze werden gespeichert und für die Schüler:innen sichtbar.">
                                Zuteilung generieren
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="text-faint text-sm">Keine Berechtigung zum Ausführen.</p>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($canReset): ?>
            <section class="card">
                <div class="card-header"><h3>Zurücksetzen</h3></div>
                <div class="card-body">
                    <p class="text-soft">Entfernt alle per Quote vergebenen Plätze (Quelle „quota“). Manuelle Einschreibungen bleiben erhalten.</p>
                    <form method="post" action="<?= e($base . '/zuruecksetzen') ?>">
                        <?= $csrf->field() ?>
                        <label class="checkbox-row">
                            <input type="checkbox" name="confirm" value="1" required>
                            <span>Ja, alle per Quote vergebenen Plätze entfernen.</span>
                        </label>
                        <div class="mt-2">
                            <button class="btn btn-danger" type="submit" data-confirm="Alle per Quote vergebenen Plätze wirklich entfernen?">Zurücksetzen</button>
                        </div>
                    </form>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <?php if ($report !== null): ?>
        <section class="card mt-2">
            <div class="card-header"><h3><?= $report['simulated'] ? '🔍 Probelauf-Ergebnis' : '✅ Zuteilung erzeugt' ?></h3></div>
            <div class="card-body">
                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= (int) $report['assigned_total'] ?></div>
                        <div class="stat-label">Plätze <?= $report['simulated'] ? 'würden vergeben' : 'vergeben' ?></div>
                    </div>
                    <div class="stat-card<?= $report['conflicts'] !== [] ? ' stat-danger' : ' stat-success' ?>">
                        <div class="stat-value"><?= count($report['conflicts']) ?></div>
                        <div class="stat-label">Konflikte (Bedarf nicht voll gedeckt)</div>
                    </div>
                </div>
                <?php if ($report['by_block'] !== []): ?>
                    <table class="data-table mt-2">
                        <thead><tr><th>Zeitblock</th><th>Plätze vergeben</th></tr></thead>
                        <tbody>
                            <?php foreach ($report['by_block'] as $bb): ?>
                                <tr><td><?= e($bb['name']) ?></td><td><?= (int) $bb['assigned'] ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if ($report['conflicts'] !== []): ?>
                    <ul class="mt-2">
                        <?php foreach ($report['conflicts'] as $conflict): ?>
                            <li class="text-sm text-soft"><?= e($conflict) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?= block_close() ?>
<?php endforeach; ?>
