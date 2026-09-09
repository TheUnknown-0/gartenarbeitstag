<?php
/**
 * HTML-Teil der Standleitungs-Erinnerung (E-Mail, kein Layout).
 * Erwartet: $name, $day, $stations (list mit name/location), $urlFor.
 */
?>
<!doctype html>
<html lang="de">
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1a1e24;">
<div style="max-width:520px;margin:0 auto;padding:24px 16px;">
    <div style="background:#ffffff;border-radius:8px;padding:24px;border:1px solid #e2e5ea;">
        <h1 style="font-size:18px;margin:0 0 12px;">Gartenarbeitstag „<?= e($day['name']) ?>“</h1>
        <p style="margin:0 0 16px;">Hallo <?= e($name) ?>,</p>
        <p style="margin:0 0 16px;">
            kurze Erinnerung an den Gartenarbeitstag
            <?php if (!empty($day['event_date'])): ?>
                am <strong><?= e(format_date($day['event_date'])) ?></strong>
            <?php endif; ?>
            — hier sind deine Stände:
        </p>
        <ul style="margin:0 0 16px;padding-left:20px;">
            <?php foreach ($stations as $station): ?>
                <li><?= e($station['name']) ?><?= !empty($station['location']) ? ' — ' . e($station['location']) : '' ?></li>
            <?php endforeach; ?>
        </ul>
        <p style="margin:0 0 20px;">
            <a href="<?= e($urlFor('/meine-staende')) ?>" style="display:inline-block;background:#2f7a3a;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;">
                Teilnehmerlisten ansehen
            </a>
        </p>
        <p style="margin:0;color:#6b7280;font-size:13px;">Viele Grüße</p>
    </div>
</div>
</body>
</html>
