<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\I18nService;
use App\Services\QuizService;
use RuntimeException;
use Throwable;

/**
 * Teacher dashboard for the quiz module.
 * Protected by a dedicated password (branding 'quiz_admin_password'),
 * independent from the public library password — same pattern as /sync.
 */
class QuizAdminController
{
    private const SESSION_KEY = 'quiz_admin_authenticated';

    private QuizService $quiz;
    private I18nService $i18n;
    private array $config;

    public function __construct(QuizService $quiz, I18nService $i18n, array $config)
    {
        $this->quiz = $quiz;
        $this->i18n = $i18n;
        $this->config = $config;
    }

    public function handle(string $subPath): void
    {
        $subPath = '/' . trim($subPath, '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            $password = (string)($this->config['branding']['quiz_admin_password'] ?? '');
            if ($password === '') {
                http_response_code(503);
                echo 'Quiz admin is not configured. Set quiz_admin_password in branding.php.';
                return;
            }

            if ($subPath === '/login' && $method === 'POST') {
                $this->login($password);
                return;
            }
            if ($subPath === '/logout') {
                unset($_SESSION[self::SESSION_KEY]);
                header('Location: /quiz-admin');
                exit;
            }

            if (empty($_SESSION[self::SESSION_KEY])) {
                // API routes must answer with JSON, never the HTML login page:
                // an auto-refreshing page (board, session) can then detect the
                // lost session instead of silently parsing login markup as JSON.
                if (strpos($subPath, '/api/') === 0) {
                    http_response_code(401);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => 'auth_required']);
                    return;
                }
                render('quiz/admin/login', ['error' => ''], $this->i18n, $this->config);
                return;
            }

            if ($subPath === '/' && $method === 'GET') {
                $this->index();
            } elseif ($subPath === '/create' && $method === 'POST') {
                $this->create();
            } elseif ($subPath === '/session' && $method === 'GET') {
                $this->sessionDetail();
            } elseif ($subPath === '/attempt' && $method === 'GET') {
                $this->attemptDetail();
            } elseif ($subPath === '/event/excuse' && $method === 'POST') {
                $this->excuseEvent();
            } elseif ($subPath === '/attempt/excuse-all' && $method === 'POST') {
                $this->excuseAttempt();
            } elseif ($subPath === '/session/reset' && $method === 'POST') {
                $this->resetSession();
            } elseif ($subPath === '/report' && $method === 'GET') {
                $this->integrityReport();
            } elseif ($subPath === '/board' && $method === 'GET') {
                $this->board();
            } elseif (in_array($subPath, ['/session/open', '/session/launch', '/session/stop', '/session/close'], true) && $method === 'POST') {
                $this->changeState(basename($subPath));
            } elseif ($subPath === '/session/update' && $method === 'POST') {
                $this->updateSession();
            } elseif ($subPath === '/email-codes' && $method === 'POST') {
                $this->emailCodes();
            } elseif ($subPath === '/roster' && $method === 'POST') {
                $this->addRoster();
            } elseif ($subPath === '/students/update' && $method === 'POST') {
                $this->updateStudents();
            } elseif ($subPath === '/students/delete' && $method === 'POST') {
                $this->deleteStudent();
            } elseif ($subPath === '/codes' && $method === 'GET') {
                $this->printableCodes();
            } elseif ($subPath === '/export' && $method === 'GET') {
                $this->exportCsv();
            } elseif ($subPath === '/api/attempts' && $method === 'GET') {
                $this->apiAttempts();
            } elseif ($subPath === '/api/events' && $method === 'GET') {
                $this->apiEvents();
            } else {
                http_response_code(404);
                echo 'Not found';
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Erreur interne.';
            error_log('Quiz admin error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    // ------------------------------------------------------------------

    private function login(string $password): void
    {
        $given = (string)($_POST['admin_password'] ?? '');
        if (hash_equals($password, $given)) {
            $_SESSION[self::SESSION_KEY] = true;
            header('Location: /quiz-admin');
            exit;
        }
        render('quiz/admin/login', [
            'error' => $this->i18n->t('auth.invalid_password'),
        ], $this->i18n, $this->config);
    }

    private function index(string $flash = '', array $old = []): void
    {
        render('quiz/admin/index', [
            'sessions' => $this->quiz->listSessions(),
            'flash' => $flash !== '' ? $flash : (string)($_GET['flash'] ?? ''),
            'old' => $old,
        ], $this->i18n, $this->config);
    }

    private function create(): void
    {
        try {
            $result = $this->quiz->createSession($_POST, $this->rosterInput());
        } catch (RuntimeException $e) {
            // Keep the teacher's input so a single bad field does not wipe the form
            $this->index($this->i18n->t('quiz.admin.create_error') . ' (' . $e->getMessage() . ')', $_POST);
            return;
        }
        header('Location: /quiz-admin/session?id=' . $result['id']);
        exit;
    }

    /** Roster text from the textarea, plus the content of an uploaded CSV file if any. */
    private function rosterInput(): string
    {
        $text = (string)($_POST['roster'] ?? '');
        $tmp = $_FILES['roster_file']['tmp_name'] ?? '';
        if (is_string($tmp) && $tmp !== '' && is_uploaded_file($tmp)) {
            $text .= "\n" . (string)file_get_contents($tmp);
        }
        return $text;
    }

    private function sessionDetail(): void
    {
        $session = $this->requireSessionFromQuery();
        render('quiz/admin/session', [
            'session' => $session,
            'students' => $this->quiz->listStudents((int)$session['id']),
            'attempts' => $this->quiz->listAttempts((int)$session['id']),
            'recentEvents' => array_slice($this->quiz->listEvents((int)$session['id']), 0, 30),
            'flash' => (string)($_GET['flash'] ?? ''),
        ], $this->i18n, $this->config);
    }

    /** Edits the session rules, allowed in any state (even while running). */
    private function updateSession(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $this->quiz->updateSession($id, $_POST);
            $flash = $this->i18n->t('quiz.admin.edit_saved');
        } catch (RuntimeException $e) {
            $flash = $this->i18n->t('quiz.admin.edit_error') . ' (' . $e->getMessage() . ')';
        }
        header('Location: /quiz-admin/session?id=' . $id . '&flash=' . urlencode($flash));
        exit;
    }

    /** Emails each student (with a known address) their personal code. */
    private function emailCodes(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $session = $this->quiz->getSession($id);
        if ($session === null) {
            http_response_code(404);
            echo 'Session not found';
            return;
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $siteName = (string)($this->config['branding']['site_name'] ?? '');
        $smtp = $this->config['branding']['quiz_smtp'] ?? null;

        if (is_array($smtp) && !empty($smtp['username'])) {
            // Authenticated SMTP: the From must be the authenticated mailbox
            // so the provider (SPF/DKIM) accepts the message.
            $from = (string)($smtp['from'] ?? $smtp['username']);
            $fromName = (string)($smtp['from_name'] ?? $siteName);
        } else {
            $smtp = null;
            $from = (string)($this->config['branding']['quiz_mail_from'] ?? '');
            if ($from === '') {
                $from = 'no-reply@' . preg_replace('/:\d+$/', '', $host);
            }
            $fromName = $siteName;
        }
        $joinUrl = 'https://' . $host . '/quiz';

        $result = $this->quiz->emailCodes(
            $id,
            $this->i18n->t('quiz.email.subject'),
            $this->i18n->t('quiz.email.body'),
            $from,
            $fromName,
            $joinUrl,
            $smtp
        );

        $flash = sprintf(
            $this->i18n->t('quiz.admin.email_result'),
            $result['sent'],
            $result['no_email'],
            $result['failed']
        );
        header('Location: /quiz-admin/session?id=' . $id . '&flash=' . urlencode($flash));
        exit;
    }

    /** Dedicated page: every monitoring event of one student's attempt. */
    private function attemptDetail(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $attempt = $this->quiz->getAttempt($id);
        $session = $attempt !== null ? $this->quiz->getSession((int)$attempt['session_id']) : null;
        if ($attempt === null || $session === null) {
            http_response_code(404);
            echo 'Attempt not found';
            return;
        }
        render('quiz/admin/attempt', [
            'session' => $session,
            'attempt' => $attempt,
            'events' => $this->quiz->listEventsForAttempt($id),
        ], $this->i18n, $this->config);
    }

    private function changeState(string $action): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $session = $this->quiz->getSession($id);
        if ($session === null) {
            http_response_code(404);
            echo 'Session not found';
            return;
        }

        try {
            if ($action === 'open') {
                $this->quiz->openLobby($id);
            } elseif ($action === 'launch') {
                $this->quiz->launch($id);
            } elseif ($action === 'stop') {
                $this->quiz->stop($id);
            } else {
                $this->quiz->close($id);
            }
        } catch (RuntimeException $e) {
            // Invalid transition (e.g. double click): just go back to the page
        }

        header('Location: /quiz-admin/session?id=' . $id);
        exit;
    }

    private function addRoster(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $session = $this->quiz->getSession($id);
        if ($session !== null) {
            $this->quiz->importRoster($id, $this->rosterInput());
        }
        header('Location: /quiz-admin/session?id=' . $id);
        exit;
    }

    /** Saves every row of the student table in one go. */
    private function updateStudents(): void
    {
        $sessionId = (int)($_POST['id'] ?? 0);
        $rows = $_POST['students'] ?? [];
        $result = $this->quiz->updateStudents($sessionId, is_array($rows) ? $rows : []);

        $flash = sprintf($this->i18n->t('quiz.admin.students_saved'), $result['updated']);
        if ($result['errors'] > 0) {
            $flash .= ' ' . sprintf($this->i18n->t('quiz.admin.students_errors'), $result['errors']);
        }
        header('Location: /quiz-admin/session?id=' . $sessionId . '&flash=' . urlencode($flash));
        exit;
    }

    /** Removes one student (and their attempt + events) from the session. */
    private function deleteStudent(): void
    {
        $sessionId = (int)($_POST['id'] ?? 0);
        $studentId = (int)($_POST['student_id'] ?? 0);
        $name = $this->quiz->deleteStudent($studentId, $sessionId);
        $flash = $name !== null ? sprintf($this->i18n->t('quiz.admin.student_deleted'), $name) : '';
        header('Location: /quiz-admin/session?id=' . $sessionId . ($flash !== '' ? '&flash=' . urlencode($flash) : ''));
        exit;
    }

    private function printableCodes(): void
    {
        $session = $this->requireSessionFromQuery();
        $students = $this->quiz->listStudents((int)$session['id']);
        $i18n = $this->i18n;
        // Standalone printable page, outside the site layout
        include __DIR__ . '/../Views/quiz/admin/codes.php';
    }

    private function exportCsv(): void
    {
        $session = $this->requireSessionFromQuery();
        $csv = $this->quiz->exportCsv((int)$session['id']);
        $filename = 'quiz-' . $session['slug'] . '-' . gmdate('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        // BOM so Excel opens UTF-8 accents correctly
        echo "\xEF\xBB\xBF" . $csv;
    }

    /** Teacher arbitration: excuse or reinstate an incident, then back to the attempt page. */
    private function excuseEvent(): void
    {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $excused = (string)($_POST['excused'] ?? '1') === '1';
        $attemptId = $this->quiz->setEventExcused($eventId, $excused);
        if ($attemptId === null) {
            $attemptId = (int)($_POST['attempt_id'] ?? 0);
        }
        header('Location: /quiz-admin/attempt?id=' . $attemptId);
        exit;
    }

    /** Teacher arbitration, whole-student version: excuses every incident of one attempt. */
    private function excuseAttempt(): void
    {
        $attemptId = (int)($_POST['attempt_id'] ?? 0);
        $this->quiz->excuseAttempt($attemptId);
        header('Location: /quiz-admin/attempt?id=' . $attemptId);
        exit;
    }

    /** Full restart: wipes events, resets every student status, relaunches the timer. */
    private function resetSession(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $flash = '';
        try {
            $this->quiz->resetAndRelaunch($id);
            $flash = $this->i18n->t('quiz.admin.reset_done');
        } catch (RuntimeException $e) {
            // Invalid state (e.g. armed/closed): just go back to the page
        }
        header('Location: /quiz-admin/session?id=' . $id . ($flash !== '' ? '&flash=' . urlencode($flash) : ''));
        exit;
    }

    /** Standalone printable integrity report for one attempt. */
    private function integrityReport(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $attempt = $this->quiz->getAttempt($id);
        $session = $attempt !== null ? $this->quiz->getSession((int)$attempt['session_id']) : null;
        if ($attempt === null || $session === null) {
            http_response_code(404);
            echo 'Attempt not found';
            return;
        }
        $events = $this->quiz->listEventsForAttempt($id);
        $i18n = $this->i18n;
        // Standalone printable page, outside the site layout
        include __DIR__ . '/../Views/quiz/admin/report.php';
    }

    /** Fullscreen classroom board, projected during the exam (auto-refreshing). */
    private function board(): void
    {
        $session = $this->requireSessionFromQuery();
        $students = $this->quiz->listStudents((int)$session['id']);
        $i18n = $this->i18n;
        // Standalone fullscreen page, outside the site layout
        include __DIR__ . '/../Views/quiz/admin/board.php';
    }

    /** Live feed: events newer than ?after=<id>, oldest first. */
    private function apiEvents(): void
    {
        $session = $this->requireSessionFromQuery();
        $after = (int)($_GET['after'] ?? 0);
        $events = $this->quiz->listEventsSince((int)$session['id'], $after);

        $rows = array_map(static function (array $e): array {
            return [
                'id' => (int)$e['id'],
                'student_id' => (int)($e['student_id'] ?? 0),
                'first_name' => $e['first_name'],
                'last_name' => $e['last_name'],
                'event_type' => $e['event_type'],
                'away_seconds' => (int)$e['away_seconds'],
                'is_incident' => (int)$e['is_incident'] === 1,
                'excused' => !empty($e['excused']),
                'date' => formatParisTime($e['created_at']),
            ];
        }, $events);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['events' => $rows]);
    }

    private function apiAttempts(): void
    {
        $session = $this->requireSessionFromQuery();
        $attempts = $this->quiz->listAttempts((int)$session['id']);
        $now = time();

        $remaining = null;
        if ($session['state'] === 'running' && !empty($session['started_at'])) {
            $endsAt = strtotime($session['started_at'] . ' UTC') + ((int)$session['duration_minutes'] * 60);
            $remaining = max(0, $endsAt - $now);
        }

        $rows = array_map(static function (array $a) use ($now): array {
            $lastHb = $a['last_heartbeat_at'] ? strtotime($a['last_heartbeat_at'] . ' UTC') : null;
            return [
                'attempt_id' => (int)$a['id'],
                'student_id' => (int)$a['student_id'],
                'first_name' => $a['first_name'],
                'last_name' => $a['last_name'],
                'code' => $a['code'],
                'status' => $a['status'],
                'incident_count' => (int)$a['incident_count'],
                'event_count' => (int)$a['event_count'],
                'finished' => !empty($a['finished_at']),
                'connected' => $lastHb !== null && ($now - $lastHb) < 30,
                'last_seen_seconds' => $lastHb !== null ? max(0, $now - $lastHb) : null,
            ];
        }, $attempts);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'state' => $session['state'],
            'max_incidents' => (int)$session['max_incidents'],
            'server_now' => $now,
            'remaining_seconds' => $remaining,
            'pin' => $session['access_pin'] ?? null,
            'attempts' => $rows,
        ]);
    }

    private function requireSessionFromQuery(): array
    {
        $id = (int)($_GET['id'] ?? 0);
        $session = $this->quiz->getSession($id);
        if ($session === null) {
            http_response_code(404);
            echo 'Session not found';
            exit;
        }
        return $session;
    }
}
