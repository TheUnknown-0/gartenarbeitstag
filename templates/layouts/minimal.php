<?php
/**
 * Minimal-Layout: Login, Fehlerseiten, Setup.
 * Erwartet: $content, optional $title, $wide (bool), $pageScripts.
 */

use App\Services\Customization;

$baseUrl = $ctx->config['app']['base_url'];
$appName = $ctx->settings->get('app_name') ?: 'Gartenarbeitstag';
$schoolName = $ctx->settings->get('school_name');
$logo = $ctx->settings->get('school_logo');
$loginImage = (new Customization($ctx->settings))->theme()['login_image'];
$wrapStyle = $loginImage !== null
    ? 'background:linear-gradient(rgb(17 24 39 / 0.45), rgb(17 24 39 / 0.45)), url(' .
      e($ctx->url('/medien/branding/' . $loginImage)) . ') center/cover no-repeat;'
    : '';
?><!DOCTYPE html>
<html lang="de" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrf->token()) ?>">
    <title><?= e(isset($title) ? $title . ' · ' . $appName : $appName) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css', $baseUrl)) ?>">
    <?= $view->renderPartial('partials/theme') ?>
    <script src="<?= e(asset('js/theme-init.js', $baseUrl)) ?>"></script>
</head>
<body>
<div class="minimal-wrap" style="<?= $wrapStyle ?>">
    <div class="minimal-card<?= !empty($wide) ? ' wide' : '' ?>">
        <div class="brand-row">
            <?php if (!empty($logo)): ?>
                <img class="brand-logo" style="width:38px;height:38px;"
                     src="<?= e($ctx->url('/medien/logos/' . $logo)) ?>" alt="">
            <?php else: ?>
                <span class="brand-mark" style="width:38px;height:38px;border-radius:8px;background:var(--primary);display:grid;place-items:center;color:var(--on-primary);font-family:var(--font-display);font-weight:800;">G</span>
            <?php endif; ?>
            <div>
                <strong style="font-family:var(--font-display);font-size:1.05rem;"><?= e($appName) ?></strong>
                <?php if (!empty($schoolName)): ?>
                    <div class="text-sm text-soft"><?= e($schoolName) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?= $view->renderPartial('partials/flash') ?>
        <?= $content ?>
    </div>
</div>
<script src="<?= e(asset('js/app.js', $baseUrl)) ?>"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= e(asset('js/' . $script, $baseUrl)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
