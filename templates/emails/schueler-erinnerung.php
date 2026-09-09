<?php
/**
 * HTML-Teil der Schüler:innen-Erinnerung (E-Mail, kein Layout).
 * Erwartet: $name, $day, $blocks (list), $byBlock (time_block_id => Zeile mit station/location), $urlFor.
 */
use App\Services\DayQueries;
?>
<!doctype html>
<html lang="de">
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1a1e24;">
<div style="max-width:520px;margin:0 auto;padding:24px 16px;">
    <div style="background:#ffffff;border-radius:8px;padding:24px;border:1px solid #e2e5ea;">
        <h1 style="font-size:18px;margin:0 0 12px;">Gartenarbeitstag „<?= e($day['name']) ?>“</h1>
        <p style="margin:0 0 16px;">Hallo <?= e($name) ?>,</p>
        <p style="margin:0 0 16px;">
            hier ist dein Zeitplan für den Gartenarbeitstag
            <?php if (!empty($day['event_date'])): ?>
                am <strong><?= e(format_date($day['event_date'])) ?></strong>
            <?php endif; ?>:
        </p>
        <table style="width:100%;border-collapse:collapse;margin:0 0 16px;">
            <?php foreach ($blocks as $block): ?>
                <?php $row = $byBlock[(int) $block['id']] ?? null; ?>
                <tr>
                    <td style="padding:6px 8px;border-bottom:1px solid #eef0f3;font-size:13px;color:#6b7280;white-space:nowrap;">
                        <?= e(DayQueries::blockLabel($block)) ?>
                    </td>
                    <td style="padding:6px 8px;border-bottom:1px solid #eef0f3;">
                        <?php if ($row !== null): ?>
                            <strong><?= e($row['station']) ?></strong><?= !empty($row['location']) ? ' — ' . e($row['location']) : '' ?>
                        <?php else: ?>
                            <span style="color:#9aa1ab;">–</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p style="margin:0 0 20px;">
            <a href="<?= e($urlFor('/mein-plan')) ?>" style="display:inline-block;background:#2f7a3a;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;">
                Plan online ansehen
            </a>
        </p>
        <p style="margin:0;color:#6b7280;font-size:13px;">Viele Grüße</p>
    </div>
</div>
</body>
</html>
