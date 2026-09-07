<?php
/**
 * Bestätigung: Wartelisten-Eintrag trotz Verstößen fest setzen.
 * Erwartet: $day, $enrollment, $violations, $overridable, $back.
 */
use App\Services\DayQueries;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung · Einschreibungen</div>
        <h1 class="page-title">Nachrücken bestätigen</h1>
        <p class="page-sub"><?= e(DayQueries::personName($enrollment)) ?> (<?= e((string) $enrollment['class']) ?>) → <?= e($enrollment['name']) ?>, <?= e($enrollment['block_name']) ?></p>
    </div>
</div>

<div class="card" style="max-width:720px;">
    <div class="card-body">
        <form method="post" action="<?= e($ctx->url('/admin/einschreibungen/' . (int) $enrollment['id'] . '/nachruecken')) ?>">
            <?= $csrf->field() ?>
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <?= $view->renderPartial('pages/enrollments/_override', [
                'violations' => $violations,
                'overridable' => $overridable,
                'note' => '',
            ]) ?>
            <div class="cluster mt-2">
                <?php if ($overridable): ?>
                    <button class="btn btn-primary" type="submit">Trotzdem fest einschreiben</button>
                <?php endif; ?>
                <a class="btn btn-ghost" href="<?= e($back) ?>">Zurück</a>
            </div>
        </form>
    </div>
</div>
