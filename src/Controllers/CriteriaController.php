<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;

/**
 * Ausschlusskriterien (z. B. Allergien, Einschränkungen) und ihre Zuweisung
 * an Schüler:innen. Zuweisungen sind harte Sperren — ein Stand mit dem
 * Kriterium ist für die Person nie buchbar.
 */
final class CriteriaController extends Controller
{
    public function index(array $params): string
    {
        $this->requirePermission(P::KRITERIEN_SEHEN);

        $criteria = $this->ctx->db->fetchAll(
            'SELECT ec.*,
                    (SELECT COUNT(*) FROM user_exclusions ue WHERE ue.criterion_id = ec.id) AS user_count,
                    (SELECT COUNT(*) FROM station_exclusions se WHERE se.criterion_id = ec.id) AS station_count
             FROM exclusion_criteria ec ORDER BY ec.sort_order, ec.name',
        );

        return $this->render('pages/criteria/index', [
            'title' => 'Ausschlusskriterien',
            'criteria' => $criteria,
            'old' => $this->ctx->session->pullOldInput(),
        ]);
    }

    public function store(array $params): string
    {
        $this->requirePermission(P::KRITERIEN_BEARBEITEN);
        $this->requireCsrf();
        $data = $this->validated($this->ctx->url('/admin/kriterien'), null);

        $this->ctx->db->run(
            'INSERT INTO exclusion_criteria (name, description, is_active, sort_order) VALUES (?, ?, ?, ?)',
            [$data['name'], $data['description'], $data['is_active'], $data['sort_order']],
        );
        $id = $this->ctx->db->lastInsertId();
        $this->ctx->audit->log('criterion.create', 'info', 'Ausschlusskriterium angelegt: ' . $data['name'] . ' (#' . $id . ')');
        $this->flash('success', 'Kriterium „' . $data['name'] . '“ angelegt.');
        $this->redirect($this->ctx->url('/admin/kriterien'));
    }

    public function edit(array $params): string
    {
        $this->requirePermission(P::KRITERIEN_BEARBEITEN);
        $criterion = $this->findCriterion((int) ($params['id'] ?? 0));

        return $this->render('pages/criteria/form', [
            'title' => $criterion['name'],
            'criterion' => $criterion,
            'userCount' => (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM user_exclusions WHERE criterion_id = ?', [(int) $criterion['id']]),
            'stationCount' => (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM station_exclusions WHERE criterion_id = ?', [(int) $criterion['id']]),
            'old' => $this->ctx->session->pullOldInput(),
        ]);
    }

    public function update(array $params): string
    {
        $this->requirePermission(P::KRITERIEN_BEARBEITEN);
        $this->requireCsrf();
        $criterion = $this->findCriterion((int) ($params['id'] ?? 0));
        $id = (int) $criterion['id'];
        $data = $this->validated($this->ctx->url('/admin/kriterien/' . $id), $id);

        $this->ctx->db->run(
            'UPDATE exclusion_criteria SET name = ?, description = ?, is_active = ?, sort_order = ? WHERE id = ?',
            [$data['name'], $data['description'], $data['is_active'], $data['sort_order'], $id],
        );
        $this->ctx->audit->log('criterion.update', 'info', 'Ausschlusskriterium bearbeitet: ' . $data['name'] . ' (#' . $id . ')');
        $this->flash('success', 'Kriterium „' . $data['name'] . '“ gespeichert.');
        $this->redirect($this->ctx->url('/admin/kriterien'));
    }

    public function delete(array $params): string
    {
        $this->requirePermission(P::KRITERIEN_BEARBEITEN);
        $this->requireCsrf();
        $criterion = $this->findCriterion((int) ($params['id'] ?? 0));
        $id = (int) $criterion['id'];

        $users = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM user_exclusions WHERE criterion_id = ?', [$id]);
        $stations = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM station_exclusions WHERE criterion_id = ?', [$id]);
        if ($users > 0 || $stations > 0) {
            $this->flash('error', 'Das Kriterium „' . $criterion['name'] . '“ ist noch ' . $users . ' Schüler:innen und ' . $stations . ' Ständen zugeordnet und kann nicht gelöscht werden. Deaktiviere es stattdessen.');
            $this->redirect($this->ctx->url('/admin/kriterien'));
        }

        $this->ctx->db->run('DELETE FROM exclusion_criteria WHERE id = ?', [$id]);
        $this->ctx->audit->log('criterion.delete', 'warning', 'Ausschlusskriterium gelöscht: ' . $criterion['name'] . ' (#' . $id . ')');
        $this->flash('success', 'Kriterium „' . $criterion['name'] . '“ gelöscht.');
        $this->redirect($this->ctx->url('/admin/kriterien'));
    }

    public function people(array $params): string
    {
        $this->requirePermission(P::KRITERIEN_ZUWEISEN);
        $criterion = $this->findCriterion((int) ($params['id'] ?? 0));
        $id = (int) $criterion['id'];

        $people = $this->ctx->db->fetchAll(
            'SELECT ue.id AS exclusion_id, ue.note, ue.created_at, u.id AS user_id, u.username, u.firstname, u.lastname, u.class, u.is_active,
                    sb.username AS set_by_username, sb.firstname AS set_by_firstname, sb.lastname AS set_by_lastname
             FROM user_exclusions ue
             JOIN users u ON u.id = ue.user_id
             LEFT JOIN users sb ON sb.id = ue.set_by
             WHERE ue.criterion_id = ?
             ORDER BY u.class, u.lastname, u.firstname',
            [$id],
        );

        $q = trim((string) ($_GET['q'] ?? ''));
        $results = [];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $results = $this->ctx->db->fetchAll(
                "SELECT u.id, u.username, u.firstname, u.lastname, u.class
                 FROM users u
                 WHERE u.role = 'student' AND u.is_active = 1
                   AND (u.username LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR u.class LIKE ? OR CONCAT(u.firstname, ' ', u.lastname) LIKE ?)
                   AND NOT EXISTS (SELECT 1 FROM user_exclusions ue WHERE ue.user_id = u.id AND ue.criterion_id = ?)
                 ORDER BY u.class, u.lastname, u.firstname
                 LIMIT 50",
                [$like, $like, $like, $like, $like, $id],
            );
        }

        return $this->render('pages/criteria/people', [
            'title' => 'Personen: ' . $criterion['name'],
            'criterion' => $criterion,
            'people' => $people,
            'q' => $q,
            'results' => $results,
        ]);
    }

    public function assign(array $params): string
    {
        $this->requirePermission(P::KRITERIEN_ZUWEISEN);
        $this->requireCsrf();
        $criterion = $this->findCriterion((int) ($params['id'] ?? 0));
        $id = (int) $criterion['id'];
        $back = $this->ctx->url('/admin/kriterien/' . $id . '/personen');

        $userId = (int) ($_POST['user_id'] ?? 0);
        $student = $userId > 0
            ? $this->ctx->db->fetchOne("SELECT id, username, firstname, lastname, class FROM users WHERE id = ? AND role = 'student'", [$userId])
            : null;
        if ($student === null) {
            $this->flash('error', 'Schüler:in nicht gefunden.');
            $this->redirect($back);
        }

        $note = trim((string) ($_POST['note'] ?? ''));
        $note = $note === '' ? null : mb_substr($note, 0, 500);

        $exists = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM user_exclusions WHERE user_id = ? AND criterion_id = ?', [$userId, $id]);
        if ($exists > 0) {
            $this->flash('info', $this->studentName($student) . ' hat dieses Kriterium bereits.');
            $this->redirect($back);
        }

        $this->ctx->db->run(
            'INSERT INTO user_exclusions (user_id, criterion_id, note, set_by) VALUES (?, ?, ?, ?)',
            [$userId, $id, $note, $this->ctx->auth->id()],
        );
        $this->ctx->audit->log('criterion.assign', 'warning', 'Kriterium „' . $criterion['name'] . '“ zugewiesen an ' . $this->studentName($student) . ' (#' . $userId . ')' . ($note !== null ? ' — ' . $note : ''));
        $this->flash('success', 'Kriterium „' . $criterion['name'] . '“ für ' . $this->studentName($student) . ' gesetzt.');

        // Bestehende Einschreibungen an Ständen mit diesem Kriterium bleiben stehen — nur warnen
        $conflicts = (int) $this->ctx->db->fetchValue(
            "SELECT COUNT(*) FROM enrollments e
             JOIN station_exclusions se ON se.station_id = e.station_id AND se.criterion_id = ?
             JOIN stations s ON s.id = e.station_id
             JOIN garden_days gd ON gd.id = s.garden_day_id AND gd.status <> 'archived'
             WHERE e.user_id = ? AND e.status IN ('assigned', 'waitlist')",
            [$id, $userId],
        );
        if ($conflicts > 0) {
            $this->flash('warning', $this->studentName($student) . ' ist aktuell in ' . $conflicts . ' Zeitblock/Zeitblöcken an einem Stand mit diesem Kriterium eingeschrieben. Bitte die Einschreibung(en) prüfen und ggf. umbuchen.');
        }

        $this->redirect($back);
    }

    public function unassign(array $params): string
    {
        $this->requirePermission(P::KRITERIEN_ZUWEISEN);
        $this->requireCsrf();
        $criterion = $this->findCriterion((int) ($params['id'] ?? 0));
        $id = (int) $criterion['id'];
        $userId = (int) ($params['userId'] ?? 0);
        $back = $this->ctx->url('/admin/kriterien/' . $id . '/personen');

        $student = $userId > 0 ? $this->ctx->db->fetchOne('SELECT id, username, firstname, lastname, class FROM users WHERE id = ?', [$userId]) : null;
        if ($student === null) {
            $this->flash('error', 'Schüler:in nicht gefunden.');
            $this->redirect($back);
        }

        $this->ctx->db->run('DELETE FROM user_exclusions WHERE user_id = ? AND criterion_id = ?', [$userId, $id]);
        $this->ctx->audit->log('criterion.unassign', 'warning', 'Kriterium „' . $criterion['name'] . '“ entfernt bei ' . $this->studentName($student) . ' (#' . $userId . ')');
        $this->flash('success', 'Kriterium „' . $criterion['name'] . '“ bei ' . $this->studentName($student) . ' entfernt.');
        $this->redirect($back);
    }

    // ---------- Hilfen ----------

    /** @return array<string, mixed> */
    private function validated(string $back, ?int $ignoreId): array
    {
        $this->ctx->session->rememberInput($_POST);

        $name = trim((string) ($_POST['name'] ?? ''));
        $error = null;
        if ($name === '' || mb_strlen($name) > 150) {
            $error = 'Bitte einen Namen (max. 150 Zeichen) angeben.';
        } else {
            $dupe = (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM exclusion_criteria WHERE name = ? AND id <> ?',
                [$name, $ignoreId ?? 0],
            );
            if ($dupe > 0) {
                $error = 'Ein Kriterium mit dem Namen „' . $name . '“ existiert bereits.';
            }
        }
        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $description = trim((string) ($_POST['description'] ?? ''));

        return [
            'name' => $name,
            'description' => $description === '' ? null : mb_substr($description, 0, 500),
            // Das Inline-Formular zum Anlegen hat kein is_active-Feld: neue Kriterien sind aktiv
            'is_active' => $ignoreId === null || (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0,
            'sort_order' => max(0, min(999, (int) ($_POST['sort_order'] ?? 0))),
        ];
    }

    /** @return array<string, mixed> */
    private function findCriterion(int $id): array
    {
        $criterion = $id > 0 ? $this->ctx->db->fetchOne('SELECT * FROM exclusion_criteria WHERE id = ?', [$id]) : null;
        if ($criterion === null) {
            throw new HttpException(404, 'Kriterium nicht gefunden.');
        }

        return $criterion;
    }

    private function studentName(array $student): string
    {
        $name = trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''));
        $name = $name !== '' ? $name : (string) $student['username'];

        return !empty($student['class']) ? $name . ' (' . $student['class'] . ')' : $name;
    }
}
