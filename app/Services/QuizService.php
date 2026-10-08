<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Business logic for monitored quiz sessions.
 *
 * Lifecycle of a session: armed -> lobby -> running -> closed.
 * Students join with the room PIN (displayed in class) + their personal code,
 * which creates a monitored attempt identified by an HMAC-signed token.
 * That token is prefilled into the Google Form for later reconciliation.
 */
class QuizService
{
    use QuizTracking;
    use QuizBrowser;
    // Unambiguous alphabet for student codes (no 0/O, 1/I/L)
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const CODE_LENGTH = 5;
    private const ARCHIVE_VERSION = 1;
    private const MAX_ARCHIVE_ATTEMPTS = 1000;
    private const MAX_ARCHIVE_EVENTS = 20000;
    private const MAX_ARCHIVE_BYTES = 8388608;
    private const MAX_RESET_ARCHIVE_BYTES = 67108864;

    private PDO $db;
    private string $storagePath;
    private string $hmacSecret;
    /** @var array{id:int,role:string}|null */
    private ?array $adminContext = null;

    public function __construct(QuizDbService $dbService, array $config, string $storagePath)
    {
        $this->db = $dbService->pdo();
        $this->storagePath = $storagePath;
        $this->hmacSecret = $this->resolveHmacSecret($config['branding']['quiz_hmac_secret'] ?? null);
    }

    /** Bind the authenticated administrator to every teacher-side operation. */
    public function setAdminContext(array $admin): void
    {
        $id = (int)($admin['id'] ?? 0);
        $role = (string)($admin['role'] ?? '');
        if ($id < 1 || !in_array($role, ['super_admin', 'quiz_admin'], true)) {
            throw new RuntimeException('invalid_admin_context');
        }
        $this->adminContext = ['id' => $id, 'role' => $role];
    }

    private function currentAdminId(): int
    {
        if ($this->adminContext === null) {
            throw new RuntimeException('admin_auth_required');
        }
        return $this->adminContext['id'];
    }

    private function isSuperAdmin(): bool
    {
        return $this->adminContext !== null && $this->adminContext['role'] === 'super_admin';
    }

    // ------------------------------------------------------------------
    // Sessions (teacher side)
    // ------------------------------------------------------------------

    private function normalizeFormEditUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // Keep the teacher link restricted to the native Google Forms editor.
        if (!preg_match('~^https://docs\.google\.com/forms/d/([A-Za-z0-9_-]+)/edit(?:[?#][^\s]*)?$~D', $url, $match)) {
            throw new RuntimeException('invalid_form_edit_url');
        }
        return 'https://docs.google.com/forms/d/' . $match[1] . '/edit';
    }

    public function createSession(array $data, string $rosterText): array
    {
        $ownerId = $this->currentAdminId();
        $title = trim((string)($data['title'] ?? ''));
        $formUrl = trim((string)($data['google_form_url'] ?? ''));
        $editUrl = $this->normalizeFormEditUrl((string)($data['google_form_edit_url'] ?? ''));
        $entryId = $this->normalizeEntryId((string)($data['attempt_entry_id'] ?? ''));

        if ($title === '' || $formUrl === '' || $entryId === '') {
            throw new RuntimeException('missing_fields');
        }
        if (!preg_match('#^https://docs\.google\.com/forms/#', $formUrl)) {
            throw new RuntimeException('invalid_form_url');
        }

        $this->validateRosterEmails($rosterText);
        $slug = $this->generateSlug($title);

        $stmt = $this->db->prepare(
            'INSERT INTO quiz_sessions (owner_admin_id, slug, title, google_form_url, google_form_edit_url, attempt_entry_id, duration_minutes, max_incidents, min_away_seconds, require_fullscreen, reload_is_incident, state, tracking_generation)
             VALUES (:owner, :slug, :title, :url, :edit_url, :entry, :duration, :max_incidents, :min_away, :fullscreen, :reload, :state, :generation)'
        );
        $stmt->execute([
            'owner' => $ownerId,
            'slug' => $slug,
            'title' => $title,
            'url' => $formUrl,
            'edit_url' => $editUrl,
            'entry' => $entryId,
            'duration' => max(1, (int)($data['duration_minutes'] ?? 30)),
            'max_incidents' => max(1, (int)($data['max_incidents'] ?? 2)),
            'min_away' => max(1, (int)($data['min_away_seconds'] ?? 10)),
            'fullscreen' => isset($data['require_fullscreen']) ? 1 : 0,
            'reload' => isset($data['reload_is_incident']) ? 1 : 0,
            'state' => 'armed',
            'generation' => bin2hex(random_bytes(16)),
        ]);

        $sessionId = (int)$this->db->lastInsertId();
        $imported = $this->importRoster($sessionId, $rosterText);

        return ['id' => $sessionId, 'slug' => $slug, 'students' => $imported];
    }

    /**
     * Updates the rules of an existing session (allowed in any state, even
     * while running). Attempt statuses are re-derived under the new thresholds.
     */
    public function updateSession(int $sessionId, array $data): void
    {
        $this->currentAdminId();
        $session = $this->requireSession($sessionId);
        $data['google_form_edit_url'] = $data['google_form_edit_url'] ?? $session['google_form_edit_url'] ?? '';

        $title = trim((string)($data['title'] ?? ''));
        $formUrl = trim((string)($data['google_form_url'] ?? ''));
        $editUrl = $this->normalizeFormEditUrl((string)($data['google_form_edit_url'] ?? ''));
        $entryId = $this->normalizeEntryId((string)($data['attempt_entry_id'] ?? ''));

        if ($title === '' || $formUrl === '' || $entryId === '') {
            throw new RuntimeException('missing_fields');
        }
        if (!preg_match('#^https://docs\.google\.com/forms/#', $formUrl)) {
            throw new RuntimeException('invalid_form_url');
        }

        $this->db->beginTransaction();
        try {
            $this->lockTeacherSession($sessionId);
            $before = $this->sessionAuditFields($this->requireSession($sessionId));
            $stmt = $this->db->prepare(
                'UPDATE quiz_sessions SET title = :title, google_form_url = :url, google_form_edit_url = :edit_url, attempt_entry_id = :entry,
                        duration_minutes = :duration, max_incidents = :max_incidents, min_away_seconds = :min_away,
                        require_fullscreen = :fullscreen, reload_is_incident = :reload, settings_revision = settings_revision + 1
                 WHERE id = :id'
            );
            $stmt->execute([
                'title' => $title,
                'url' => $formUrl,
                'edit_url' => $editUrl,
                'entry' => $entryId,
                'duration' => max(1, (int)($data['duration_minutes'] ?? 30)),
                'max_incidents' => max(1, (int)($data['max_incidents'] ?? 2)),
                'min_away' => max(1, (int)($data['min_away_seconds'] ?? 10)),
                'fullscreen' => isset($data['require_fullscreen']) ? 1 : 0,
                'reload' => isset($data['reload_is_incident']) ? 1 : 0,
                'id' => $sessionId,
            ]);

            $session = $this->requireSession($sessionId);
            if (!$before['require_fullscreen'] && !empty($session['require_fullscreen'])) { $this->invalidateTrackingSession($sessionId, 'fullscreen_added'); }
            elseif ($before['require_fullscreen'] && empty($session['require_fullscreen']) && !empty($session['browser_enabled'])) { $this->invalidateBrowserSession($sessionId, 'fullscreen_changed'); }
            $this->reclassifyEvents($session);

            // Reclassification and every attempt count commit with the rules.
            $stmt = $this->db->prepare('SELECT id FROM quiz_attempts WHERE session_id = :sid');
            $stmt->execute(['sid' => $sessionId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $attemptId) {
                $this->recomputeAttemptStatus((int)$attemptId, $sessionId);
            }
            $this->appendAudit($sessionId, 'settings_updated', $before, $this->sessionAuditFields($session));
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $exception;
        }
    }

    /**
     * Roster format: one student per line. Email is required; name is optional
     * Accepted separators: ';', ',' or spaces.
     *   "Léa Dupont" — "Léa;Dupont" — "Léa Dupont lea@ecole.fr" — "lea@ecole.fr"
     * Returns the number of imported students.
     */
    public function importRoster(int $sessionId, string $rosterText): int
    {
        $this->requireSession($sessionId);
        $this->validateRosterEmails($rosterText);
        // CSV exports are often Windows-1252; the rest of the app is UTF-8
        $rosterText = (string)preg_replace('/^\xEF\xBB\xBF/', '', $rosterText);
        if (!mb_check_encoding($rosterText, 'UTF-8')) {
            $rosterText = mb_convert_encoding($rosterText, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($rosterText)) ?: [];
        $existingCodes = $this->existingCodes($sessionId);
        $count = 0;

        $stmt = $this->db->prepare(
            'INSERT INTO quiz_students (session_id, first_name, last_name, email, code) VALUES (:sid, :first, :last, :email, :code)'
        );

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (strpos($line, "\t") !== false) {
                $parts = array_map('trim', explode("\t", $line));
            } elseif (strpos($line, ';') !== false) {
                $parts = array_map('trim', explode(';', $line));
            } elseif (strpos($line, ',') !== false) {
                $parts = array_map('trim', explode(',', $line));
            } else {
                $parts = preg_split('/\s+/', $line) ?: [$line];
            }

            if ($this->isRosterHeader($parts)) {
                continue; // CSV header line ("Prénom;Nom;Email"), not a student
            }

            // The email can be anywhere on the line; everything else is the name
            $email = null;
            $nameParts = [];
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                if ($email === null && filter_var($part, FILTER_VALIDATE_EMAIL) !== false) {
                    $email = $part;
                } else {
                    $nameParts[] = $part;
                }
            }

            $first = $nameParts[0] ?? '';
            $last = implode(' ', array_slice($nameParts, 1));
            if ($first === '' && $email !== null) {
                // Email-only line: use the mailbox name so the student stays identifiable
                $first = (string)strstr($email, '@', true);
            }
            if ($first === '') {
                continue;
            }

            $code = $this->generateStudentCode($existingCodes);
            $existingCodes[$code] = true;
            $stmt->execute(['sid' => $sessionId, 'first' => $first, 'last' => $last, 'email' => $email, 'code' => $code]);
            $count++;
        }

        return $count;
    }

    public function listSessions(bool $includeAll = false): array
    {
        $adminId = $this->currentAdminId();
        $sql = 'SELECT s.*, u.display_name AS owner_name, u.email AS owner_email,
                    (SELECT COUNT(*) FROM quiz_students st WHERE st.session_id = s.id) AS student_count,
                    (SELECT COUNT(*) FROM quiz_attempts a WHERE a.session_id = s.id) AS attempt_count
             FROM quiz_sessions s JOIN admin_users u ON u.id = s.owner_admin_id';
        if (!$includeAll || !$this->isSuperAdmin()) {
            $sql .= ' WHERE s.owner_admin_id = :owner';
        }
        $sql .= ' ORDER BY s.id DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute((!$includeAll || !$this->isSuperAdmin()) ? ['owner' => $adminId] : []);
        return $stmt->fetchAll();
    }

    public function getSession(int $id): ?array
    {
        $sql = 'SELECT * FROM quiz_sessions WHERE id = :id';
        $params = ['id' => $id];
        if ($this->adminContext !== null && !$this->isSuperAdmin()) {
            $sql .= ' AND owner_admin_id = :owner';
            $params['owner'] = $this->adminContext['id'];
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Transfer one session; the caller must be an authenticated super-admin. */
    public function transferSession(int $sessionId, int $newOwnerId): void
    {
        $this->currentAdminId();
        if (!$this->isSuperAdmin()) {
            throw new RuntimeException('resource_not_found');
        }
        $this->requireSession($sessionId);
        $stmt = $this->db->prepare("SELECT id FROM admin_users WHERE id = :id AND status = 'active'");
        $stmt->execute(['id' => $newOwnerId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('invalid_owner');
        }
        $stmt = $this->db->prepare('UPDATE quiz_sessions SET owner_admin_id = :owner WHERE id = :id');
        $stmt->execute(['owner' => $newOwnerId, 'id' => $sessionId]);
    }

    public function findSessionByPin(string $pin): ?array
    {
        $pin = preg_replace('/\D/', '', $pin) ?? '';
        if ($pin === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM quiz_sessions WHERE access_pin = :pin AND state IN ('lobby', 'running') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['pin' => $pin]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Opens the virtual room: generates the PIN displayed in class. */
    public function openLobby(int $sessionId): string
    {
        return $this->sessionMutation($sessionId, 'lobby_opened', function (array $session) use ($sessionId): string {
            if (!in_array($session['state'], ['armed', 'closed'], true)) { throw new RuntimeException('invalid_state'); }
            do { $pin = (string)random_int(100000, 999999); } while ($this->findSessionByPin($pin) !== null);
            $stmt = $this->db->prepare("UPDATE quiz_sessions SET state = 'lobby', access_pin = :pin, pin_generated_at = :now, started_at = NULL, closed_at = NULL WHERE id = :id");
            $stmt->execute(['pin' => $pin, 'now' => $this->now(), 'id' => $sessionId]);
            return $pin;
        });
    }

    /** Ordinary launch changes the timer/nonce; current observations and counts stay. */
    public function launch(int $sessionId): void
    {
        $this->sessionMutation($sessionId, 'launched', function (array $session) use ($sessionId): void {
            if (!in_array($session['state'], ['lobby', 'running'], true)) { throw new RuntimeException('invalid_state'); }
            $this->expireTechnicalOverrides($sessionId, 'new_launch');
            $this->invalidateTrackingSession($sessionId, 'new_launch');
            $stmt = $this->db->prepare("UPDATE quiz_sessions SET state = 'running', started_at = :now, tracking_generation = :generation WHERE id = :id");
            $stmt->execute(['now' => $this->now(), 'generation' => bin2hex(random_bytes(16)), 'id' => $sessionId]);
        });
    }

    public function stop(int $sessionId): void
    {
        $this->sessionMutation($sessionId, 'stopped', function (array $session) use ($sessionId): void {
            if ($session['state'] !== 'running') { throw new RuntimeException('invalid_state'); }
            $this->invalidateTrackingSession($sessionId, 'stopped');
            $stmt = $this->db->prepare("UPDATE quiz_sessions SET state = 'lobby', started_at = NULL WHERE id = :id");
            $stmt->execute(['id' => $sessionId]);
        });
    }

    /** Immutable snapshots precede the current-history reset in the same transaction. */
    public function resetAndRelaunch(int $sessionId): void
    {
        $this->sessionMutation($sessionId, 'reset', function (array $session) use ($sessionId): int {
            if (!in_array($session['state'], ['lobby', 'running'], true)) { throw new RuntimeException('invalid_state'); }
            $archived = $this->archiveAttempts($session);
            $this->expireTechnicalOverrides($sessionId, 'reset');
            $this->invalidateTrackingSession($sessionId, 'reset');
            $stmt = $this->db->prepare('DELETE FROM quiz_events WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE session_id = :sid)');
            $stmt->execute(['sid' => $sessionId]);
            $stmt = $this->db->prepare("UPDATE quiz_attempts SET incident_count = 0, status = 'started', finished_at = NULL WHERE session_id = :sid");
            $stmt->execute(['sid' => $sessionId]);
            $stmt = $this->db->prepare("UPDATE quiz_sessions SET state = 'running', started_at = :now, tracking_generation = :generation, history_revision = history_revision + 1 WHERE id = :id");
            $stmt->execute(['now' => $this->now(), 'generation' => bin2hex(random_bytes(16)), 'id' => $sessionId]);
            return $archived;
        });
    }

    public function close(int $sessionId): void
    {
        $this->sessionMutation($sessionId, 'closed', function (array $session) use ($sessionId): void {
            $this->expireTechnicalOverrides($sessionId, 'closed');
            $this->invalidateTrackingSession($sessionId, 'closed');
            $stmt = $this->db->prepare("UPDATE quiz_sessions SET state = 'closed', closed_at = :now, access_pin = NULL WHERE id = :id");
            $stmt->execute(['now' => $this->now(), 'id' => $sessionId]);
        });
    }

    /**
     * Bulk edit: $rows is keyed by student id, each value holding
     * first_name / last_name / email. Invalid rows are skipped and counted.
     * Returns ['updated' => int, 'errors' => int].
     */
    public function updateStudents(int $sessionId, array $rows): array
    {
        $this->requireSession($sessionId);
        $updated = 0;
        $errors = 0;
        foreach ($rows as $studentId => $data) {
            if (!is_array($data)) {
                continue;
            }
            try {
                $this->updateStudent((int)$studentId, $sessionId, $data);
                $updated++;
            } catch (RuntimeException $e) {
                $errors++;
            }
        }
        return ['updated' => $updated, 'errors' => $errors];
    }

    /** Edits a student's identity (first/last name, email). The code never changes. */
    public function updateStudent(int $studentId, int $sessionId, array $data): void
    {
        $this->requireSession($sessionId);
        $first = trim((string)($data['first_name'] ?? ''));
        $last = trim((string)($data['last_name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));

        if ($first === '') {
            throw new RuntimeException('missing_fields');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('invalid_email');
        }

        $stmt = $this->db->prepare(
            'UPDATE quiz_students SET first_name = :first, last_name = :last, email = :email
             WHERE id = :id AND session_id = :sid'
        );
        $stmt->execute([
            'first' => $first,
            'last' => $last,
            'email' => $email === '' ? null : $email,
            'id' => $studentId,
            'sid' => $sessionId,
        ]);
    }

    /**
     * Removes a student from a session, along with their attempt and every
     * recorded event (no FK cascade in this SQLite setup, so we clean up by
     * hand). Returns the deleted student's display name, or null if unknown.
     */
    public function deleteStudent(int $studentId, int $sessionId): ?string
    {
        $this->requireSession($sessionId);
        $stmt = $this->db->prepare('SELECT first_name, last_name FROM quiz_students WHERE id = :id AND session_id = :sid');
        $stmt->execute(['id' => $studentId, 'sid' => $sessionId]);
        $student = $stmt->fetch();
        if ($student === false) {
            return null;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM quiz_events WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE session_id = :sid AND student_id = :stid)'
        );
        $stmt->execute(['sid' => $sessionId, 'stid' => $studentId]);

        $stmt = $this->db->prepare('DELETE FROM quiz_attempts WHERE session_id = :sid AND student_id = :stid');
        $stmt->execute(['sid' => $sessionId, 'stid' => $studentId]);

        $stmt = $this->db->prepare('DELETE FROM quiz_students WHERE id = :id AND session_id = :sid');
        $stmt->execute(['id' => $studentId, 'sid' => $sessionId]);

        return trim($student['first_name'] . ' ' . $student['last_name']);
    }

    public function listStudents(int $sessionId): array
    {
        $this->requireSession($sessionId);
        $stmt = $this->db->prepare('SELECT * FROM quiz_students WHERE session_id = :sid ORDER BY last_name, first_name');
        $stmt->execute(['sid' => $sessionId]);
        return $stmt->fetchAll();
    }

    /**
     * Emails each student their personal code. Templates use the placeholders
     * {name}, {title}, {code} and {url}. When $smtp is configured the mail is
     * sent through that authenticated SMTP account, otherwise PHP mail() is used.
     * Returns ['sent', 'no_email', 'failed'].
     */
    public function emailCodes(int $sessionId, string $subjectTemplate, string $bodyTemplate, string $fromEmail, string $fromName, string $joinUrl, ?array $smtp = null): array
    {
        $session = $this->requireSession($sessionId);
        $students = $this->listStudents($sessionId);

        $sent = 0;
        $noEmail = 0;
        $failed = 0;

        $mailer = SmtpMailer::isConfigured($smtp) ? new SmtpMailer($smtp) : null;
        $mark = $this->db->prepare('UPDATE quiz_students SET code_email_sent_at = :now WHERE id = :id');

        foreach ($students as $student) {
            $email = (string)($student['email'] ?? '');
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $noEmail++;
                continue;
            }

            $vars = [
                '{name}' => trim($student['first_name'] . ' ' . $student['last_name']),
                '{title}' => (string)$session['title'],
                '{code}' => (string)$student['code'],
                '{url}' => $joinUrl,
            ];
            $subject = strtr($subjectTemplate, $vars);
            $body = strtr($bodyTemplate, $vars);

            if ($mailer !== null) {
                $ok = $mailer->send($fromEmail, $fromName, $email, $subject, $body);
            } else {
                $fromHeader = $fromName !== ''
                    ? mb_encode_mimeheader($fromName, 'UTF-8', 'B') . ' <' . $fromEmail . '>'
                    : $fromEmail;
                $headers = 'From: ' . $fromHeader . "\r\n"
                    . "MIME-Version: 1.0\r\n"
                    . "Content-Type: text/plain; charset=UTF-8\r\n"
                    . "Content-Transfer-Encoding: 8bit\r\n";
                $ok = @mail($email, mb_encode_mimeheader($subject, 'UTF-8', 'B'), $body, $headers);
            }

            if ($ok) {
                $mark->execute(['now' => $this->now(), 'id' => (int)$student['id']]);
                $sent++;
            } else {
                $failed++;
            }
        }

        if ($mailer !== null) {
            $mailer->close();
        }

        return ['sent' => $sent, 'no_email' => $noEmail, 'failed' => $failed];
    }

    public function listAttempts(int $sessionId): array
    {
        $this->requireSession($sessionId);
        $stmt = $this->db->prepare(
            'SELECT a.*, st.first_name, st.last_name, st.code, st.email,
                    (SELECT COUNT(*) FROM quiz_events e WHERE e.attempt_id = a.id) AS event_count
             FROM quiz_attempts a
             JOIN quiz_students st ON st.id = a.student_id
             WHERE a.session_id = :sid
             ORDER BY st.last_name, st.first_name'
        );
        $stmt->execute(['sid' => $sessionId]);
        return $stmt->fetchAll();
    }

    public function listEvents(int $sessionId): array
    {
        $this->requireSession($sessionId);
        $stmt = $this->db->prepare(
            'SELECT e.*, st.first_name, st.last_name
             FROM quiz_events e
             JOIN quiz_attempts a ON a.id = e.attempt_id
             JOIN quiz_students st ON st.id = a.student_id
             WHERE a.session_id = :sid
             ORDER BY e.id DESC'
        );
        $stmt->execute(['sid' => $sessionId]);
        return $stmt->fetchAll();
    }

    public function listEventsForAttempt(int $attemptId): array
    {
        if ($this->getAttempt($attemptId) === null) {
            throw new RuntimeException('resource_not_found');
        }
        $stmt = $this->db->prepare('SELECT * FROM quiz_events WHERE attempt_id = :aid ORDER BY id DESC');
        $stmt->execute(['aid' => $attemptId]);
        return $stmt->fetchAll();
    }

    /** New events for the live feed: everything after $afterId, oldest first. */
    public function listEventsSince(int $sessionId, int $afterId, int $limit = 100, bool $projected = false): array
    {
        $this->requireSession($sessionId);
        $stmt = $this->db->prepare(
            'SELECT e.*, a.student_id, st.first_name, st.last_name
             FROM quiz_events e
             JOIN quiz_attempts a ON a.id = e.attempt_id
             JOIN quiz_students st ON st.id = a.student_id
             WHERE a.session_id = :sid AND e.id > :after ' . ($projected ? $this->projectedEventFilter() : '') . '
             ORDER BY e.id ASC LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['sid' => $sessionId, 'after' => $afterId]);
        return $stmt->fetchAll();
    }

    /** Latest bounded feed snapshot, oldest first for the same client renderer. */
    public function listRecentEvents(int $sessionId, int $limit = 100, bool $projected = false): array
    {
        $this->requireSession($sessionId);
        $stmt = $this->db->prepare(
            'SELECT e.*, a.student_id, st.first_name, st.last_name
             FROM quiz_events e
             JOIN quiz_attempts a ON a.id = e.attempt_id
             JOIN quiz_students st ON st.id = a.student_id
             WHERE a.session_id = :sid ' . ($projected ? $this->projectedEventFilter() : '') . ' ORDER BY e.id DESC LIMIT ' . max(1, min($limit, 100))
        );
        $stmt->execute(['sid' => $sessionId]);
        return array_reverse($stmt->fetchAll());
    }

    private function projectedEventFilter(): string
    {
        return " AND e.event_type IN ('hidden', 'blur', 'fullscreen_exit', 'leave', 'reload', 'devtools', 'copy', 'paste', 'print') ";
    }

    /**
     * Teacher arbitration: excuse (or reinstate) an incident, then recompute
     * the attempt's incident count and status. Returns the attempt id, or
     * null if the event does not exist or is not an incident.
     */
    public function setEventExcused(int $eventId, bool $excused): ?int
    {
        $this->currentAdminId();
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('UPDATE quiz_events SET excused = excused WHERE id = :id');
            $lock->execute(['id' => $eventId]);
            $stmt = $this->db->prepare(
                'SELECT e.id, e.attempt_id, e.is_incident, e.absence_uid, a.session_id
                 FROM quiz_events e JOIN quiz_attempts a ON a.id = e.attempt_id
                 WHERE e.id = :id'
            );
            $stmt->execute(['id' => $eventId]);
            $event = $stmt->fetch();
            if ($event === false || (int)$event['is_incident'] !== 1) {
                $this->db->commit();
                return null;
            }
            $this->requireSession((int)$event['session_id']);
            $before = $this->attemptAuditFields((int)$event['attempt_id']);
            $before['event_id'] = $eventId;
            $before['absence_uid'] = $event['absence_uid'];

            $upd = $this->db->prepare(empty($event['absence_uid'])
                ? 'UPDATE quiz_events SET excused = :ex WHERE id = :id'
                : 'UPDATE quiz_events SET excused = :ex WHERE attempt_id = :aid AND absence_uid = :absence');
            $upd->execute(empty($event['absence_uid'])
                ? ['ex' => $excused ? 1 : 0, 'id' => (int)$event['id']]
                : ['ex' => $excused ? 1 : 0, 'aid' => (int)$event['attempt_id'], 'absence' => $event['absence_uid']]);

            $this->recomputeAttemptStatus((int)$event['attempt_id'], (int)$event['session_id']);
            $after = $this->attemptAuditFields((int)$event['attempt_id']);
            $after['event_id'] = $eventId;
            $after['absence_uid'] = $event['absence_uid'];
            $this->appendAudit((int)$event['session_id'], $excused ? 'episode_excused' : 'episode_reinstated', $before, $after);
            $this->db->commit();
            return (int)$event['attempt_id'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /**
     * Teacher arbitration, whole-student version: excuses every incident of
     * one attempt in a single click and resets its status to 'started'.
     * Returns the session id, or null if the attempt does not exist.
     */
    public function excuseAttempt(int $attemptId): ?int
    {
        $this->currentAdminId();
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('UPDATE quiz_attempts SET incident_count = incident_count WHERE id = :id');
            $lock->execute(['id' => $attemptId]);
            $attempt = $this->getAttempt($attemptId);
            if ($attempt === null) {
                $this->db->commit();
                return null;
            }

            $before = $this->attemptAuditFields($attemptId);
            $stmt = $this->db->prepare('UPDATE quiz_events SET excused = 1 WHERE attempt_id = :aid AND (is_incident = 1 OR absence_uid IN (SELECT absence_uid FROM quiz_events WHERE attempt_id = :aid AND is_incident = 1 AND absence_uid IS NOT NULL))');
            $stmt->execute(['aid' => $attemptId]);

            $this->recomputeAttemptStatus($attemptId, (int)$attempt['session_id']);
            $this->appendAudit((int)$attempt['session_id'], 'attempt_excused', $before, $this->attemptAuditFields($attemptId));
            $this->db->commit();
            return (int)$attempt['session_id'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /** Recounts non-excused incidents and re-derives the attempt status. */
    private function recomputeAttemptStatus(int $attemptId, int $sessionId): array
    {
        $session = $this->requireSession($sessionId);
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM quiz_events WHERE attempt_id = :aid AND is_incident = 1 AND excused = 0'
        );
        $stmt->execute(['aid' => $attemptId]);
        $count = (int)$stmt->fetchColumn();

        $status = $count >= (int)$session['max_incidents'] ? 'invalid' : ($count > 0 ? 'suspect' : 'started');
        $upd = $this->db->prepare('UPDATE quiz_attempts SET incident_count = :count, status = :status WHERE id = :id');
        $upd->execute(['count' => $count, 'status' => $status, 'id' => $attemptId]);
        return ['incident_count' => $count, 'status' => $status];
    }

    // ------------------------------------------------------------------
    // Attempts (student side)
    // ------------------------------------------------------------------

    /**
     * Validates the personal code for a session and creates (or resumes) the attempt.
     * Returns the attempt row, with 'resumed' => bool.
     */
    public function joinAttempt(array $session, string $code): array
    {
        $code = strtoupper(trim($code));
        $stmt = $this->db->prepare('SELECT * FROM quiz_students WHERE session_id = :sid AND code = :code');
        $stmt->execute(['sid' => (int)$session['id'], 'code' => $code]);
        $student = $stmt->fetch();
        if ($student === false) {
            throw new RuntimeException('invalid_code');
        }

        $stmt = $this->db->prepare('SELECT * FROM quiz_attempts WHERE session_id = :sid AND student_id = :stid');
        $stmt->execute(['sid' => (int)$session['id'], 'stid' => (int)$student['id']]);
        $attempt = $stmt->fetch();

        if ($attempt !== false) {
            // Same student coming back (reload, crash, second device): resume, but log it.
            $this->insertEvent((int)$attempt['id'], 'rejoin', 0, false);
            $attempt['resumed'] = true;
            $attempt['first_name'] = $student['first_name'];
            $attempt['last_name'] = $student['last_name'];
            return $attempt;
        }

        $token = $this->generateAttemptToken();
        $stmt = $this->db->prepare(
            'INSERT INTO quiz_attempts (session_id, student_id, public_token, started_at, last_heartbeat_at, ip_hash, user_agent)
             VALUES (:sid, :stid, :token, :now, :now2, :ip, :ua)'
        );
        $stmt->execute([
            'sid' => (int)$session['id'],
            'stid' => (int)$student['id'],
            'token' => $token,
            'now' => $this->now(),
            'now2' => $this->now(),
            'ip' => $this->anonymizedIpHash(),
            'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        $attemptId = (int)$this->db->lastInsertId();
        $this->insertEvent($attemptId, 'join', 0, false);

        return [
            'id' => $attemptId,
            'session_id' => (int)$session['id'],
            'student_id' => (int)$student['id'],
            'public_token' => $token,
            'incident_count' => 0,
            'status' => 'started',
            'resumed' => false,
            'first_name' => $student['first_name'],
            'last_name' => $student['last_name'],
        ];
    }

    public function getAttempt(int $attemptId): ?array
    {
        $sql = 'SELECT a.*, st.first_name, st.last_name, st.code, st.email
             FROM quiz_attempts a
             JOIN quiz_students st ON st.id = a.student_id
             JOIN quiz_sessions s ON s.id = a.session_id
             WHERE a.id = :id';
        $params = ['id' => $attemptId];
        if ($this->adminContext !== null && !$this->isSuperAdmin()) {
            $sql .= ' AND s.owner_admin_id = :owner';
            $params['owner'] = $this->adminContext['id'];
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function recordHeartbeat(int $attemptId): void
    {
        $stmt = $this->db->prepare('UPDATE quiz_attempts SET last_heartbeat_at = :now WHERE id = :id');
        $stmt->execute(['now' => $this->now(), 'id' => $attemptId]);
    }

    /** Teacher-only policy details. The independent row survives roster deletion. */
    public function getStudentAccess(int $sessionId, int $studentId): array
    {
        $this->currentAdminId();
        $session = $this->requireSession($sessionId);
        $stmt = $this->db->prepare('SELECT first_name, last_name FROM quiz_students WHERE id = :student AND session_id = :sid');
        $stmt->execute(['student' => $studentId, 'sid' => $sessionId]);
        $student = $stmt->fetch();
        if ($student === false) { throw new RuntimeException('resource_not_found'); }
        return $this->accessContext($sessionId, $studentId, $student) + ['technical_override' => $this->activeTechnicalOverride($sessionId, $studentId, (string)$session['tracking_generation'])];
    }

    public function setManualAccess(int $sessionId, int $studentId, bool $blocked, string $reason): void
    {
        $this->currentAdminId();
        $reason = $this->normalizeAccessReason($reason);
        $this->db->beginTransaction();
        try {
            $this->lockTeacherSession($sessionId);
            $before = $this->getStudentAccess($sessionId, $studentId);
            if ($before['manual_blocked'] === $blocked) { throw new RuntimeException('access_unchanged'); }
            $actor = $this->auditActor();
            $stmt = $this->db->prepare('INSERT INTO quiz_student_access(session_id, student_id, manual_blocked, reason, actor_admin_id, actor_name, changed_at, first_name, last_name) VALUES(:sid, :student, :blocked, :reason, :actor, :name, :now, :first, :last) ON CONFLICT(session_id, student_id) DO UPDATE SET manual_blocked = excluded.manual_blocked, reason = excluded.reason, actor_admin_id = excluded.actor_admin_id, actor_name = excluded.actor_name, changed_at = excluded.changed_at, first_name = excluded.first_name, last_name = excluded.last_name');
            $stmt->execute(['sid' => $sessionId, 'student' => $studentId, 'blocked' => $blocked ? 1 : 0, 'reason' => $reason,
                'actor' => $actor['id'], 'name' => $actor['name'], 'now' => $this->now(), 'first' => $before['first_name'], 'last' => $before['last_name']]);
            $this->appendAudit($sessionId, $blocked ? 'access_blocked' : 'access_lifted', $before, $this->getStudentAccess($sessionId, $studentId));
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /** Public decision exposes no cause, policy row, actor or technical detail. */
    public function studentAccessAllowed(int $sessionId, int $studentId): bool
    {
        $owns = !$this->db->inTransaction();
        if($owns){$this->db->beginTransaction();$this->trackingDecisionTime=null;}
        try { if($owns){$stmt=$this->db->prepare('UPDATE quiz_sessions SET settings_revision=settings_revision WHERE id=:id');$stmt->execute(['id'=>$sessionId]);}
            $result=$this->studentAccessAllowedUnlocked($sessionId,$studentId);if($owns){$this->db->commit();}return $result;
        }catch(Throwable $e){if($owns&&$this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }

    private function studentAccessAllowedUnlocked(int $sessionId, int $studentId): bool
    {
        if (!$this->projectedAccessAllowed($sessionId, $studentId)) { return QuizAccessEvaluator::evaluate(true, []); }
        $session = $this->requireSession($sessionId);
        $generation = (string)$session['tracking_generation'];
        $override = $this->activeTechnicalOverride($sessionId, $studentId, $generation);
        $context = $this->technicalAccessContext($sessionId, $studentId, $generation);
        return QuizAccessEvaluator::evaluate(false, $this->currentTechnicalCauses($context), $override['scopes'] ?? []);
    }

    /** Board is a generic projection, not an aggregate of different browser contexts. */
    public function projectedAccessAllowed(int $sessionId, int $studentId): bool
    {
        $stmt = $this->db->prepare('SELECT COALESCE(ac.manual_blocked, 0) FROM quiz_students st LEFT JOIN quiz_student_access ac ON ac.session_id = st.session_id AND ac.student_id = st.id WHERE st.session_id = :sid AND st.id = :student');
        $stmt->execute(['sid' => $sessionId, 'student' => $studentId]);
        $value = $stmt->fetchColumn();
        return $value !== false && (int)$value === 0;
    }

    protected function technicalAccessContext(int $sessionId, int $studentId, string $generation): array
    {
        $attemptId = $_SESSION['quiz_attempt_id'] ?? null;
        $attempt = is_int($attemptId) ? $this->getAttempt($attemptId) : null;
        if ($attempt === null || (int)$attempt['session_id'] !== $sessionId || (int)$attempt['student_id'] !== $studentId) { $attemptId = null; }
        $ref = null;
        $session = $this->requireSession($sessionId);
        if (self::trackingStrengthened($session['tracking_mode'])) {
            if ($attempt === null) { throw new RuntimeException('attempt_mismatch'); }
            $ref = $this->trackingBoundContext($session, $attempt)['context_ref'];
        }
        return ['session_id' => $sessionId, 'student_id' => $studentId, 'attempt_id' => $attemptId, 'tracking_generation' => $generation, 'context_ref' => $ref];
    }

    /** Independent raw browser/tracking causes, evaluated only for the bound student document. */
    protected function currentTechnicalCauses(array $context): array
    {
        $session = $this->requireSession((int)$context['session_id']);
        if (!self::trackingStrengthened($session['tracking_mode'])) { return []; }
        $stmt = $this->db->prepare('SELECT * FROM quiz_tracking_contexts WHERE context_ref=:ref'); $stmt->execute(['ref'=>$context['context_ref']]);
        $row = $stmt->fetch();
        if ($row === false || $row['status'] !== 'active') { throw new RuntimeException('cookie_context_mismatch'); }
        $row = $this->trackingExpireProof($row);
        $row = $this->browserObserveHttp($session, $row);
        $causes = [['scope'=>'tracking', 'active'=>$row['proof_status'] !== 'healthy', 'code'=>$row['proof_status'] === 'expired' ? 'proof_expired' : 'proof_'.$row['proof_status']]];
        if (!empty($session['browser_enabled'])) {
            $valid = $row['browser_status'] === 'valid' && $row['browser_policy_fingerprint'] === QuizBrowserEvaluator::policyFingerprint($this->browserPolicy($session));
            $details = $row['browser_diagnostic_json'] !== null ? json_decode($row['browser_diagnostic_json'], true, 32, JSON_THROW_ON_ERROR) : [];
            foreach ($valid ? [] : ($details['causes'] ?? ['browser_verification_required']) as $code) { $causes[] = ['scope' => 'browser', 'active' => true, 'code' => $code]; }
        }
        return $causes;
    }

    private function normalizeAccessReason(string $reason): string
    {
        $reason = trim((string)preg_replace('/\s+/u', ' ', $reason));
        if ($reason === '' || mb_strlen($reason) > 1000 || preg_match('/[\x00-\x1f\x7f]/u', $reason)) { throw new RuntimeException('invalid_access_reason'); }
        return $reason;
    }

    public function grantTechnicalOverride(int $sessionId, int $studentId, string $expectedGeneration, array $scopes, string $reason): int
    {
        $this->currentAdminId();
        $scopes = QuizAccessEvaluator::normalizeScopes($scopes);
        $reason = $this->normalizeAccessReason($reason);
        $this->db->beginTransaction();
        try {
            $student = $this->overrideMutationContext($sessionId, $studentId, $expectedGeneration);
            if ($this->activeTechnicalOverride($sessionId, $studentId, $expectedGeneration) !== null) { throw new RuntimeException('override_exists'); }
            $actor = $this->auditActor();
            $stmt = $this->db->prepare("INSERT INTO quiz_technical_overrides(session_id, student_id, tracking_generation, scope_browser, scope_tracking, status, grant_reason, granted_at, grant_actor_id, grant_actor_name, first_name, last_name) VALUES(:sid, :student, :generation, :browser, :tracking, 'active', :reason, :now, :actor, :name, :first, :last)");
            $stmt->execute(['sid' => $sessionId, 'student' => $studentId, 'generation' => $expectedGeneration,
                'browser' => in_array('browser', $scopes, true) ? 1 : 0, 'tracking' => in_array('tracking', $scopes, true) ? 1 : 0,
                'reason' => $reason, 'now' => $this->now(), 'actor' => $actor['id'], 'name' => $actor['name'], 'first' => $student['first_name'], 'last' => $student['last_name']]);
            $id = (int)$this->db->lastInsertId();
            $after = $this->activeTechnicalOverride($sessionId, $studentId, $expectedGeneration);
            $this->appendAudit($sessionId, 'override_granted', ['student_id' => $studentId, 'first_name' => $student['first_name'], 'last_name' => $student['last_name'], 'technical_override' => null], $after);
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    public function revokeTechnicalOverride(int $sessionId, int $studentId, int $overrideId, string $expectedGeneration, string $reason): void
    {
        $this->currentAdminId();
        $reason = $this->normalizeAccessReason($reason);
        $this->db->beginTransaction();
        try {
            $this->overrideMutationContext($sessionId, $studentId, $expectedGeneration);
            $before = $this->activeTechnicalOverride($sessionId, $studentId, $expectedGeneration);
            if ($before === null || $before['id'] !== $overrideId) { throw new RuntimeException('override_not_active'); }
            $after = $this->endTechnicalOverride($before, 'revoked', 'revoked', $reason);
            $this->appendAudit($sessionId, 'override_revoked', $before, $after);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    private function overrideMutationContext(int $sessionId, int $studentId, string $expectedGeneration): array
    {
        $this->lockTeacherSession($sessionId);
        $session = $this->requireSession($sessionId);
        if (!in_array($session['state'], ['lobby', 'running'], true)) { throw new RuntimeException('invalid_override_state'); }
        if (preg_match('/^[a-f0-9]{32}$/D', $expectedGeneration) !== 1 || !hash_equals((string)$session['tracking_generation'], $expectedGeneration)) { throw new RuntimeException('stale_generation'); }
        return $this->getStudentAccess($sessionId, $studentId);
    }

    private function activeTechnicalOverride(int $sessionId, int $studentId, string $generation): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM quiz_technical_overrides WHERE session_id = :sid AND student_id = :student AND tracking_generation = :generation AND status = 'active'");
        $stmt->execute(['sid' => $sessionId, 'student' => $studentId, 'generation' => $generation]);
        $row = $stmt->fetch();
        return $row !== false ? $this->technicalOverrideRow($row) : null;
    }

    private function technicalOverrideRow(array $row): array
    {
        $row['scopes'] = [];
        if ((int)$row['scope_browser'] === 1) { $row['scopes'][] = 'browser'; }
        if ((int)$row['scope_tracking'] === 1) { $row['scopes'][] = 'tracking'; }
        unset($row['scope_browser'], $row['scope_tracking']);
        foreach (['id', 'session_id', 'student_id', 'grant_actor_id'] as $key) { $row[$key] = (int)$row[$key]; }
        $row['end_actor_id'] = $row['end_actor_id'] !== null ? (int)$row['end_actor_id'] : null;
        return $row;
    }

    private function endTechnicalOverride(array $before, string $status, string $kind, string $reason): array
    {
        $actor = $this->auditActor();
        $stmt = $this->db->prepare("UPDATE quiz_technical_overrides SET status = :status, ended_at = :now, end_actor_id = :actor, end_actor_name = :name, end_reason = :reason, end_kind = :kind WHERE id = :id AND status = 'active'");
        $after = array_merge($before, ['status' => $status, 'ended_at' => $this->now(), 'end_actor_id' => $actor['id'], 'end_actor_name' => $actor['name'], 'end_reason' => $reason, 'end_kind' => $kind]);
        $stmt->execute(['status' => $status, 'now' => $after['ended_at'], 'actor' => $actor['id'], 'name' => $actor['name'], 'reason' => $reason, 'kind' => $kind, 'id' => $before['id']]);
        if ($stmt->rowCount() !== 1) { throw new RuntimeException('override_not_active'); }
        return $after;
    }

    /** All active rows of this session expire, including stale generations/orphaned students. */
    private function expireTechnicalOverrides(int $sessionId, string $kind): void
    {
        if (!$this->db->inTransaction() || !in_array($kind, ['closed', 'new_launch', 'reset'], true)) { throw new RuntimeException('invalid_override_expiration'); }
        $lastId = 0;
        do {
            // Close the read cursor before updating this table: SQLite does not
            // guarantee a stable SELECT traversal while its rows are mutated.
            $stmt = $this->db->prepare("SELECT * FROM quiz_technical_overrides WHERE session_id = :sid AND status = 'active' AND id > :last ORDER BY id LIMIT 50");
            $stmt->execute(['sid' => $sessionId, 'last' => $lastId]);
            $rows = $stmt->fetchAll(); $stmt->closeCursor();
            foreach ($rows as $row) {
                $before = $this->technicalOverrideRow($row); $lastId = $before['id'];
                $after = $this->endTechnicalOverride($before, 'expired', $kind, $kind);
                $this->appendAudit($sessionId, 'override_expired', $before, $after);
            }
        } while (count($rows) === 50);
    }

    public function listTechnicalOverrides(int $sessionId, ?int $studentId = null, int $beforeId = 0, int $limit = 25): array
    {
        $this->currentAdminId(); $this->requireSession($sessionId);
        $limit = max(1, min(50, $limit));
        $where = 'session_id = :sid'; $params = ['sid' => $sessionId];
        if ($studentId !== null) { $where .= ' AND student_id = :student'; $params['student'] = $studentId; }
        if ($beforeId > 0) { $where .= ' AND id < :before'; $params['before'] = $beforeId; }
        $stmt = $this->db->prepare('SELECT * FROM quiz_technical_overrides WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . ($limit + 1));
        $stmt->execute($params); $rows = $stmt->fetchAll(); $more = count($rows) > $limit;
        $rows = array_map(fn(array $row): array => $this->technicalOverrideRow($row), array_slice($rows, 0, $limit));
        return ['rows' => $rows, 'next_before' => $more ? (int)end($rows)['id'] : null];
    }

    private function accessContext(int $sessionId, int $studentId, array $student): array
    {
        $stmt = $this->db->prepare('SELECT manual_blocked, reason, actor_admin_id, actor_name, changed_at FROM quiz_student_access WHERE session_id = :sid AND student_id = :student');
        $stmt->execute(['sid' => $sessionId, 'student' => $studentId]);
        $row = $stmt->fetch();
        return array_merge(['student_id' => $studentId, 'first_name' => $student['first_name'], 'last_name' => $student['last_name'],
            'manual_blocked' => false, 'reason' => null, 'actor_admin_id' => null, 'actor_name' => null, 'changed_at' => null],
            $row !== false ? array_merge($row, ['manual_blocked' => (int)$row['manual_blocked'] === 1]) : []);
    }

    /**
     * Records a monitoring event. The incident decision is made server-side:
     * an away period >= min_away_seconds on an away-type event counts as an incident.
     */
    public function recordEvent(array $session, array $attempt, string $type, int $awaySeconds, array $metadata = []): array
    {
        $allowedTypes = ['hidden', 'blur', 'fullscreen_exit', 'reload', 'leave', 'devtools', 'copy', 'paste', 'print', 'finish', 'resume', 'away_start', 'tracking_diagnostic'];
        if (!in_array($type, $allowedTypes, true)) {
            throw new RuntimeException('invalid_event_type');
        }

        $awaySeconds = max(0, min($awaySeconds, 3600));
        $metadata = $this->normalizeEventMetadata($type, $metadata, (int)$attempt['id']);
        if ($metadata['duration_ms'] !== null) { $awaySeconds = (int)floor($metadata['duration_ms'] / 1000); }
        $this->db->beginTransaction();
        $this->trackingDecisionTime=null;
        try {
            // The first write takes the SQLite lock before reading current rules.
            // A stale API context cannot restore an old qualification or count.
            $lock = $this->db->prepare('UPDATE quiz_attempts SET incident_count = incident_count WHERE id = :id');
            $lock->execute(['id' => (int)$attempt['id']]);
            $session = $this->requireSession((int)$session['id']);
            $currentAttempt = $this->getAttempt((int)$attempt['id']);
            if ($currentAttempt === null || (int)$currentAttempt['session_id'] !== (int)$session['id']) {
                throw new RuntimeException('resource_not_found');
            }
            if ($metadata['event_uid'] !== null && !hash_equals((string)$session['tracking_generation'], (string)$metadata['tracking_generation'])) {
                throw new RuntimeException('stale_generation');
            }
            $trackingObservation = self::trackingStrengthened($session['tracking_mode']) && !in_array($type,['finish','resume'],true);
            if (in_array($type, ['finish', 'resume'], true)) {
                if(self::trackingStrengthened($session['tracking_mode']) && $metadata['event_uid']===null){throw new RuntimeException('generation_mismatch');}
                if(self::trackingStrengthened($session['tracking_mode'])){$this->trackingBoundContext($session,$currentAttempt);}
                if (!$this->studentAccessAllowed((int)$session['id'], (int)$currentAttempt['student_id'])) {
                    $state = $this->buildStatePayload($session, $currentAttempt);
                    $this->db->commit();
                    return $state; // reversible suspension, no UID acknowledgement or completion mutation
                }
                if ($session['state'] !== 'running') { throw new RuntimeException('session_not_running'); }
            }
            $inserted = $this->insertEvent((int)$attempt['id'], $type, $awaySeconds, false, $metadata);
            if (!$inserted) {
                $existing = $this->db->prepare('SELECT * FROM quiz_events WHERE attempt_id = :aid AND event_uid = :uid');
                $existing->execute(['aid' => (int)$attempt['id'], 'uid' => $metadata['event_uid']]);
                $event = $existing->fetch();
                if (!$event || $event['event_type'] !== $type || (int)$event['away_seconds'] !== $awaySeconds) {
                    throw new RuntimeException('event_uid_conflict');
                }
                foreach (['absence_uid', 'source', 'related_event_uid', 'duration_ms', 'dropped_events', 'tracking_generation'] as $key) {
                    if ((string)($event[$key] ?? '') !== (string)($metadata[$key] ?? '')) { throw new RuntimeException('event_uid_conflict'); }
                }
                $access = $trackingObservation ? ['settings_revision'=>(int)$session['settings_revision']] : ['access_allowed'=>$this->studentAccessAllowed((int)$session['id'], (int)$currentAttempt['student_id'])];
                $this->db->commit();
                return array_merge([
                    'incident_count' => (int)$currentAttempt['incident_count'], 'status' => $currentAttempt['status'],
                    'is_incident' => (int)$event['is_incident'] === 1, 'finished' => !empty($currentAttempt['finished_at']),
                    'attempt_id' => (int)$attempt['id'], 'event_uid' => $metadata['event_uid'], 'duplicate' => true,
                ],$access);
            }
            $eventId = (int)$this->db->lastInsertId();
            if ($metadata['related_event_uid'] !== null) {
                $related = $this->db->prepare('SELECT event_type, absence_uid, source FROM quiz_events WHERE attempt_id = :aid AND event_uid = :uid');
                $related->execute(['aid' => (int)$attempt['id'], 'uid' => $metadata['related_event_uid']]);
                $start = $related->fetch();
                if ($start && ($start['event_type'] !== 'away_start' || $start['absence_uid'] !== $metadata['absence_uid'] || $start['source'] !== $metadata['source'])) { throw new RuntimeException('invalid_event_relation'); }
            }
            if ($type === 'away_start') {
                $returns = $this->db->prepare('SELECT absence_uid, source FROM quiz_events WHERE attempt_id = :aid AND related_event_uid = :uid');
                $returns->execute(['aid' => (int)$attempt['id'], 'uid' => $metadata['event_uid']]);
                foreach ($returns->fetchAll() as $return) {
                    if ($return['absence_uid'] !== $metadata['absence_uid'] || $return['source'] !== $metadata['source']) { throw new RuntimeException('invalid_event_relation'); }
                }
            }
            if (in_array($type, ['finish', 'resume'], true) && $session['state'] !== 'running') {
                throw new RuntimeException('session_not_running');
            }
            if ($metadata['absence_uid'] !== null) {
                $group = $this->db->prepare('SELECT MAX(excused) AS excused, MIN(tracking_generation) AS generation, MAX(tracking_generation) AS last_generation FROM quiz_events WHERE attempt_id = :aid AND absence_uid = :absence');
                $group->execute(['aid' => (int)$attempt['id'], 'absence' => $metadata['absence_uid']]);
                $episode = $group->fetch();
                if ($episode['generation'] !== $metadata['tracking_generation'] || $episode['last_generation'] !== $metadata['tracking_generation']) { throw new RuntimeException('absence_generation_conflict'); }
                if (!empty($episode['excused'])) {
                    $excuse = $this->db->prepare('UPDATE quiz_events SET excused = 1 WHERE attempt_id = :aid AND absence_uid = :absence');
                    $excuse->execute(['aid' => (int)$attempt['id'], 'absence' => $metadata['absence_uid']]);
                }
                $this->reclassifyEvents($session, (int)$attempt['id'], $metadata['absence_uid']);
                $read = $this->db->prepare('SELECT is_incident FROM quiz_events WHERE id = :id');
                $read->execute(['id' => $eventId]);
                $isIncident = (int)$read->fetchColumn() === 1;
            } else {
                $isIncident = $this->eventIsIncident($session, $type, $awaySeconds, $metadata['duration_ms']);
            }
            if ($isIncident && $metadata['absence_uid'] === null) {
                $stmt = $this->db->prepare('UPDATE quiz_events SET is_incident = 1 WHERE id = :id');
                $stmt->execute(['id' => $eventId]);
            }

            if ($type === 'finish') {
                $stmt = $this->db->prepare('UPDATE quiz_attempts SET finished_at = :now WHERE id = :id AND finished_at IS NULL');
                $stmt->execute(['now' => $this->now(), 'id' => (int)$attempt['id']]);
            }

            if ($type === 'resume') {
                $stmt = $this->db->prepare('UPDATE quiz_attempts SET finished_at = NULL WHERE id = :id');
                $stmt->execute(['id' => (int)$attempt['id']]);
            }

            $result = $this->recomputeAttemptStatus((int)$attempt['id'], (int)$session['id']);
            $currentAttempt = $this->getAttempt((int)$attempt['id']);
            $access = $trackingObservation ? ['settings_revision'=>(int)$session['settings_revision']] : ['access_allowed'=>$this->studentAccessAllowed((int)$session['id'], (int)$currentAttempt['student_id'])];
            $this->db->commit();
            return array_merge($result, ['is_incident' => $isIncident, 'finished' => !empty($currentAttempt['finished_at']), 'attempt_id' => (int)$attempt['id'], 'event_uid' => $metadata['event_uid'], 'duplicate' => false,
                ]+$access);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $exception;
        }
    }

    /** Shared qualification for new records and reclassification of history. */
    private function eventIsIncident(array $session, string $type, int $awaySeconds, ?int $durationMs = null): bool
    {
        if ($type === 'reload') {
            return !empty($session['reload_is_incident']);
        }
        if ($type === 'fullscreen_exit' && empty($session['require_fullscreen'])) {
            return false;
        }
        return in_array($type, ['hidden', 'blur', 'fullscreen_exit'], true)
            && ($durationMs !== null ? $durationMs >= (int)$session['min_away_seconds'] * 1000 : $awaySeconds >= (int)$session['min_away_seconds']);
    }

    /** Raw source durations never merge: select one eligible return per episode. */
    private function reclassifyEvents(array $session, ?int $attemptId = null, ?string $absenceUid = null): void
    {
        $sql = 'SELECT e.* FROM quiz_events e JOIN quiz_attempts a ON a.id = e.attempt_id WHERE a.session_id = :sid';
        $parameters = ['sid' => (int)$session['id']];
        if ($attemptId !== null) { $sql .= ' AND e.attempt_id = :aid'; $parameters['aid'] = $attemptId; }
        if ($absenceUid !== null) { $sql .= ' AND e.absence_uid = :absence'; $parameters['absence'] = $absenceUid; }
        $stmt = $this->db->prepare($sql . ' ORDER BY e.id');
        $stmt->execute($parameters);
        $update = $this->db->prepare('UPDATE quiz_events SET is_incident = :incident WHERE id = :id');
        $qualified = [];
        foreach ($stmt->fetchAll() as $event) {
            $incident = $this->eventIsIncident($session, (string)$event['event_type'], (int)$event['away_seconds'], $event['duration_ms'] !== null ? (int)$event['duration_ms'] : null);
            if ($event['absence_uid'] !== null) {
                $group = $event['attempt_id'] . ':' . $event['absence_uid'];
                if (isset($qualified[$group])) { $incident = false; }
                if ($incident) { $qualified[$group] = true; }
            }
            $update->execute(['incident' => $incident ? 1 : 0, 'id' => (int)$event['id']]);
        }
    }

    private function normalizeEventMetadata(string $type, array $data, int $attemptId): array
    {
        $empty = array_fill_keys(['event_uid', 'absence_uid', 'source', 'related_event_uid', 'duration_ms', 'dropped_events', 'tracking_generation'], null);
        if ($data === []) {
            if (in_array($type, ['away_start', 'tracking_diagnostic'], true)) { throw new RuntimeException('invalid_event_metadata'); }
            return $empty;
        }
        foreach (array_keys($data) as $key) {
            if (!array_key_exists($key, $empty) && $key !== 'attempt_id') { throw new RuntimeException('invalid_event_metadata'); }
        }
        $validUid = static fn($value): bool => is_string($value) && preg_match('/^[a-zA-Z0-9_-]{16,80}$/D', $value) === 1;
        if (($data['attempt_id'] ?? null) !== $attemptId) { throw new RuntimeException('attempt_mismatch'); }
        if (!$validUid($data['event_uid'] ?? null) || !is_string($data['tracking_generation'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $data['tracking_generation']) !== 1) { throw new RuntimeException('invalid_event_metadata'); }
        $normalized = array_merge($empty, array_intersect_key($data, $empty));
        if (!in_array($normalized['source'], ['hidden', 'blur', 'fullscreen_exit', 'shortcut', 'navigation', 'page', 'queue'], true)) { throw new RuntimeException('invalid_event_source'); }
        $awaySource = in_array($type, ['away_start', 'hidden', 'blur', 'fullscreen_exit'], true);
        if ($awaySource) {
            if (!$validUid($normalized['absence_uid']) || !in_array($normalized['source'], ['hidden', 'blur', 'fullscreen_exit'], true)) { throw new RuntimeException('invalid_event_metadata'); }
            if ($type === 'away_start') {
                if ($normalized['duration_ms'] !== null || $normalized['related_event_uid'] !== null) { throw new RuntimeException('invalid_event_duration'); }
            } elseif ($type !== $normalized['source'] || !$validUid($normalized['related_event_uid']) || !is_int($normalized['duration_ms']) || $normalized['duration_ms'] < 0 || $normalized['duration_ms'] > 3600000) {
                throw new RuntimeException('invalid_event_duration');
            }
        } elseif ($normalized['absence_uid'] !== null || $normalized['related_event_uid'] !== null || $normalized['duration_ms'] !== null) {
            throw new RuntimeException('invalid_event_metadata');
        }
        if ($type === 'tracking_diagnostic') {
            if ($normalized['source'] !== 'queue' || !is_int($normalized['dropped_events']) || $normalized['dropped_events'] < 1 || $normalized['dropped_events'] > 100000) { throw new RuntimeException('invalid_diagnostic'); }
        } elseif ($normalized['dropped_events'] !== null) { throw new RuntimeException('invalid_diagnostic'); }
        return $normalized;
    }

    /** No schema change: this version follows only incident rules and quota. */
    public function rulesVersion(array $session): string
    {
        return hash('sha256', json_encode([
            (int)$session['max_incidents'], (int)$session['min_away_seconds'],
            !empty($session['require_fullscreen']), !empty($session['reload_is_incident']),
        ]));
    }

    // ------------------------------------------------------------------
    // State & URLs
    // ------------------------------------------------------------------

    /** Current rules and clock shared by student and teacher polling endpoints. */
    public function buildStatePayload(array $session, ?array $attempt = null): array
    {
        $owns = $attempt !== null && !$this->db->inTransaction();
        if ($owns) { $this->db->beginTransaction();$this->trackingDecisionTime=null; }
        try {
            if ($owns) { $lock=$this->db->prepare('UPDATE quiz_sessions SET settings_revision=settings_revision WHERE id=:id'); $lock->execute(['id'=>$session['id']]); }
            $result=$this->buildStatePayloadUnlocked($session,$attempt);
            if ($owns) { $this->db->commit(); } return $result;
        } catch (Throwable $e) { if($owns && $this->db->inTransaction()){$this->db->rollBack();} throw $e; }
    }

    private function buildStatePayloadUnlocked(array $session, ?array $attempt): array
    {
        if ($attempt !== null) {
            $session = $this->requireSession((int)$session['id']);
            $attempt = $this->getAttempt((int)$attempt['id']);
            if ($attempt === null || (int)$attempt['session_id'] !== (int)$session['id']) { throw new RuntimeException('attempt_mismatch'); }
        }
        $now = $this->trackingClock();
        $payload = [
            'state' => $session['state'],
            'title' => (string)$session['title'],
            'duration_minutes' => (int)$session['duration_minutes'],
            'max_incidents' => (int)$session['max_incidents'],
            'min_away_seconds' => (int)$session['min_away_seconds'],
            'require_fullscreen' => !empty($session['require_fullscreen']),
            'reload_is_incident' => !empty($session['reload_is_incident']),
            'rules_version' => $this->rulesVersion($session),
            'server_now' => $now,
            'settings_revision' => (int)$session['settings_revision'],
            'remaining_seconds' => null,
        ];

        if ($session['state'] === 'running' && !empty($session['started_at'])) {
            $endsAt = $this->toTimestamp($session['started_at']) + ((int)$session['duration_minutes'] * 60);
            $payload['remaining_seconds'] = max(0, $endsAt - $now);
        }

        if ($attempt === null) { $payload['history_revision'] = (int)$session['history_revision']; }
        if ($attempt !== null) {
            $payload['attempt_id'] = (int)$attempt['id'];
            $payload['tracking_generation'] = (string)($session['tracking_generation'] ?? '');
            $payload['incident_count'] = (int)$attempt['incident_count'];
            $payload['attempt_status'] = $attempt['status'];
            $payload['finished'] = !empty($attempt['finished_at']);
            $payload['access_allowed'] = $this->studentAccessAllowed((int)$session['id'], (int)$attempt['student_id']);
            $payload['tracking_mode'] = $session['tracking_mode'];
            $payload['access_until'] = null;
            if (self::trackingStrengthened($session['tracking_mode'])) {
                $context=$this->trackingBoundContext($session,$attempt);
                if ($payload['access_allowed']) {
                    $context=$this->trackingExpireProof($context);
                    $payload['access_until']=$context['proof_status']==='healthy' ? (int)$context['proof_until'] : $now+60;
                    if ($context['admitted_at']===null) { $stmt=$this->db->prepare('UPDATE quiz_tracking_contexts SET admitted_at=:now WHERE id=:id');$stmt->execute(['now'=>$this->now(),'id'=>$context['id']]); }
                }
            }
            // The form URL is only delivered once the quiz is actually running.
            if ($payload['access_allowed'] && $session['state'] === 'running') {
                $payload['form_url'] = $this->buildEmbeddedFormUrl($session, (string)$attempt['public_token']);
            }
        }

        return $payload;
    }

    public function buildEmbeddedFormUrl(array $session, string $token): string
    {
        $base = (string)$session['google_form_url'];
        // Strip an existing query string, then rebuild with our parameters
        $base = strtok($base, '?');
        $params = http_build_query([
            'embedded' => 'true',
            'usp' => 'pp_url',
            'entry.' . $session['attempt_entry_id'] => $token,
        ]);
        return $base . '?' . $params;
    }

    /** Verifies that a token (received later from Google Forms) was issued by us. */
    public function verifyAttemptToken(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }
        [$uuid, $sig] = $parts;
        $expected = substr(hash_hmac('sha256', $uuid, $this->hmacSecret), 0, 12);
        return hash_equals($expected, $sig);
    }

    /** Compatibility wrapper; HTTP exports spool and stream without one giant string. */
    public function exportCsv(int $sessionId): string
    {
        $out = fopen('php://temp', 'w+');
        try { $this->writeExportCsv($sessionId, $out); rewind($out); return stream_get_contents($out) ?: ''; }
        finally { fclose($out); }
    }

    public function writeExportCsv(int $sessionId, $out): void
    {
        $this->currentAdminId();
        $this->db->beginTransaction();
        try {
            $this->requireSession($sessionId);
            $this->refreshTrackingProofs($sessionId);
            $this->csvHeader($out);
            $policy = $this->requireSession($sessionId);
            $row = array_fill(0, 23, ''); $row[0] = 'politique_suivi';
            $this->csvRow($out, $row, array_pad(['suivi_courant'], 9, '') + [9 => json_encode(['tracking_policy' => ['mode' => $policy['tracking_mode'], 'settings_revision' => (int)$policy['settings_revision']]], JSON_THROW_ON_ERROR)]);
            $stmt = $this->db->prepare('SELECT a.*, st.first_name, st.last_name, st.code, st.email FROM quiz_attempts a JOIN quiz_students st ON st.id = a.student_id WHERE a.session_id = :sid ORDER BY a.id');
            $stmt->execute(['sid' => $sessionId]);
            while ($attempt = $stmt->fetch()) {
                $metadata = array_pad(['courant'], 9, '');
                $metadata[] = json_encode($this->getStudentAccess($sessionId, (int)$attempt['student_id']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->csvRow($out, $this->csvAttemptRow($attempt), $metadata);
            }
            $stmt->closeCursor();
            $stmt = $this->db->prepare('SELECT e.*, st.first_name, st.last_name FROM quiz_events e JOIN quiz_attempts a ON a.id = e.attempt_id JOIN quiz_students st ON st.id = a.student_id WHERE a.session_id = :sid ORDER BY e.id');
            $stmt->execute(['sid' => $sessionId]);
            while ($event = $stmt->fetch()) { $this->csvRow($out, $this->csvEventRow($event), ['courant']); }
            $stmt->closeCursor();
            $stmt = $this->db->prepare('SELECT * FROM quiz_technical_overrides WHERE session_id = :sid ORDER BY id');
            $stmt->execute(['sid' => $sessionId]);
            while ($row = $stmt->fetch()) {
                $override = $this->technicalOverrideRow($row);
                $record = array_fill(0, 23, ''); $record[0] = 'derogation_technique'; $record[1] = $override['last_name']; $record[2] = $override['first_name'];
                $record[8] = $override['status']; $record[14] = $this->toParisTime($override['granted_at']); $record[22] = $override['tracking_generation'];
                $this->csvRow($out, $record, ['derogation', '', '', '', $override['grant_actor_id'], $override['grant_actor_name'], 1, '', '', json_encode(['technical_override' => $override], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            }
            $stmt->closeCursor();
            $this->csvTrackingRecords($out, $sessionId);
            $this->csvBrowserRecords($out, $sessionId);
            $before = 0;
            do {
                $page = $this->listArchives($sessionId, $before, 25);
                foreach ($page['rows'] as $row) {
                    $archive = $this->getArchive((int)$row['id']);
                    $this->csvArchive($out, $archive);
                    unset($archive);
                }
                $before = $page['next_before'];
            } while ($before !== null);
            $before = 0;
            do {
                $page = $this->listAudit($sessionId, $before, 25);
                foreach ($page['rows'] as $entry) {
                    $row = array_fill(0, 23, ''); $row[0] = 'audit'; $row[12] = $entry['action']; $row[14] = $this->toParisTime($entry['created_at']);
                    $this->csvRow($out, $row, ['audit', '', '', $this->toParisTime($entry['created_at']), $entry['actor_admin_id'], $entry['actor_name'], $entry['format_version'], json_encode($entry['before'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($entry['after'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                }
                $before = $page['next_before'];
            } while ($before !== null);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    public function exportArchiveCsv(int $archiveId): string
    {
        $out = fopen('php://temp', 'w+');
        try { $this->writeArchiveCsv($archiveId, $out); rewind($out); return stream_get_contents($out) ?: ''; }
        finally { fclose($out); }
    }

    public function writeArchiveCsv(int $archiveId, $out): void
    {
        $archive = $this->getArchive($archiveId);
        if ($archive === null) { throw new RuntimeException('resource_not_found'); }
        $this->csvHeader($out); $this->csvArchive($out, $archive);
    }

    private function csvHeader($out): void
    {
        fputcsv($out, ['type', 'nom', 'prenom', 'code', 'token', 'email', 'fin_declaree_a', 'reponses_google_forms', 'statut', 'incidents', 'rejoint_a', 'dernier_heartbeat', 'evenement', 'duree_absence_s', 'reception_serveur', 'source', 'duree_absence_ms', 'precision', 'episode', 'event_uid', 'depart_uid', 'traces_perdues', 'generation', 'section', 'archive_id', 'nonce_au_reset', 'copie_avant_reset_a', 'acteur_id', 'acteur', 'format_version', 'audit_avant', 'audit_apres', 'contexte_acces_prive'], ';', '"', '');
    }

    private function csvRow($out, array $row, array $metadata): void
    {
        if (fputcsv($out, array_merge($row, array_pad($metadata, 10, '')), ';', '"', '') === false) { throw new RuntimeException('export_write_failed'); }
    }

    private function csvAttemptRow(array $a): array
    {
        return ['tentative', $a['last_name'], $a['first_name'], $a['code'] ?? '', $a['public_token'] ?? '', $a['email'], $this->toParisTime($a['finished_at']), 'non vérifiées dans Quiz', $a['status'], $a['incident_count'], $this->toParisTime($a['started_at']), $this->toParisTime($a['last_heartbeat_at']), '', '', '', '', '', '', '', '', '', '', ''];
    }

    private function csvEventRow(array $e): array
    {
        return ['evenement', $e['last_name'], $e['first_name'], '', '', '', '', '', '', '', '', '', $e['event_type'] . ($e['is_incident'] ? (!empty($e['excused']) ? ' (incident excusé)' : ' (incident)') : ''), $e['event_type'] === 'away_start' ? '' : $e['away_seconds'], $this->toParisTime($e['created_at']), $e['source'], $e['duration_ms'], $e['event_type'] === 'away_start' ? 'inconnue' : ($e['duration_ms'] !== null ? ((int)$e['duration_ms'] >= 3600000 ? 'ms_minimum' : 'ms') : 's'), $e['absence_uid'], $e['event_uid'], $e['related_event_uid'], $e['dropped_events'], $e['tracking_generation']];
    }

    private function csvArchive($out, array $archive): void
    {
        $snapshot = $archive['snapshot']; $attempt = $snapshot['attempt'];
        $metadata = ['archive', $archive['id'], $archive['generation'], $this->toParisTime($archive['archived_at']), $archive['actor_admin_id'], $archive['actor_name'], $archive['format_version']];
        $metadata = array_pad($metadata, 9, '');
        $metadata[] = isset($snapshot['access_context']) ? json_encode($snapshot['access_context'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : 'non conservé';
        $row = $this->csvAttemptRow($attempt); $row[0] = 'archive_tentative'; $this->csvRow($out, $row, $metadata);
        foreach ($snapshot['events'] as $event) {
            $row = $this->csvEventRow($event + ['first_name' => $attempt['first_name'], 'last_name' => $attempt['last_name']]);
            $row[0] = 'archive_evenement'; $this->csvRow($out, $row, $metadata);
        }
        $policy = $snapshot['tracking_policy'] ?? 'non conservé';
        $trackingMetadata = $metadata;
        $trackingMetadata[9] = json_encode(['tracking_policy' => $policy,'browser_policy'=>$snapshot['browser_policy']??'non conservé'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $row = array_fill(0, 23, ''); $row[0] = 'archive_politique_suivi';
        $this->csvRow($out, $row, $trackingMetadata);
        foreach ($snapshot['tracking_contexts'] ?? [] as $context) {
            $this->csvTrackingRecord($out, 'archive_contexte_suivi', $context, $metadata);
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** All teacher mutations acquire their write lock before fresh ownership/state reads. */
    private function lockTeacherSession(int $sessionId): void
    {
        $this->currentAdminId();
        $lock = $this->db->prepare('UPDATE quiz_sessions SET tracking_generation = tracking_generation WHERE id = :id');
        $lock->execute(['id' => $sessionId]);
        $this->requireSession($sessionId);
    }

    private function sessionMutation(int $sessionId, string $action, callable $mutation): mixed
    {
        $this->currentAdminId();
        $this->db->beginTransaction();
        try {
            $this->lockTeacherSession($sessionId);
            $session = $this->requireSession($sessionId);
            $before = $this->sessionAuditFields($session);
            if ($action === 'reset') { $before += $this->sessionCounts($sessionId); }
            $result = $mutation($session);
            $after = $this->sessionAuditFields($this->requireSession($sessionId));
            if ($action === 'reset') { $after += $this->sessionCounts($sessionId); $after['archived_attempt_count'] = $result; }
            $this->appendAudit($sessionId, $action, $before, $after);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    private function ruleFields(array $session): array
    {
        return [
            'title' => (string)$session['title'], 'duration_minutes' => (int)$session['duration_minutes'],
            'max_incidents' => (int)$session['max_incidents'], 'min_away_seconds' => (int)$session['min_away_seconds'],
            'require_fullscreen' => !empty($session['require_fullscreen']), 'reload_is_incident' => !empty($session['reload_is_incident']),
        ];
    }

    private function sessionAuditFields(array $session): array
    {
        // Strip any prefilled query values from the public URL; a digest still
        // distinguishes a query-only edit without collecting Forms responses.
        return $this->ruleFields($session) + [
            'google_form_url' => strtok((string)$session['google_form_url'], '?') ?: '',
            'google_form_url_hash' => hash('sha256', (string)$session['google_form_url']),
            'google_form_edit_url' => (string)$session['google_form_edit_url'],
            'attempt_entry_id' => (string)$session['attempt_entry_id'],
            'state' => $session['state'], 'started_at' => $session['started_at'], 'closed_at' => $session['closed_at'],
            'pin_active' => !empty($session['access_pin']), 'tracking_generation' => $session['tracking_generation'],
            'history_revision' => (int)$session['history_revision'],
            'tracking_mode' => $session['tracking_mode'], 'settings_revision' => (int)$session['settings_revision'],
        ];
    }

    private function sessionCounts(int $sessionId): array
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS attempt_count, COALESCE(SUM(incident_count), 0) AS incident_count, SUM(CASE WHEN finished_at IS NOT NULL THEN 1 ELSE 0 END) AS finished_count FROM quiz_attempts WHERE session_id = :sid');
        $stmt->execute(['sid' => $sessionId]);
        $counts = array_map('intval', $stmt->fetch());
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM quiz_events e JOIN quiz_attempts a ON a.id = e.attempt_id WHERE a.session_id = :sid');
        $stmt->execute(['sid' => $sessionId]);
        $counts['event_count'] = (int)$stmt->fetchColumn();
        return $counts;
    }

    private function attemptAuditFields(int $attemptId): array
    {
        $attempt = $this->getAttempt($attemptId);
        if ($attempt === null) { throw new RuntimeException('resource_not_found'); }
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM quiz_events WHERE attempt_id = :aid AND excused = 1');
        $stmt->execute(['aid' => $attemptId]);
        return ['attempt_id' => $attemptId, 'first_name' => $attempt['first_name'], 'last_name' => $attempt['last_name'],
            'incident_count' => (int)$attempt['incident_count'],
            'status' => $attempt['status'], 'finished_at' => $attempt['finished_at'], 'excused_event_count' => (int)$stmt->fetchColumn()];
    }

    private function auditActor(): array
    {
        $stmt = $this->db->prepare("SELECT id, display_name FROM admin_users WHERE id = :id AND status = 'active'");
        $stmt->execute(['id' => $this->currentAdminId()]);
        $actor = $stmt->fetch();
        if ($actor === false) { throw new RuntimeException('admin_auth_required'); }
        return ['id' => (int)$actor['id'], 'name' => mb_substr((string)$actor['display_name'], 0, 256)];
    }

    /** Reusable within atomic teacher operations; callers supply targeted fields. */
    private function appendAudit(int $sessionId, string $action, array $before, array $after): void
    {
        $this->currentAdminId();
        $this->requireSession($sessionId);
        if (!$this->db->inTransaction()) { throw new RuntimeException('audit_requires_transaction'); }
        $actor = $this->auditActor();
        $beforeJson = json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $afterJson = json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($beforeJson) > 16384 || strlen($afterJson) > 16384) { throw new RuntimeException('audit_too_large'); }
        $stmt = $this->db->prepare('INSERT INTO quiz_session_audit(session_id, action, actor_admin_id, actor_name, created_at, before_json, after_json) VALUES(:sid, :action, :actor, :name, :now, :before, :after)');
        $stmt->execute(['sid' => $sessionId, 'action' => $action, 'actor' => $actor['id'], 'name' => $actor['name'], 'now' => $this->now(), 'before' => $beforeJson, 'after' => $afterJson]);
    }

    private function archiveAttempts(array $session): int
    {
        $sid = (int)$session['id'];
        // Observe expiry once for the complete reset snapshot, under its existing write lock.
        $this->refreshTrackingProofs($sid);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE session_id = :sid');
        $stmt->execute(['sid' => $sid]);
        if ((int)$stmt->fetchColumn() > self::MAX_ARCHIVE_ATTEMPTS) { throw new RuntimeException('archive_attempt_limit'); }
        $attempts = $this->listAttempts($sid);
        $actor = $this->auditActor(); $date = $this->now(); $totalBytes = 0;
        $insert = $this->db->prepare('INSERT INTO quiz_attempt_archives(session_id, source_attempt_id, generation, format_version, actor_admin_id, actor_name, archived_at, first_name, last_name, incident_count, status, finished_at, event_count, snapshot_json) VALUES(:sid, :aid, :generation, :version, :actor, :name, :now, :first, :last, :count, :status, :finished, :events, :json)');
        foreach ($attempts as $attempt) {
            if ((int)$attempt['event_count'] > self::MAX_ARCHIVE_EVENTS) { throw new RuntimeException('archive_event_limit'); }
            $fields = ['id', 'student_id', 'first_name', 'last_name', 'email', 'incident_count', 'status', 'finished_at', 'started_at', 'last_heartbeat_at', 'created_at'];
            $frozenAttempt = array_intersect_key($attempt, array_fill_keys($fields, true));
            $events = array_reverse($this->listEventsForAttempt((int)$attempt['id']));
            $snapshot = ['format_version' => self::ARCHIVE_VERSION, 'session_id' => $sid,
                'reset_generation' => (string)($session['tracking_generation'] ?? ''), 'rules' => $this->ruleFields($session),
                'attempt' => $frozenAttempt, 'events' => $events,
                'access_context' => $this->getStudentAccess($sid, (int)$attempt['student_id']),
                'tracking_policy' => ['mode'=>$session['tracking_mode'], 'settings_revision'=>(int)$session['settings_revision']],
                'browser_policy' => $this->browserPolicy($session),
                'tracking_contexts' => $this->trackingSnapshotContexts($sid,(int)$attempt['id']),
                'event_generations' => array_values(array_unique(array_filter(array_column($events, 'tracking_generation'), static fn($value): bool => $value !== null && $value !== '')))];
            $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $bytes = strlen($json); $totalBytes += $bytes;
            if ($bytes > self::MAX_ARCHIVE_BYTES || $totalBytes > self::MAX_RESET_ARCHIVE_BYTES) { throw new RuntimeException('archive_size_limit'); }
            $insert->execute(['sid' => $sid, 'aid' => (int)$attempt['id'], 'generation' => $snapshot['reset_generation'],
                'version' => self::ARCHIVE_VERSION, 'actor' => $actor['id'], 'name' => $actor['name'], 'now' => $date,
                'first' => $attempt['first_name'], 'last' => $attempt['last_name'], 'count' => (int)$attempt['incident_count'],
                'status' => $attempt['status'], 'finished' => $attempt['finished_at'], 'events' => count($events), 'json' => $json]);
            unset($events, $snapshot, $json);
        }
        return count($attempts);
    }

    /** Metadata-only keyset pagination; never load a page of large JSON snapshots. */
    public function listArchives(int $sessionId, int $beforeId = 0, int $limit = 25, ?int $attemptId = null): array
    {
        $this->currentAdminId(); $this->requireSession($sessionId);
        $limit = max(1, min(50, $limit));
        $where = 'session_id = :sid'; $params = ['sid' => $sessionId];
        if ($beforeId > 0) { $where .= ' AND id < :before'; $params['before'] = $beforeId; }
        if ($attemptId !== null) { $where .= ' AND source_attempt_id = :aid'; $params['aid'] = $attemptId; }
        $stmt = $this->db->prepare('SELECT id, session_id, source_attempt_id, generation, format_version, actor_admin_id, actor_name, archived_at, first_name, last_name, incident_count, status, finished_at, event_count FROM quiz_attempt_archives WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . ($limit + 1));
        $stmt->execute($params); $rows = $stmt->fetchAll();
        $more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
        return ['rows' => $rows, 'next_before' => $more ? (int)end($rows)['id'] : null];
    }

    public function getArchive(int $archiveId): ?array
    {
        $this->currentAdminId();
        $sql = 'SELECT ar.* FROM quiz_attempt_archives ar JOIN quiz_sessions s ON s.id = ar.session_id WHERE ar.id = :id';
        $params = ['id' => $archiveId];
        if (!$this->isSuperAdmin()) { $sql .= ' AND s.owner_admin_id = :owner'; $params['owner'] = $this->currentAdminId(); }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params); $row = $stmt->fetch();
        if ($row === false) { return null; }
        $this->requireSession((int)$row['session_id']);
        if ((int)$row['format_version'] !== self::ARCHIVE_VERSION || strlen($row['snapshot_json']) > self::MAX_ARCHIVE_BYTES) { throw new RuntimeException('unsupported_archive_format'); }
        $snapshot = json_decode($row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        if (($snapshot['format_version'] ?? null) !== self::ARCHIVE_VERSION || !is_array($snapshot['rules'] ?? null) || !is_array($snapshot['attempt'] ?? null) || !is_array($snapshot['events'] ?? null)) { throw new RuntimeException('invalid_archive'); }
        unset($row['snapshot_json']); $row['snapshot'] = $snapshot;
        return $row;
    }

    public function listAudit(int $sessionId, int $beforeId = 0, int $limit = 25): array
    {
        $this->currentAdminId(); $this->requireSession($sessionId);
        $limit = max(1, min(50, $limit));
        $where = 'session_id = :sid'; $params = ['sid' => $sessionId];
        if ($beforeId > 0) { $where .= ' AND id < :before'; $params['before'] = $beforeId; }
        $stmt = $this->db->prepare('SELECT * FROM quiz_session_audit WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . ($limit + 1));
        $stmt->execute($params); $rows = $stmt->fetchAll();
        $more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$row) {
            $row['before'] = json_decode($row['before_json'], true, 512, JSON_THROW_ON_ERROR);
            $row['after'] = json_decode($row['after_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($row['before_json'], $row['after_json']);
        }
        unset($row);
        return ['rows' => $rows, 'next_before' => $more ? (int)end($rows)['id'] : null];
    }

    private function requireSession(int $id): array
    {
        $session = $this->getSession($id);
        if ($session === null) {
            throw new RuntimeException('resource_not_found');
        }
        return $session;
    }

    private function insertEvent(int $attemptId, string $type, int $awaySeconds, bool $isIncident, array $metadata = []): bool
    {
        $stmt = $this->db->prepare(
            ($metadata['event_uid'] ?? null ? 'INSERT OR IGNORE' : 'INSERT') . ' INTO quiz_events (attempt_id, event_type, away_seconds, is_incident, created_at, event_uid, absence_uid, source, related_event_uid, duration_ms, dropped_events, tracking_generation)
             VALUES (:aid, :type, :away, :incident, :now, :uid, :absence, :source, :related, :duration, :dropped, :generation)'
        );
        $stmt->execute([
            'aid' => $attemptId,
            'type' => $type,
            'away' => $awaySeconds,
            'incident' => $isIncident ? 1 : 0,
            'now' => $this->now(),
            'uid' => $metadata['event_uid'] ?? null, 'absence' => $metadata['absence_uid'] ?? null,
            'source' => $metadata['source'] ?? null, 'related' => $metadata['related_event_uid'] ?? null,
            'duration' => $metadata['duration_ms'] ?? null, 'dropped' => $metadata['dropped_events'] ?? null,
            'generation' => $metadata['tracking_generation'] ?? null,
        ]);
        return $stmt->rowCount() > 0;
    }

    private function generateAttemptToken(): string
    {
        $uuid = bin2hex(random_bytes(8));
        $sig = substr(hash_hmac('sha256', $uuid, $this->hmacSecret), 0, 12);
        return $uuid . '.' . $sig;
    }

    /** True when every cell of the line is a known CSV header word (Prénom, Nom, Email…). */
    private function isRosterHeader(array $parts): bool
    {
        $known = [
            'prenom', 'prénom', 'pr�nom', 'nom', 'nom de famille', 'name',
            'first name', 'firstname', 'last name', 'lastname',
            'email', 'e-mail', 'mail', 'courriel', 'adresse email', 'adresse e-mail',
        ];
        $matched = 0;
        foreach ($parts as $part) {
            $part = mb_strtolower(trim($part));
            if ($part === '') {
                continue;
            }
            if (!in_array($part, $known, true)) {
                return false;
            }
            $matched++;
        }
        return $matched > 0;
    }

    /** Validate the whole import before creating a session or inserting any row. */
    private function validateRosterEmails(string $text): void
    {
        $text = (string)preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) ?: [] as $index => $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $separator = strpos($line, "\t") !== false ? "\t" : (strpos($line, ';') !== false ? ';' : (strpos($line, ',') !== false ? ',' : null));
            $parts = $separator === null ? (preg_split('/\s+/', $line) ?: []) : array_map('trim', explode($separator, $line));
            if ($this->isRosterHeader($parts)) { continue; }
            $emails = array_filter($parts, static fn($part) => filter_var($part, FILTER_VALIDATE_EMAIL) !== false);
            if (count($emails) !== 1) {
                throw new RuntimeException('E-mail obligatoire et valide : ligne ' . ($index + 1));
            }
        }
    }

    private function generateStudentCode(array $existing): string
    {
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (isset($existing[$code]));
        return $code;
    }

    private function existingCodes(int $sessionId): array
    {
        $stmt = $this->db->prepare('SELECT code FROM quiz_students WHERE session_id = :sid');
        $stmt->execute(['sid' => $sessionId]);
        $codes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $codes[$code] = true;
        }
        return $codes;
    }

    private function generateSlug(string $title): string
    {
        $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title) ?? '', '-'));
        if ($base === '') {
            $base = 'quiz';
        }
        return substr($base, 0, 40) . '-' . bin2hex(random_bytes(3));
    }

    /** Accepts "entry.123456", "123456" or a full prefilled URL and returns the numeric id. */
    private function normalizeEntryId(string $input): string
    {
        $input = trim($input);
        if (preg_match('/entry\.(\d+)/', $input, $m)) {
            return $m[1];
        }
        if (preg_match('/^\d+$/', $input)) {
            return $input;
        }
        return '';
    }

    private function resolveHmacSecret(?string $configured): string
    {
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $secretFile = $this->storagePath . DIRECTORY_SEPARATOR . 'quiz-secret.key';
        if (is_file($secretFile)) {
            $secret = trim((string)file_get_contents($secretFile));
            if ($secret !== '') {
                return $secret;
            }
        }

        $secret = bin2hex(random_bytes(32));
        file_put_contents($secretFile, $secret);
        return $secret;
    }

    /** Truncated hash of the IP: enough to spot "same room / different network", no raw IP stored. */
    private function anonymizedIpHash(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return substr(hash_hmac('sha256', $ip, $this->hmacSecret), 0, 12);
    }

    protected function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Storage is UTC; exports and displays are in Paris time. */
    private function toParisTime(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        try {
            $dt = new \DateTime($utc, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone('Europe/Paris'));
            return $dt->format('d/m/Y H:i:s');
        } catch (\Exception $e) {
            return $utc;
        }
    }

    private function toTimestamp(string $utc): int
    {
        $ts = strtotime($utc . ' UTC');
        return $ts === false ? time() : $ts;
    }
}
