<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

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
    // Unambiguous alphabet for student codes (no 0/O, 1/I/L)
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const CODE_LENGTH = 5;

    private PDO $db;
    private string $storagePath;
    private string $hmacSecret;

    public function __construct(QuizDbService $dbService, array $config, string $storagePath)
    {
        $this->db = $dbService->pdo();
        $this->storagePath = $storagePath;
        $this->hmacSecret = $this->resolveHmacSecret($config['branding']['quiz_hmac_secret'] ?? null);
    }

    // ------------------------------------------------------------------
    // Sessions (teacher side)
    // ------------------------------------------------------------------

    public function createSession(array $data, string $rosterText): array
    {
        $title = trim((string)($data['title'] ?? ''));
        $formUrl = trim((string)($data['google_form_url'] ?? ''));
        $entryId = $this->normalizeEntryId((string)($data['attempt_entry_id'] ?? ''));

        if ($title === '' || $formUrl === '' || $entryId === '') {
            throw new RuntimeException('missing_fields');
        }
        if (!preg_match('#^https://docs\.google\.com/forms/#', $formUrl)) {
            throw new RuntimeException('invalid_form_url');
        }

        $slug = $this->generateSlug($title);

        $stmt = $this->db->prepare(
            'INSERT INTO quiz_sessions (slug, title, google_form_url, attempt_entry_id, duration_minutes, max_incidents, min_away_seconds, require_fullscreen, reload_is_incident, state)
             VALUES (:slug, :title, :url, :entry, :duration, :max_incidents, :min_away, :fullscreen, :reload, :state)'
        );
        $stmt->execute([
            'slug' => $slug,
            'title' => $title,
            'url' => $formUrl,
            'entry' => $entryId,
            'duration' => max(1, (int)($data['duration_minutes'] ?? 30)),
            'max_incidents' => max(1, (int)($data['max_incidents'] ?? 2)),
            'min_away' => max(1, (int)($data['min_away_seconds'] ?? 10)),
            'fullscreen' => isset($data['require_fullscreen']) ? 1 : 0,
            'reload' => isset($data['reload_is_incident']) ? 1 : 0,
            'state' => 'armed',
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
        $this->requireSession($sessionId);

        $title = trim((string)($data['title'] ?? ''));
        $formUrl = trim((string)($data['google_form_url'] ?? ''));
        $entryId = $this->normalizeEntryId((string)($data['attempt_entry_id'] ?? ''));

        if ($title === '' || $formUrl === '' || $entryId === '') {
            throw new RuntimeException('missing_fields');
        }
        if (!preg_match('#^https://docs\.google\.com/forms/#', $formUrl)) {
            throw new RuntimeException('invalid_form_url');
        }

        $stmt = $this->db->prepare(
            'UPDATE quiz_sessions SET title = :title, google_form_url = :url, attempt_entry_id = :entry,
                    duration_minutes = :duration, max_incidents = :max_incidents, min_away_seconds = :min_away,
                    require_fullscreen = :fullscreen, reload_is_incident = :reload
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'url' => $formUrl,
            'entry' => $entryId,
            'duration' => max(1, (int)($data['duration_minutes'] ?? 30)),
            'max_incidents' => max(1, (int)($data['max_incidents'] ?? 2)),
            'min_away' => max(1, (int)($data['min_away_seconds'] ?? 10)),
            'fullscreen' => isset($data['require_fullscreen']) ? 1 : 0,
            'reload' => isset($data['reload_is_incident']) ? 1 : 0,
            'id' => $sessionId,
        ]);

        // Thresholds may have changed: re-derive every attempt status
        $stmt = $this->db->prepare('SELECT id FROM quiz_attempts WHERE session_id = :sid');
        $stmt->execute(['sid' => $sessionId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $attemptId) {
            $this->recomputeAttemptStatus((int)$attemptId, $sessionId);
        }
    }

    /**
     * Roster format: one student per line. Name and email are both optional
     * (but at least one is required). Accepted separators: ';', ',' or spaces.
     *   "Léa Dupont" — "Léa;Dupont" — "Léa Dupont lea@ecole.fr" — "lea@ecole.fr"
     * Returns the number of imported students.
     */
    public function importRoster(int $sessionId, string $rosterText): int
    {
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

    public function listSessions(): array
    {
        return $this->db->query(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM quiz_students st WHERE st.session_id = s.id) AS student_count,
                    (SELECT COUNT(*) FROM quiz_attempts a WHERE a.session_id = s.id) AS attempt_count
             FROM quiz_sessions s ORDER BY s.id DESC'
        )->fetchAll();
    }

    public function getSession(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM quiz_sessions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
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
        $session = $this->requireSession($sessionId);
        if (!in_array($session['state'], ['armed', 'closed'], true)) {
            throw new RuntimeException('invalid_state');
        }

        do {
            $pin = (string)random_int(100000, 999999);
        } while ($this->findSessionByPin($pin) !== null);

        $stmt = $this->db->prepare(
            "UPDATE quiz_sessions SET state = 'lobby', access_pin = :pin, pin_generated_at = :now, started_at = NULL, closed_at = NULL WHERE id = :id"
        );
        $stmt->execute(['pin' => $pin, 'now' => $this->now(), 'id' => $sessionId]);

        return $pin;
    }

    /**
     * Starts the quiz: the timer starts now, synchronized for everyone.
     * Also allowed while running: relaunches with a fresh timer (debug / false start).
     */
    public function launch(int $sessionId): void
    {
        $session = $this->requireSession($sessionId);
        if (!in_array($session['state'], ['lobby', 'running'], true)) {
            throw new RuntimeException('invalid_state');
        }
        $stmt = $this->db->prepare("UPDATE quiz_sessions SET state = 'running', started_at = :now WHERE id = :id");
        $stmt->execute(['now' => $this->now(), 'id' => $sessionId]);
    }

    /** Pauses a running quiz back to the lobby (PIN kept, students see the waiting screen). */
    public function stop(int $sessionId): void
    {
        $session = $this->requireSession($sessionId);
        if ($session['state'] !== 'running') {
            throw new RuntimeException('invalid_state');
        }
        $stmt = $this->db->prepare("UPDATE quiz_sessions SET state = 'lobby', started_at = NULL WHERE id = :id");
        $stmt->execute(['id' => $sessionId]);
    }

    /**
     * Full restart: wipes every monitoring event of the session, resets every
     * attempt (status, incidents, finished flag) and relaunches with a fresh
     * timer. Attempts and tokens are kept so connected students stay in the room.
     */
    public function resetAndRelaunch(int $sessionId): void
    {
        $session = $this->requireSession($sessionId);
        if (!in_array($session['state'], ['lobby', 'running'], true)) {
            throw new RuntimeException('invalid_state');
        }

        $stmt = $this->db->prepare(
            'DELETE FROM quiz_events WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE session_id = :sid)'
        );
        $stmt->execute(['sid' => $sessionId]);

        $stmt = $this->db->prepare(
            "UPDATE quiz_attempts SET incident_count = 0, status = 'started', finished_at = NULL WHERE session_id = :sid"
        );
        $stmt->execute(['sid' => $sessionId]);

        $stmt = $this->db->prepare("UPDATE quiz_sessions SET state = 'running', started_at = :now WHERE id = :id");
        $stmt->execute(['now' => $this->now(), 'id' => $sessionId]);
    }

    public function close(int $sessionId): void
    {
        $stmt = $this->db->prepare("UPDATE quiz_sessions SET state = 'closed', closed_at = :now, access_pin = NULL WHERE id = :id");
        $stmt->execute(['now' => $this->now(), 'id' => $sessionId]);
    }

    /**
     * Bulk edit: $rows is keyed by student id, each value holding
     * first_name / last_name / email. Invalid rows are skipped and counted.
     * Returns ['updated' => int, 'errors' => int].
     */
    public function updateStudents(int $sessionId, array $rows): array
    {
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
        $first = trim((string)($data['first_name'] ?? ''));
        $last = trim((string)($data['last_name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));

        if ($first === '') {
            throw new RuntimeException('missing_fields');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
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
        $stmt = $this->db->prepare(
            'SELECT a.*, st.first_name, st.last_name, st.code,
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
        $stmt = $this->db->prepare('SELECT * FROM quiz_events WHERE attempt_id = :aid ORDER BY id DESC');
        $stmt->execute(['aid' => $attemptId]);
        return $stmt->fetchAll();
    }

    /** New events for the live feed: everything after $afterId, oldest first. */
    public function listEventsSince(int $sessionId, int $afterId, int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, a.student_id, st.first_name, st.last_name
             FROM quiz_events e
             JOIN quiz_attempts a ON a.id = e.attempt_id
             JOIN quiz_students st ON st.id = a.student_id
             WHERE a.session_id = :sid AND e.id > :after
             ORDER BY e.id ASC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['sid' => $sessionId, 'after' => $afterId]);
        return $stmt->fetchAll();
    }

    /**
     * Teacher arbitration: excuse (or reinstate) an incident, then recompute
     * the attempt's incident count and status. Returns the attempt id, or
     * null if the event does not exist or is not an incident.
     */
    public function setEventExcused(int $eventId, bool $excused): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT e.id, e.attempt_id, e.is_incident, a.session_id
             FROM quiz_events e JOIN quiz_attempts a ON a.id = e.attempt_id
             WHERE e.id = :id'
        );
        $stmt->execute(['id' => $eventId]);
        $event = $stmt->fetch();
        if ($event === false || (int)$event['is_incident'] !== 1) {
            return null;
        }

        $upd = $this->db->prepare('UPDATE quiz_events SET excused = :ex WHERE id = :id');
        $upd->execute(['ex' => $excused ? 1 : 0, 'id' => (int)$event['id']]);

        $this->recomputeAttemptStatus((int)$event['attempt_id'], (int)$event['session_id']);
        return (int)$event['attempt_id'];
    }

    /**
     * Teacher arbitration, whole-student version: excuses every incident of
     * one attempt in a single click and resets its status to 'started'.
     * Returns the session id, or null if the attempt does not exist.
     */
    public function excuseAttempt(int $attemptId): ?int
    {
        $attempt = $this->getAttempt($attemptId);
        if ($attempt === null) {
            return null;
        }

        $stmt = $this->db->prepare('UPDATE quiz_events SET excused = 1 WHERE attempt_id = :aid AND is_incident = 1');
        $stmt->execute(['aid' => $attemptId]);

        $this->recomputeAttemptStatus($attemptId, (int)$attempt['session_id']);
        return (int)$attempt['session_id'];
    }

    /** Recounts non-excused incidents and re-derives the attempt status. */
    private function recomputeAttemptStatus(int $attemptId, int $sessionId): void
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
        $stmt = $this->db->prepare(
            'SELECT a.*, st.first_name, st.last_name, st.code
             FROM quiz_attempts a JOIN quiz_students st ON st.id = a.student_id
             WHERE a.id = :id'
        );
        $stmt->execute(['id' => $attemptId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function recordHeartbeat(int $attemptId): void
    {
        $stmt = $this->db->prepare('UPDATE quiz_attempts SET last_heartbeat_at = :now WHERE id = :id');
        $stmt->execute(['now' => $this->now(), 'id' => $attemptId]);
    }

    /**
     * Records a monitoring event. The incident decision is made server-side:
     * an away period >= min_away_seconds on an away-type event counts as an incident.
     */
    public function recordEvent(array $session, array $attempt, string $type, int $awaySeconds): array
    {
        $allowedTypes = ['hidden', 'blur', 'fullscreen_exit', 'reload', 'leave', 'devtools', 'copy', 'paste', 'print', 'finish'];
        if (!in_array($type, $allowedTypes, true)) {
            throw new RuntimeException('invalid_event_type');
        }

        $awaySeconds = max(0, min($awaySeconds, 3600));
        $awayTypes = ['hidden', 'blur'];
        if (!empty($session['require_fullscreen'])) {
            $awayTypes[] = 'fullscreen_exit';
        }
        $isIncident = in_array($type, $awayTypes, true) && $awaySeconds >= (int)$session['min_away_seconds'];
        if ($type === 'reload' && !empty($session['reload_is_incident'])) {
            $isIncident = true;
        }

        $this->insertEvent((int)$attempt['id'], $type, $awaySeconds, $isIncident);

        if ($type === 'finish') {
            $stmt = $this->db->prepare('UPDATE quiz_attempts SET finished_at = :now WHERE id = :id AND finished_at IS NULL');
            $stmt->execute(['now' => $this->now(), 'id' => (int)$attempt['id']]);
        }

        if ($isIncident) {
            $newCount = (int)$attempt['incident_count'] + 1;
            $status = $newCount >= (int)$session['max_incidents'] ? 'invalid' : 'suspect';
            $stmt = $this->db->prepare('UPDATE quiz_attempts SET incident_count = :count, status = :status WHERE id = :id');
            $stmt->execute(['count' => $newCount, 'status' => $status, 'id' => (int)$attempt['id']]);
            return ['incident_count' => $newCount, 'status' => $status, 'is_incident' => true];
        }

        return [
            'incident_count' => (int)$attempt['incident_count'],
            'status' => (string)$attempt['status'],
            'is_incident' => false,
        ];
    }

    // ------------------------------------------------------------------
    // State & URLs
    // ------------------------------------------------------------------

    /** Payload polled by the student page (and later by the class board). */
    public function buildStatePayload(array $session, ?array $attempt = null): array
    {
        $now = time();
        $payload = [
            'state' => $session['state'],
            'server_now' => $now,
            'remaining_seconds' => null,
        ];

        if ($session['state'] === 'running' && !empty($session['started_at'])) {
            $endsAt = $this->toTimestamp($session['started_at']) + ((int)$session['duration_minutes'] * 60);
            $payload['remaining_seconds'] = max(0, $endsAt - $now);
        }

        if ($attempt !== null) {
            $payload['incident_count'] = (int)$attempt['incident_count'];
            $payload['max_incidents'] = (int)$session['max_incidents'];
            $payload['attempt_status'] = $attempt['status'];
            $payload['finished'] = !empty($attempt['finished_at']);
            // The form URL is only delivered once the quiz is actually running.
            if ($session['state'] === 'running') {
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

    public function exportCsv(int $sessionId): string
    {
        $attempts = $this->listAttempts($sessionId);
        $events = $this->listEvents($sessionId);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['type', 'nom', 'prenom', 'code', 'token', 'statut', 'incidents', 'rejoint_a', 'dernier_heartbeat', 'evenement', 'duree_absence_s', 'date'], ';');
        foreach ($attempts as $a) {
            fputcsv($out, ['tentative', $a['last_name'], $a['first_name'], $a['code'], $a['public_token'], $a['status'], $a['incident_count'], $this->toParisTime($a['started_at']), $this->toParisTime($a['last_heartbeat_at']), '', '', ''], ';');
        }
        foreach ($events as $e) {
            fputcsv($out, ['evenement', $e['last_name'], $e['first_name'], '', '', '', '', '', '', $e['event_type'] . ($e['is_incident'] ? (!empty($e['excused']) ? ' (incident excusé)' : ' (incident)') : ''), $e['away_seconds'], $this->toParisTime($e['created_at'])], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);
        return $csv;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function requireSession(int $id): array
    {
        $session = $this->getSession($id);
        if ($session === null) {
            throw new RuntimeException('session_not_found');
        }
        return $session;
    }

    private function insertEvent(int $attemptId, string $type, int $awaySeconds, bool $isIncident): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO quiz_events (attempt_id, event_type, away_seconds, is_incident, created_at)
             VALUES (:aid, :type, :away, :incident, :now)'
        );
        $stmt->execute([
            'aid' => $attemptId,
            'type' => $type,
            'away' => $awaySeconds,
            'incident' => $isIncident ? 1 : 0,
            'now' => $this->now(),
        ]);
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

    private function now(): string
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
