<?php /** Login. Erwartet optional: $redirect. */ ?>
<h1 style="font-size:1.35rem;">Anmelden</h1>
<p class="text-soft text-sm">Melde dich mit deinem Schul-Konto an.</p>

<form method="post" action="">
    <?= $csrf->field() ?>
    <?php if (!empty($redirect)): ?>
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <?php endif; ?>

    <div class="field">
        <label for="username">Benutzername</label>
        <input class="input" type="text" id="username" name="username" required autofocus autocomplete="username">
    </div>
    <div class="field">
        <label for="password">Passwort</label>
        <input class="input" type="password" id="password" name="password" required autocomplete="current-password">
    </div>

    <button class="btn btn-primary btn-block btn-lg" type="submit">Anmelden</button>
</form>
