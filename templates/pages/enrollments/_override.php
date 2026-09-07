<?php
/**
 * Warnbox für Limit-Verstöße mit Übersteuern-Option.
 * Erwartet: $violations (list), $overridable (bool), optional $note (string).
 */
if ($violations === []) {
    return;
}
$hard = array_filter($violations, static fn (array $v): bool => $v['hard']);
?>
<div class="alert <?= $hard !== [] ? 'alert-error' : 'alert-warning' ?>" role="alert">
    <strong><?= $hard !== [] ? 'Diese Einschreibung ist nicht möglich:' : 'Folgende Limits würden überschritten:' ?></strong>
    <ul class="mt-0">
        <?php foreach ($violations as $v): ?>
            <li><?= e($v['message']) ?><?= $v['hard'] ? ' <span class="badge badge-danger">nicht übersteuerbar</span>' : '' ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($hard !== []): ?>
        <p class="mb-0 text-sm">Harte Sperren (Ausschlusskriterium, Zeitkonflikt, Duplikat, inaktiver Stand) können nicht übersteuert werden. Bitte einen anderen Stand oder Zeitblock wählen.</p>
    <?php elseif ($overridable): ?>
        <div class="checkbox-row mt-2">
            <input type="checkbox" id="override" name="override" value="1">
            <label for="override"><strong>Limits bewusst übersteuern</strong> — die Einschreibung wird trotzdem angelegt und im Audit-Log vermerkt.</label>
        </div>
        <div class="field mb-0">
            <label for="override_note">Begründung (Pflicht)</label>
            <input class="input" type="text" id="override_note" name="override_note" maxlength="255" value="<?= e($note ?? '') ?>" placeholder="z. B. Absprache mit Klassenleitung">
        </div>
    <?php else: ?>
        <p class="mb-0 text-sm">Du hast keine Berechtigung, Limits zu übersteuern. Bitte wende dich an die Orga-Leitung.</p>
    <?php endif; ?>
</div>
