<?php
/**
 * Sidebar-Navigation — rollen- und berechtigungsabhängig.
 * Reihenfolge und Sichtbarkeit sind anpassbar (nav_layout, siehe
 * Services\Customization); Berechtigungen filtern IMMER zusätzlich.
 */

use App\Core\Permissions as P;
use App\Services\Customization;

$user = $auth->user();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$appName = $ctx->settings->get('app_name') ?: 'Gartenarbeitstag';
$schoolName = $ctx->settings->get('school_name');
$logo = $ctx->settings->get('school_logo');
$day = $ctx->activeDay();

$navLink = static function (string $url, string $icon, string $label) use ($currentPath): string {
    $active = $currentPath === $url || ($url !== '/' && str_starts_with($currentPath, rtrim($url, '/') . '/'));

    return '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . e($url) . '">'
        . '<span class="nav-icon" aria-hidden="true">' . $icon . '</span>' . e($label) . '</a>';
};

$u = static fn (string $path): string => $ctx->url($path);
$navLayout = (new Customization($ctx->settings))->navLayout();
?>
<aside class="sidebar">
    <a class="sidebar-brand" href="<?= e($ctx->url('/')) ?>">
        <?php if (!empty($logo)): ?>
            <img class="brand-logo" src="<?= e($ctx->url('/medien/logos/' . $logo)) ?>" alt="">
        <?php else: ?>
            <span class="brand-mark">G</span>
        <?php endif; ?>
        <span><?= e($appName) ?></span>
    </a>
    <?php if (!empty($schoolName) || $day !== null): ?>
        <div class="sidebar-school">
            <?= e((string) $schoolName) ?>
            <?php if ($day !== null): ?>
                <?= !empty($schoolName) ? '· ' : '' ?><?= e($day['name']) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <nav class="sidebar-nav">
        <?php if ($user !== null): ?>
            <?php
            $role = $user['role'];
            $can = static fn (string $p): bool => $auth->can($p);
            $leadsStation = $auth->leadsAnyStation($ctx->activeDayId());

            // Alle Bereichs-Definitionen: key => [URL, Icon, Label, sichtbar?]
            $sections = [];

            if ($role === 'student') {
                $sections['student'] = ['Mein Aktionstag', [
                    'uebersicht' => [$u('/uebersicht'), '🏠', 'Übersicht', true],
                    'staende' => [$u('/staende'), '🌱', 'Stände', true],
                    'einschreibung' => [$u('/einschreibung'), '📝', 'Einschreibung', true],
                    'mein-plan' => [$u('/mein-plan'), '🗓️', 'Mein Plan', true],
                ]];
            }

            if ($role === 'teacher') {
                $sections['teacher'] = ['Lehrkraft', [
                    'meine-staende' => [$u('/meine-staende'), '🌱', 'Meine Stände', $leadsStation],
                    'klassen' => [$u('/klassen'), '🧑‍🏫', 'Klassen', true],
                    'staende' => [$u('/staende'), '📋', 'Alle Stände', true],
                ]];
            } elseif (in_array($role, ['admin', 'orga'], true) && $leadsStation) {
                $sections['teacher'] = ['Standleitung', [
                    'meine-staende' => [$u('/meine-staende'), '🌱', 'Meine Stände', true],
                ]];
            }

            if (in_array($role, ['admin', 'orga', 'teacher'], true)) {
                $sections['admin'] = ['Verwaltung', [
                    'dashboard' => [$u('/admin/dashboard'), '📊', 'Dashboard', $can(P::DASHBOARD_SEHEN)],
                    'aktionstage' => [$u('/admin/aktionstage'), '📅', 'Aktionstage', $can(P::AKTIONSTAGE_SEHEN)],
                    'staende' => [$u('/admin/staende'), '🌱', 'Stände', $can(P::STAENDE_SEHEN)],
                    'einschreibungen' => [$u('/admin/einschreibungen'), '📝', 'Einschreibungen', $can(P::EINSCHREIBUNGEN_SEHEN)],
                    'zuteilung' => [$u('/admin/zuteilung'), '🎯', 'Zuteilung', $can(P::ZUTEILUNG_AUSFUEHREN) && $day !== null && $day['mode'] === 'wishlist'],
                    'quote' => [$u('/admin/quote'), '🧮', 'Quote', $can(P::ZUTEILUNG_AUSFUEHREN) && $day !== null && $day['mode'] === 'quota'],
                    'anwesenheit' => [$u('/admin/anwesenheit'), '✅', 'Anwesenheit', $can(P::ANWESENHEIT_SEHEN)],
                    'kriterien' => [$u('/admin/kriterien'), '🚫', 'Ausschlusskriterien', $can(P::KRITERIEN_SEHEN)],
                    'benutzer' => [$u('/admin/benutzer'), '👥', 'Benutzer', $can(P::BENUTZER_SEHEN)],
                    'berechtigungen' => [$u('/admin/berechtigungen'), '🔑', 'Berechtigungen', $can(P::BERECHTIGUNGEN_SEHEN)],
                    'druck' => [$u('/admin/druck'), '🖨️', 'Listen & Export', $can(P::BERICHTE_SEHEN)],
                    'emails' => [$u('/admin/emails'), '📧', 'E-Mail', $can(P::EMAILS_SEHEN)],
                    'einstellungen' => [$u('/admin/einstellungen'), '⚙️', 'Einstellungen', $can(P::EINSTELLUNGEN_SEHEN)],
                    'darstellung' => [$u('/admin/darstellung'), '🎨', 'Darstellung', $auth->isAdmin()],
                    'audit-log' => [$u('/admin/audit-log'), '📜', 'Audit-Log', $can(P::AUDIT_LOGS_SEHEN)],
                ]];
            }

            foreach ($sections as $sectionKey => [$sectionTitle, $items]) {
                // Erst Berechtigungsfilter, dann die gespeicherte Anordnung
                $items = array_filter($items, static fn (array $item): bool => $item[3]);
                $items = Customization::applyLayout($items, $navLayout[$sectionKey] ?? [], ['darstellung']);
                if ($items === []) {
                    continue;
                }
                echo '<div class="nav-section">' . e($sectionTitle) . '</div>';
                foreach ($items as [$url, $icon, $label]) {
                    echo $navLink($url, $icon, $label);
                }
            }
            ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <?= e($appName) ?>
    </div>
</aside>
