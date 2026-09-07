<?php
/**
 * Automatische Zuteilung (Wunschmodus).
 * Erwartet: $day, $options, $report (null|array), $blockStats, $students, $canReset.
 */

$isWishlist = ($day['mode'] ?? '') === 'wishlist';
$done = !empty($day['assignment_done_at']);
$seed = $options['seed'] ?? null;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung · <?= e($day['name']) ?></div>
        <h1 class="page-title">Zuteilung</h1>
        <p class="page-sub">Wünsche der Schüler:innen automatisch auf Stände verteilen. Erst Probelauf, dann ausführen.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen?status=wish')) ?>">Wünsche ansehen</a>
        <a class="btn btn-ghost" href="<?= e($ctx->url('/admin/einschreibungen')) ?>">Einschreibungen</a>
    </div>
</div>

<?php if (!$isWishlist): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <h3>Kein Wunschmodus</h3>
                <p class="text-soft">Der aktive Aktionstag läuft im Modus „<?= e((string) $day['mode']) ?>“. Die automatische Zuteilung ist nur im Wunschmodus sinnvoll — Schüler:innen schreiben sich hier direkt selbst ein.</p>
                <p class="text-soft text-sm">Den Modus kannst du in den Einstellungen des Aktionstags ändern.</p>
            </div>
        </div>
    </div>
    <?php return; ?>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= (int) $students ?></div>
        <div class="stat-label">aktive Schüler:innen</div>
    </div>
    <?php foreach ($blockStats as $bs): ?>
        <div class="stat-card">
            <div class="stat-value"><?= (int) $bs['wishers'] ?></div>
            <div class="stat-label">mit Wünschen · <?= e($bs['block']['name']) ?></div>
            <div class="text-sm text-soft"><?= (int) $bs['assigned'] ?> / <?= (int) $bs['capacity'] ?> Plätze fest<?= $bs['auto'] > 0 ? ', davon ' . (int) $bs['auto'] . ' automatisch' : '' ?></div>
        </div>
    <?php endforeach; ?>
    <div class="stat-card">
        <div class="stat-value"><?= $done ? '✓' : '–' ?></div>
        <div class="stat-label"><?= $done ? 'zugeteilt am ' . e(format_datetime($day['assignment_done_at'])) : 'noch nicht zugeteilt' ?></div>
    </div>
</div>

<?php if ($done): ?>
    <div class="alert alert-info">
        Die Zuteilung wurde bereits am <?= e(format_datetime($day['assignment_done_at'])) ?> ausgeführt.
        Ein erneuter Lauf vergibt nur noch freie Plätze an Schüler:innen, die noch keinen Platz im jeweiligen Block haben; bestehende Plätze bleiben bestehen.
        <?php if ($canReset): ?>Zum Neustart zuerst unten „Zurücksetzen“ verwenden.<?php endif; ?>
    </div>
<?php endif; ?>

<div class="<?= $canReset ? 'grid-2' : '' ?>">
    <div class="card">
        <div class="card-header"><h3>Optionen</h3></div>
        <div class="card-body">
            <form method="post" action="<?= e($ctx->url('/admin/zuteilung/probelauf')) ?>">
                <?= $csrf->field() ?>
                <div class="field">
                    <label for="seed">Seed (optional)</label>
                    <input class="input" type="number" id="seed" name="seed" min="0" step="1" inputmode="numeric" value="<?= $seed !== null ? (int) $seed : '' ?>" placeholder="zufällig">
                    <div class="hint">Gleicher Seed = gleiches Ergebnis. Nach dem Probelauf wird der verwendete Seed hier eingetragen, damit „Ausführen“ exakt das geprüfte Ergebnis erzeugt.</div>
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" name="fill_unlucky" value="1"<?= !empty($options['fill_unlucky']) ? ' checked' : '' ?>>
                    <span>Pechvögel auffüllen<br><span class="hint">Schüler:innen, die keinen ihrer Wünsche bekommen, auf einen freien Stand setzen.</span></span>
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" name="fill_no_wishes" value="1"<?= !empty($options['fill_no_wishes']) ? ' checked' : '' ?>>
                    <span>Schüler:innen ohne Wünsche verteilen<br><span class="hint">Wer gar keine Wünsche abgegeben hat, wird auf freie Plätze verteilt (bis zur Mindestanzahl Blöcke).</span></span>
                </label>

                <hr>

                <label class="checkbox-row">
                    <input type="checkbox" name="confirm" value="1">
                    <span>Ich habe den Probelauf geprüft.</span>
                </label>

                <div class="cluster mt-2">
                    <button class="btn btn-ghost" type="submit">Probelauf</button>
                    <button class="btn btn-primary" type="submit" formaction="<?= e($ctx->url('/admin/zuteilung/ausfuehren')) ?>"
                            data-confirm="Zuteilung jetzt verbindlich ausführen? Die vergebenen Plätze werden gespeichert und für die Schüler:innen sichtbar.">Zuteilung ausführen</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($canReset): ?>
        <div class="card">
            <div class="card-header"><h3>Zurücksetzen</h3></div>
            <div class="card-body">
                <p class="text-soft">Entfernt alle <strong>automatisch</strong> vergebenen Plätze (Quelle „auto“) dieses Aktionstags. Wünsche sowie manuelle Einschreibungen von Orga oder Schüler:innen bleiben erhalten.</p>
                <form method="post" action="<?= e($ctx->url('/admin/zuteilung/zuruecksetzen')) ?>">
                    <?= $csrf->field() ?>
                    <label class="checkbox-row">
                        <input type="checkbox" name="confirm" value="1" required>
                        <span>Ja, alle automatischen Zuteilungen entfernen.</span>
                    </label>
                    <div class="mt-2">
                        <button class="btn btn-danger" type="submit" data-confirm="Alle automatischen Zuteilungen wirklich entfernen?">Zurücksetzen</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($report !== null): ?>
    <?= $view->renderPartial('pages/assignment/_report', ['report' => $report, 'day' => $day]) ?>
<?php endif; ?>
