<?php
/**
 * E-Mail-Verwaltung: Konfigurationsstatus, Test-Mail, Erinnerungen,
 * Versandprotokoll.
 * Erwartet: $day, $configured, $canSend, $log.
 */
$statusLabels = ['sent' => 'gesendet', 'failed' => 'fehlgeschlagen'];
$templateLabels = [
    'test' => 'Test-Mail',
    'standleitung_erinnerung' => 'Standleitung',
    'schueler_erinnerung' => 'Schüler:in',
];
$base = $ctx->url('/admin/emails');

$blocks = page_blocks('admin-emails', [
    'test' => 'Test-Mail',
    'erinnerungen' => 'Erinnerungen',
    'protokoll' => 'Protokoll',
]);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung · <?= e($day['name']) ?></div>
        <h1 class="page-title">📧 E-Mail</h1>
        <p class="page-sub">Test-Mail, Erinnerungen an Standleitungen und Schüler:innen, Versandprotokoll.</p>
    </div>
</div>

<?php if (!$configured): ?>
    <div class="alert alert-warning">
        E-Mail-Versand ist nicht eingerichtet — <span class="mono">MAIL_HOST</span> und
        <span class="mono">MAIL_FROM_ADDRESS</span> fehlen in der Server-Konfiguration (<span class="mono">.env</span>).
        Ohne diese Angaben schlägt jeder Versand fehl.
    </div>
<?php elseif (!$canSend): ?>
    <div class="alert alert-info">
        Du kannst den Status und das Protokoll einsehen, aber keine E-Mails versenden.
        Dafür wird die Berechtigung „Erinnerungs- &amp; Test-Mails versenden“ benötigt.
    </div>
<?php endif; ?>

<?php foreach ($blocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>

<?php if ($blockKey === 'test'): ?>
    <section class="card mb-2">
        <div class="card-header"><h2>✉️ Test-Mail</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">Prüft die SMTP-Konfiguration mit einer einzelnen Mail an eine beliebige Adresse.</p>
            <?php if ($canSend): ?>
                <form method="post" action="<?= e($base . '/test') ?>" class="cluster">
                    <?= $csrf->field() ?>
                    <input class="input" type="email" name="to" placeholder="empfaenger@beispiel.de" required style="min-width:260px;">
                    <button class="btn btn-primary" type="submit">Test-Mail senden</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

<?php elseif ($blockKey === 'erinnerungen'): ?>
    <div class="grid-2">
        <section class="card">
            <div class="card-header"><h2>🧑‍🌾 Standleitungen</h2></div>
            <div class="card-body">
                <p class="text-soft text-sm">
                    Jede Standleitung bekommt ihre Stände mit Ort und einem Link zu den
                    Teilnehmerlisten. Wer schon eine Erinnerung für diesen Aktionstag bekommen
                    hat, wird beim nächsten Klick übersprungen, außer „erneut senden“ ist gesetzt.
                </p>
                <?php if ($canSend): ?>
                    <form method="post" action="<?= e($base . '/standleitungen') ?>" class="stack">
                        <?= $csrf->field() ?>
                        <label class="checkbox-row mb-0">
                            <input type="checkbox" name="force" value="1">
                            <span>Auch an bereits benachrichtigte Standleitungen erneut senden</span>
                        </label>
                        <button class="btn btn-primary" type="submit">Erinnerungen an Standleitungen senden</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
        <section class="card">
            <div class="card-header"><h2>🎒 Schüler:innen</h2></div>
            <div class="card-body">
                <p class="text-soft text-sm">
                    Jede Schülerin/jeder Schüler mit fester Einschreibung bekommt den eigenen
                    Zeitplan. Wer schon eine Erinnerung für diesen Aktionstag bekommen hat,
                    wird beim nächsten Klick übersprungen, außer „erneut senden“ ist gesetzt.
                </p>
                <?php if ($canSend): ?>
                    <form method="post" action="<?= e($base . '/schueler') ?>" class="stack">
                        <?= $csrf->field() ?>
                        <label class="checkbox-row mb-0">
                            <input type="checkbox" name="force" value="1">
                            <span>Auch an bereits benachrichtigte Schüler:innen erneut senden</span>
                        </label>
                        <button class="btn btn-primary" type="submit">Erinnerungen an Schüler:innen senden</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>

<?php elseif ($blockKey === 'protokoll'): ?>
    <section class="card mt-2">
        <div class="card-header"><h2>📜 Protokoll</h2></div>
        <?php if ($log === []): ?>
            <div class="card-body"><div class="empty-state">Noch keine E-Mails versendet.</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Zeit</th><th>Empfänger</th><th>Betreff</th><th>Vorlage</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log as $row): ?>
                            <?php $name = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? '')); ?>
                            <tr>
                                <td class="nowrap text-sm"><?= e(format_datetime($row['created_at'])) ?></td>
                                <td>
                                    <?= e($row['recipient']) ?>
                                    <?php if ($name !== ''): ?><div class="text-soft text-sm"><?= e($name) ?></div><?php endif; ?>
                                </td>
                                <td><?= e($row['subject']) ?></td>
                                <td><?= e($templateLabels[$row['template']] ?? $row['template']) ?></td>
                                <td>
                                    <span class="badge <?= $row['status'] === 'sent' ? 'badge-success' : 'badge-danger' ?>">
                                        <?= e($statusLabels[$row['status']] ?? $row['status']) ?>
                                    </span>
                                    <?php if (!empty($row['error'])): ?>
                                        <div class="text-soft text-sm" title="<?= e($row['error']) ?>"><?= e(mb_substr((string) $row['error'], 0, 60)) ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?= block_close() ?>
<?php endforeach; ?>
