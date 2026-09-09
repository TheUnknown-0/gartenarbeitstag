<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\LimitCheck;

/**
 * Stände eines Aktionstags: Stammdaten, Zeitblöcke mit Kapazität, Limits,
 * Ausschlusskriterien und Standleitungen.
 *
 * Standard ist der aktive Aktionstag; über ?tag={id} lassen sich auch
 * Entwürfe vorbereiten.
 */
final class StationsController extends Controller
{
    public function index(array $params): string
    {
        $this->requirePermission(P::STAENDE_SEHEN);
        $day = $this->resolveDay();
        $dayId = (int) $day['id'];

        $q = trim((string) ($_GET['q'] ?? ''));
        $onlyActive = (string) ($_GET['aktiv'] ?? '') === '1';

        $sql = 'SELECT s.* FROM stations s WHERE s.garden_day_id = ?';
        $args = [$dayId];
        if ($q !== '') {
            $sql .= ' AND (s.name LIKE ? OR s.location LIKE ? OR s.description LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like);
        }
        if ($onlyActive) {
            $sql .= ' AND s.is_active = 1';
        }
        $sql .= ' ORDER BY s.sort_order, s.name';
        $stations = $this->ctx->db->fetchAll($sql, $args);

        $blocks = $this->timeBlocks($dayId);

        // Belegung je Stand und Block (fest + Warteliste) in einem Rutsch
        $occupancy = [];
        foreach ($this->ctx->db->fetchAll(
            "SELECT sb.station_id, sb.time_block_id, sb.capacity,
                    SUM(CASE WHEN e.status = 'assigned' THEN 1 ELSE 0 END) AS assigned,
                    SUM(CASE WHEN e.status = 'waitlist' THEN 1 ELSE 0 END) AS waitlist
             FROM station_blocks sb
             JOIN stations s ON s.id = sb.station_id
             LEFT JOIN enrollments e ON e.station_id = sb.station_id AND e.time_block_id = sb.time_block_id
             WHERE s.garden_day_id = ?
             GROUP BY sb.station_id, sb.time_block_id, sb.capacity",
            [$dayId],
        ) as $row) {
            $occupancy[(int) $row['station_id']][(int) $row['time_block_id']] = [
                'capacity' => (int) $row['capacity'],
                'assigned' => (int) $row['assigned'],
                'waitlist' => (int) $row['waitlist'],
            ];
        }

        $criteria = [];
        foreach ($this->ctx->db->fetchAll(
            'SELECT se.station_id, ec.name FROM station_exclusions se
             JOIN exclusion_criteria ec ON ec.id = se.criterion_id
             JOIN stations s ON s.id = se.station_id
             WHERE s.garden_day_id = ? ORDER BY ec.sort_order, ec.name',
            [$dayId],
        ) as $row) {
            $criteria[(int) $row['station_id']][] = $row['name'];
        }

        $leaders = [];
        foreach ($this->ctx->db->fetchAll(
            'SELECT sl.station_id, u.firstname, u.lastname, u.username FROM station_leaders sl
             JOIN users u ON u.id = sl.user_id
             JOIN stations s ON s.id = sl.station_id
             WHERE s.garden_day_id = ? ORDER BY u.lastname, u.firstname',
            [$dayId],
        ) as $row) {
            $name = trim($row['firstname'] . ' ' . $row['lastname']);
            $leaders[(int) $row['station_id']][] = $name !== '' ? $name : $row['username'];
        }

        return $this->render('pages/stations/index', [
            'title' => 'Stände',
            'day' => $day,
            'days' => $this->selectableDays(),
            'stations' => $stations,
            'blocks' => $blocks,
            'occupancy' => $occupancy,
            'criteria' => $criteria,
            'leaders' => $leaders,
            'q' => $q,
            'onlyActive' => $onlyActive,
            'tagQuery' => $this->tagQuery($day),
        ]);
    }

    public function create(array $params): string
    {
        $this->requirePermission(P::STAENDE_ERSTELLEN);
        $day = $this->resolveDay();

        return $this->renderForm($day, null);
    }

    public function store(array $params): string
    {
        $this->requirePermission(P::STAENDE_ERSTELLEN);
        $this->requireCsrf();
        $day = $this->resolveDay();

        $data = $this->validated($this->ctx->url('/admin/staende/neu' . $this->tagQuery($day)));

        $id = $this->ctx->db->transaction(function () use ($day, $data): int {
            $this->ctx->db->run(
                'INSERT INTO stations (garden_day_id, name, description, location, materials, max_per_class, max_per_grade, allowed_grades, allowed_classes, min_students, is_active, manual_only, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $day['id'], $data['name'], $data['description'], $data['location'], $data['materials'],
                    $data['max_per_class'], $data['max_per_grade'], $data['allowed_grades'], $data['allowed_classes'],
                    $data['min_students'], $data['is_active'], $data['manual_only'], $data['sort_order'],
                ],
            );
            $id = $this->ctx->db->lastInsertId();
            $this->saveRelations($id, (int) $day['id'], $data);

            return $id;
        });

        $this->ctx->audit->log('station.create', 'info', 'Stand angelegt: ' . $data['name'] . ' (#' . $id . ', ' . $day['name'] . ')');
        $this->flash('success', 'Stand „' . $data['name'] . '“ angelegt.');
        $this->redirect($this->ctx->url('/admin/staende' . $this->tagQuery($day)));
    }

    public function edit(array $params): string
    {
        $this->requirePermission(P::STAENDE_BEARBEITEN);
        $station = $this->findStation((int) ($params['id'] ?? 0));
        $day = $this->dayOf($station);

        return $this->renderForm($day, $station);
    }

    public function update(array $params): string
    {
        $this->requirePermission(P::STAENDE_BEARBEITEN);
        $this->requireCsrf();
        $station = $this->findStation((int) ($params['id'] ?? 0));
        $day = $this->dayOf($station);
        $stationId = (int) $station['id'];

        $data = $this->validated($this->ctx->url('/admin/staende/' . $stationId . $this->tagQuery($day)));

        // Blöcke mit Einschreibungen dürfen nicht abgewählt werden
        $blocked = $this->ctx->db->fetchAll(
            'SELECT tb.id, tb.name FROM time_blocks tb
             WHERE tb.garden_day_id = ? AND EXISTS (SELECT 1 FROM enrollments e WHERE e.station_id = ? AND e.time_block_id = tb.id)',
            [(int) $day['id'], $stationId],
        );
        foreach ($blocked as $block) {
            if (!array_key_exists((int) $block['id'], $data['blocks'])) {
                $this->flash('error', 'Der Zeitblock „' . $block['name'] . '“ hat bereits Einschreibungen und kann für diesen Stand nicht entfernt werden.');
                $this->redirect($this->ctx->url('/admin/staende/' . $stationId . $this->tagQuery($day)));
            }
        }

        $this->ctx->db->transaction(function () use ($stationId, $day, $data): void {
            $this->ctx->db->run(
                'UPDATE stations SET name = ?, description = ?, location = ?, materials = ?, max_per_class = ?, max_per_grade = ?,
                        allowed_grades = ?, allowed_classes = ?, min_students = ?, is_active = ?, manual_only = ?, sort_order = ?
                 WHERE id = ?',
                [
                    $data['name'], $data['description'], $data['location'], $data['materials'],
                    $data['max_per_class'], $data['max_per_grade'], $data['allowed_grades'], $data['allowed_classes'],
                    $data['min_students'], $data['is_active'], $data['manual_only'], $data['sort_order'], $stationId,
                ],
            );
            $this->saveRelations($stationId, (int) $day['id'], $data);
        });

        $this->ctx->audit->log('station.update', 'info', 'Stand bearbeitet: ' . $data['name'] . ' (#' . $stationId . ')');
        $this->flash('success', 'Stand „' . $data['name'] . '“ gespeichert.');
        $this->redirect($this->ctx->url('/admin/staende' . $this->tagQuery($day)));
    }

    public function toggleActive(array $params): string
    {
        $this->requirePermission(P::STAENDE_BEARBEITEN);
        $this->requireCsrf();
        $station = $this->findStation((int) ($params['id'] ?? 0));
        $day = $this->dayOf($station);

        $active = (int) $station['is_active'] === 1 ? 0 : 1;
        $this->ctx->db->run('UPDATE stations SET is_active = ? WHERE id = ?', [$active, (int) $station['id']]);
        $this->ctx->audit->log('station.toggle', 'info', 'Stand „' . $station['name'] . '“ (#' . $station['id'] . ') ' . ($active === 1 ? 'aktiviert' : 'deaktiviert'));
        $this->flash('success', 'Stand „' . $station['name'] . '“ ist jetzt ' . ($active === 1 ? 'aktiv' : 'deaktiviert') . '.');
        $this->redirect($this->ctx->url('/admin/staende' . $this->tagQuery($day)));
    }

    public function delete(array $params): string
    {
        $this->requirePermission(P::STAENDE_LOESCHEN);
        $this->requireCsrf();
        $station = $this->findStation((int) ($params['id'] ?? 0));
        $day = $this->dayOf($station);
        $back = $this->ctx->url('/admin/staende' . $this->tagQuery($day));

        $count = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM enrollments WHERE station_id = ?', [(int) $station['id']]);
        if ($count > 0) {
            $this->flash('error', 'Der Stand „' . $station['name'] . '“ hat ' . $count . ' Einschreibungen/Wünsche und kann nicht gelöscht werden. Deaktiviere ihn stattdessen.');
            $this->redirect($back);
        }

        $this->ctx->db->run('DELETE FROM stations WHERE id = ?', [(int) $station['id']]);
        $this->ctx->audit->log('station.delete', 'warning', 'Stand „' . $station['name'] . '“ (#' . $station['id'] . ') gelöscht');
        $this->flash('success', 'Stand „' . $station['name'] . '“ gelöscht.');
        $this->redirect($back);
    }

    public function participants(array $params): string
    {
        $this->requirePermission(P::STAENDE_SEHEN);
        $station = $this->findStation((int) ($params['id'] ?? 0));
        $day = $this->dayOf($station);
        $stationId = (int) $station['id'];

        $blocks = $this->ctx->db->fetchAll(
            'SELECT tb.*, sb.capacity FROM station_blocks sb
             JOIN time_blocks tb ON tb.id = sb.time_block_id
             WHERE sb.station_id = ? ORDER BY tb.sort_order, tb.start_time, tb.id',
            [$stationId],
        );

        $byBlock = [];
        foreach ($this->ctx->db->fetchAll(
            "SELECT e.time_block_id, e.status, e.source, e.priority, e.created_at, u.firstname, u.lastname, u.class, u.username
             FROM enrollments e JOIN users u ON u.id = e.user_id
             WHERE e.station_id = ?
             ORDER BY FIELD(e.status, 'assigned', 'waitlist', 'wish'), e.priority, u.lastname, u.firstname",
            [$stationId],
        ) as $row) {
            $byBlock[(int) $row['time_block_id']][] = $row;
        }

        return $this->render('pages/stations/participants', [
            'title' => 'Teilnehmende: ' . $station['name'],
            'day' => $day,
            'station' => $station,
            'blocks' => $blocks,
            'byBlock' => $byBlock,
            'tagQuery' => $this->tagQuery($day),
        ]);
    }

    // ---------- Hilfen ----------

    private function renderForm(array $day, ?array $station): string
    {
        $dayId = (int) $day['id'];
        $stationId = $station !== null ? (int) $station['id'] : 0;

        $stationBlocks = [];
        $blockEnrollments = [];
        $selectedCriteria = [];
        $selectedLeaders = [];
        if ($stationId > 0) {
            foreach ($this->ctx->db->fetchAll('SELECT time_block_id, capacity FROM station_blocks WHERE station_id = ?', [$stationId]) as $row) {
                $stationBlocks[(int) $row['time_block_id']] = (int) $row['capacity'];
            }
            foreach ($this->ctx->db->fetchAll('SELECT time_block_id, COUNT(*) AS n FROM enrollments WHERE station_id = ? GROUP BY time_block_id', [$stationId]) as $row) {
                $blockEnrollments[(int) $row['time_block_id']] = (int) $row['n'];
            }
            $selectedCriteria = array_map('intval', array_column($this->ctx->db->fetchAll('SELECT criterion_id FROM station_exclusions WHERE station_id = ?', [$stationId]), 'criterion_id'));
            $selectedLeaders = array_map('intval', array_column($this->ctx->db->fetchAll('SELECT user_id FROM station_leaders WHERE station_id = ?', [$stationId]), 'user_id'));
        }

        return $this->render('pages/stations/form', [
            'title' => $station === null ? 'Neuer Stand' : $station['name'],
            'day' => $day,
            'station' => $station,
            'blocks' => $this->timeBlocks($dayId),
            'stationBlocks' => $stationBlocks,
            'blockEnrollments' => $blockEnrollments,
            'criteria' => $this->ctx->db->fetchAll('SELECT * FROM exclusion_criteria WHERE is_active = 1 ORDER BY sort_order, name'),
            'selectedCriteria' => $selectedCriteria,
            'leaderCandidates' => $this->ctx->db->fetchAll(
                "SELECT id, username, firstname, lastname, role FROM users WHERE is_active = 1 AND role IN ('teacher', 'orga', 'admin') ORDER BY lastname, firstname, username",
            ),
            'selectedLeaders' => $selectedLeaders,
            'old' => $this->ctx->session->pullOldInput(),
            'tagQuery' => $this->tagQuery($day),
        ]);
    }

    /**
     * Validiert das Stand-Formular; bei Fehlern Flash + Redirect.
     *
     * @return array<string, mixed>
     */
    private function validated(string $back): array
    {
        $this->ctx->session->rememberInput($_POST);

        $name = trim((string) ($_POST['name'] ?? ''));
        $intOrNull = static function (string $key): ?int {
            $raw = trim((string) ($_POST[$key] ?? ''));

            return $raw === '' ? null : (int) $raw;
        };
        $maxPerClass = $intOrNull('max_per_class');
        $maxPerGrade = $intOrNull('max_per_grade');
        $minStudents = max(0, min(999, (int) ($_POST['min_students'] ?? 0)));
        $allowedGrades = LimitCheck::parseList((string) ($_POST['allowed_grades'] ?? ''));
        $allowedClasses = LimitCheck::parseList((string) ($_POST['allowed_classes'] ?? ''));

        $error = null;
        if ($name === '' || mb_strlen($name) > 150) {
            $error = 'Bitte einen Namen (max. 150 Zeichen) angeben.';
        } elseif (($maxPerClass !== null && $maxPerClass < 1) || ($maxPerGrade !== null && $maxPerGrade < 1)) {
            $error = 'Limits je Klasse/Stufe müssen mindestens 1 sein (oder leer für kein Limit).';
        } elseif (array_filter($allowedGrades, static fn (string $g): bool => !ctype_digit($g)) !== []) {
            $error = 'Erlaubte Jahrgangsstufen bitte als Zahlen angeben, z. B. „5, 6, 7“.';
        } elseif (mb_strlen(implode(',', $allowedClasses)) > 1000) {
            $error = 'Die Liste erlaubter Klassen ist zu lang.';
        }

        // Zeitblöcke: block[ID] = 1, capacity[ID] = n
        $blocks = [];
        foreach ((array) ($_POST['block'] ?? []) as $blockId => $flag) {
            if ((int) $flag !== 1) {
                continue;
            }
            $capacity = (int) (($_POST['capacity'] ?? [])[$blockId] ?? 10);
            $blocks[(int) $blockId] = max(0, min(999, $capacity));
        }
        if ($error === null && $blocks === []) {
            $error = 'Bitte mindestens einen Zeitblock anbieten.';
        }

        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $text = static function (string $key, int $max): ?string {
            $value = trim((string) ($_POST[$key] ?? ''));

            return $value === '' ? null : mb_substr($value, 0, $max);
        };

        return [
            'name' => $name,
            'description' => $text('description', 5000),
            'location' => $text('location', 150),
            'materials' => $text('materials', 5000),
            'max_per_class' => $maxPerClass,
            'max_per_grade' => $maxPerGrade,
            'allowed_grades' => $allowedGrades !== [] ? implode(',', $allowedGrades) : null,
            'allowed_classes' => $allowedClasses !== [] ? implode(',', $allowedClasses) : null,
            'min_students' => $minStudents,
            'is_active' => (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0,
            'manual_only' => (int) ($_POST['manual_only'] ?? 0) === 1 ? 1 : 0,
            'sort_order' => max(0, min(999, (int) ($_POST['sort_order'] ?? 0))),
            'blocks' => $blocks,
            'criteria' => array_values(array_unique(array_map('intval', (array) ($_POST['criteria'] ?? [])))),
            'leaders' => array_values(array_unique(array_map('intval', (array) ($_POST['leaders'] ?? [])))),
        ];
    }

    /**
     * Schreibt Zeitblöcke/Kapazitäten, Ausschlusskriterien und Standleitungen.
     * Läuft innerhalb der Transaktion des Aufrufers.
     *
     * @param array<string, mixed> $data
     */
    private function saveRelations(int $stationId, int $dayId, array $data): void
    {
        $validBlockIds = array_map('intval', array_column($this->timeBlocks($dayId), 'id'));
        $keep = [];
        foreach ($data['blocks'] as $blockId => $capacity) {
            if (!in_array($blockId, $validBlockIds, true)) {
                continue;
            }
            $this->ctx->db->run(
                'INSERT INTO station_blocks (station_id, time_block_id, capacity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE capacity = VALUES(capacity)',
                [$stationId, $blockId, $capacity],
            );
            $keep[] = $blockId;
        }
        if ($keep !== []) {
            $placeholders = implode(',', array_fill(0, count($keep), '?'));
            $this->ctx->db->run('DELETE FROM station_blocks WHERE station_id = ? AND time_block_id NOT IN (' . $placeholders . ')', [$stationId, ...$keep]);
        } else {
            $this->ctx->db->run('DELETE FROM station_blocks WHERE station_id = ?', [$stationId]);
        }

        $this->ctx->db->run('DELETE FROM station_exclusions WHERE station_id = ?', [$stationId]);
        foreach ($data['criteria'] as $criterionId) {
            $this->ctx->db->run(
                'INSERT IGNORE INTO station_exclusions (station_id, criterion_id) SELECT ?, id FROM exclusion_criteria WHERE id = ?',
                [$stationId, $criterionId],
            );
        }

        $this->ctx->db->run('DELETE FROM station_leaders WHERE station_id = ?', [$stationId]);
        foreach ($data['leaders'] as $userId) {
            $this->ctx->db->run(
                "INSERT IGNORE INTO station_leaders (station_id, user_id) SELECT ?, id FROM users WHERE id = ? AND role IN ('teacher', 'orga', 'admin')",
                [$stationId, $userId],
            );
        }
    }

    /** Aktionstag aus ?tag=… (nicht archiviert) oder der aktive. */
    private function resolveDay(): array
    {
        $tag = (int) ($_GET['tag'] ?? 0);
        if ($tag > 0) {
            $day = $this->ctx->db->fetchOne("SELECT * FROM garden_days WHERE id = ? AND status <> 'archived'", [$tag]);
            if ($day === null) {
                throw new HttpException(404, 'Aktionstag nicht gefunden oder archiviert.');
            }

            return $day;
        }

        return $this->ctx->requireActiveDay();
    }

    /** @return list<array<string, mixed>> */
    private function selectableDays(): array
    {
        return $this->ctx->db->fetchAll("SELECT id, name, status, event_date FROM garden_days WHERE status <> 'archived' ORDER BY FIELD(status, 'active', 'draft'), event_date DESC, id DESC");
    }

    /** Query-Anhang, damit ein gewählter Nicht-Standard-Tag erhalten bleibt. */
    private function tagQuery(array $day): string
    {
        $activeId = $this->ctx->activeDayId();

        return $activeId === (int) $day['id'] ? '' : '?tag=' . (int) $day['id'];
    }

    /** @return array<string, mixed> */
    private function findStation(int $id): array
    {
        $station = $id > 0 ? $this->ctx->db->fetchOne('SELECT * FROM stations WHERE id = ?', [$id]) : null;
        if ($station === null) {
            throw new HttpException(404, 'Stand nicht gefunden.');
        }

        return $station;
    }

    /** @return array<string, mixed> */
    private function dayOf(array $station): array
    {
        $day = $this->ctx->db->fetchOne('SELECT * FROM garden_days WHERE id = ?', [(int) $station['garden_day_id']]);
        if ($day === null) {
            throw new HttpException(404, 'Aktionstag nicht gefunden.');
        }

        return $day;
    }

    /** @return list<array<string, mixed>> */
    private function timeBlocks(int $dayId): array
    {
        return $this->ctx->db->fetchAll('SELECT * FROM time_blocks WHERE garden_day_id = ? ORDER BY sort_order, start_time, id', [$dayId]);
    }
}
