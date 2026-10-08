<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\I18nService;
use App\Services\QuizAdminAuthService;
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
    private QuizService $quiz;
    private QuizAdminAuthService $auth;
    private I18nService $i18n;
    private array $config;
    private ?array $currentAdmin = null;

    public function __construct(QuizService $quiz, QuizAdminAuthService $auth, I18nService $i18n, array $config)
    {
        $this->quiz = $quiz;
        $this->auth = $auth;
        $this->i18n = $i18n;
        $this->config = $config;
    }

    public function handle(string $subPath): void
    {
        header('Cache-Control: no-store');
        $subPath = '/' . trim($subPath, '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            $this->auth->bootstrapFromConfig($this->config['branding']);

            if (!$this->auth->hasAdmins()) {
                http_response_code(503);
                echo 'Quiz admin is not configured.';
                return;
            }

            if ($subPath === '/login' && $method === 'POST') {
                $this->assertCsrf();
                $this->login();
                return;
            }
            if ($subPath === '/logout') {
                $this->auth->logout();
                header('Location: /quiz-admin');
                exit;
            }

            $this->currentAdmin = $this->auth->currentAdmin();
            if ($this->currentAdmin === null) {
                // API routes must answer with JSON, never the HTML login page:
                // an auto-refreshing page (board, session) can then detect the
                // lost session instead of silently parsing login markup as JSON.
                if (strpos($subPath, '/api/') === 0) {
                    http_response_code(401);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => 'auth_required']);
                    return;
                }
                render('quiz/admin/login', ['error' => '', 'csrfToken' => $this->csrfToken()], $this->i18n, $this->config);
                return;
            }
            $this->quiz->setAdminContext($this->currentAdmin);

            if ($subPath === '/change-password' && $method === 'POST') {
                $this->assertCsrf();
                $this->changePassword();
                return;
            }
            if (!empty($this->currentAdmin['must_change_password'])) {
                render('quiz/admin/change-password', [
                    'admin' => $this->currentAdmin,
                    'error' => '',
                    'csrfToken' => $this->csrfToken(),
                ], $this->i18n, $this->config);
                return;
            }
            if ($method === 'POST') {
                $this->assertCsrf();
            }

            if ($subPath === '/' && $method === 'GET') {
                $this->index();
            } elseif ($subPath === '/users' && $method === 'GET') {
                $this->users();
            } elseif ($subPath === '/users/create' && $method === 'POST') {
                $this->createUser();
            } elseif ($subPath === '/users/status' && $method === 'POST') {
                $this->setUserStatus();
            } elseif ($subPath === '/users/password' && $method === 'POST') {
                $this->resetUserPassword();
            } elseif ($subPath === '/users/transfer' && $method === 'POST') {
                $this->transferUserSessions();
            } elseif ($subPath === '/session/transfer' && $method === 'POST') {
                $this->transferSession();
            } elseif ($subPath === '/create' && $method === 'POST') {
                $this->create();
            } elseif ($subPath === '/session' && $method === 'GET') {
                $this->sessionDetail();
            } elseif ($subPath === '/attempt' && $method === 'GET') {
                $this->attemptDetail();
            } elseif ($subPath === '/history' && $method === 'GET') {
                $this->history();
            } elseif ($subPath === '/archive' && $method === 'GET') {
                $this->archiveReport();
            } elseif ($subPath === '/archive/export' && $method === 'GET') {
                $this->archiveExport();
            } elseif ($subPath === '/event/excuse' && $method === 'POST') {
                $this->excuseEvent();
            } elseif ($subPath === '/attempt/excuse-all' && $method === 'POST') {
                $this->excuseAttempt();
            } elseif ($subPath === '/session/reset' && $method === 'POST') {
                $this->resetSession();
            } elseif (in_array($subPath, ['/access/block', '/access/lift'], true) && $method === 'POST') {
                $this->changeManualAccess($subPath === '/access/block');
            } elseif (in_array($subPath, ['/access/override/grant', '/access/override/revoke'], true) && $method === 'POST') {
                $this->changeTechnicalOverride($subPath === '/access/override/grant');
            } elseif ($subPath === '/tracking/mode' && $method === 'POST') {
                $this->changeTrackingMode();
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
            } elseif ($subPath === '/api/board' && $method === 'GET') {
                $this->apiBoard();
            } elseif ($subPath === '/api/board/events' && $method === 'GET') {
                $this->apiEvents(true);
            } else {
                http_response_code(404);
                echo 'Not found';
            }
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException && $e->getMessage() === 'resource_not_found') {
                http_response_code(404);
                echo 'Not found';
                return;
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'invalid_csrf_token') {
                http_response_code(403);
                echo 'Invalid request token.';
                return;
            }
            http_response_code(500);
            echo 'Erreur interne.';
            error_log('Quiz admin error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    // ------------------------------------------------------------------

    private function login(): void
    {
        $email = (string)($_POST['admin_email'] ?? '');
        $password = (string)($_POST['admin_password'] ?? '');
        if ($this->auth->authenticate($email, $password)) {
            header('Location: /quiz-admin');
            exit;
        }
        render('quiz/admin/login', [
            'error' => $this->i18n->t('auth.invalid_password'),
            'csrfToken' => $this->csrfToken(),
        ], $this->i18n, $this->config);
    }

    private function index(string $flash = '', array $old = []): void
    {
        render('quiz/admin/index', [
            'sessions' => $this->quiz->listSessions(
                $this->isSuperAdmin() && (string)($_GET['scope'] ?? '') === 'all'
            ),
            'admin' => $this->currentAdmin,
            'showAll' => $this->isSuperAdmin() && (string)($_GET['scope'] ?? '') === 'all',
            'csrfToken' => $this->csrfToken(),
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
        $studentAccess = [];
        foreach ($this->quiz->listStudents((int)$session['id']) as $student) {
            $studentAccess[(int)$student['id']] = $this->quiz->getStudentAccess((int)$session['id'], (int)$student['id']);
        }
        render('quiz/admin/session', [
            'studentAccess' => $studentAccess,
            'session' => $session,
            'students' => $this->quiz->listStudents((int)$session['id']),
            'attempts' => $this->quiz->listAttempts((int)$session['id']),
            'recentEvents' => array_slice($this->quiz->listEvents((int)$session['id']), 0, 30),
            'rulesVersion' => $this->quiz->rulesVersion($session),
            'flash' => (string)($_GET['flash'] ?? ''),
            'admin' => $this->currentAdmin,
            'admins' => $this->isSuperAdmin() ? $this->auth->listAdmins() : [],
            'csrfToken' => $this->csrfToken(),
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
            'accessContext' => $this->quiz->getStudentAccess((int)$session['id'], (int)$attempt['student_id']),
            'session' => $session,
            'attempt' => $attempt,
            'events' => $this->quiz->listEventsForAttempt($id),
            'trackingContexts' => $this->quiz->getTrackingContexts((int)$session['id'],$id,(int)($_GET['tracking_before']??0)),
            'csrfToken' => $this->csrfToken(),
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
            try {
                $this->quiz->importRoster($id, $this->rosterInput());
            } catch (RuntimeException $e) {
                header('Location: /quiz-admin/session?id=' . $id . '&flash=' . rawurlencode($e->getMessage()));
                return;
            }
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
        $filename = 'quiz-' . $session['slug'] . '-' . gmdate('Ymd-His') . '.csv';
        $this->csvDownload($filename, function ($out) use ($session): void { $this->quiz->writeExportCsv((int)$session['id'], $out); });
    }

    private function csvDownload(string $filename, callable $writer): void
    {
        $out = fopen('php://temp', 'w+');
        try {
            $writer($out); // complete before headers: failures never deliver a partial CSV
            rewind($out);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "\xEF\xBB\xBF";
            fpassthru($out);
        } finally { fclose($out); }
    }

    private function history(): void
    {
        $session = $this->requireSessionFromQuery();
        $attemptFilter = (int)($_GET['attempt_id'] ?? 0);
        render('quiz/admin/history', [
            'session' => $session, 'attemptFilter' => $attemptFilter,
            'archives' => $this->quiz->listArchives((int)$session['id'], (int)($_GET['before'] ?? 0), 25, $attemptFilter > 0 ? $attemptFilter : null),
            'audit' => $this->quiz->listAudit((int)$session['id'], (int)($_GET['audit_before'] ?? 0)),
            'technicalOverrides' => $this->quiz->listTechnicalOverrides((int)$session['id'], null, (int)($_GET['override_before'] ?? 0)),
            'trackingDiagnostics' => $this->quiz->listTrackingDiagnostics((int)$session['id'],(int)($_GET['tracking_before']??0)),
        ], $this->i18n, $this->config);
    }

    private function archiveReport(): void
    {
        $archive = $this->quiz->getArchive((int)($_GET['id'] ?? 0));
        if ($archive === null) { throw new RuntimeException('resource_not_found'); }
        $session = $archive['snapshot']['rules'];
        $attempt = $archive['snapshot']['attempt'];
        $events = array_reverse($archive['snapshot']['events']);
        $accessContext = $archive['snapshot']['access_context'] ?? null;
        $trackingPolicy = $archive['snapshot']['tracking_policy'] ?? null;
        $trackingContexts = isset($archive['snapshot']['tracking_contexts']) ? ['rows' => $archive['snapshot']['tracking_contexts'], 'next_before' => null] : null;
        $i18n = $this->i18n;
        include __DIR__ . '/../Views/quiz/admin/report.php';
    }

    private function archiveExport(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $this->csvDownload('quiz-archive-' . $id . '.csv', function ($out) use ($id): void { $this->quiz->writeArchiveCsv($id, $out); });
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

    /** Preserve the complete current history before resetting student statuses. */
    private function resetSession(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $flash = '';
        try {
            $this->quiz->resetAndRelaunch($id);
            $flash = $this->i18n->t('quiz.admin.reset_done');
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException && $e->getMessage() === 'resource_not_found') { throw $e; }
            error_log(sprintf('Quiz reset failed: session_id=%d; exception=%s; message=%s; file=%s:%d',
                $id, get_class($e), str_replace(["\r", "\n"], ' ', mb_substr($e->getMessage(), 0, 512)), $e->getFile(), $e->getLine()));
            $flash = $this->i18n->t(str_starts_with($e->getMessage(), 'archive_') ? 'quiz.history.reset_limit' : 'quiz.history.reset_failed');
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
        $accessContext = $this->quiz->getStudentAccess((int)$session['id'], (int)$attempt['student_id']);
        $trackingPolicy = ['mode' => $session['tracking_mode'], 'settings_revision' => (int)$session['settings_revision']];
        $trackingContexts = $this->quiz->getTrackingContexts((int)$session['id'], $id, (int)($_GET['tracking_before'] ?? 0));
        $i18n = $this->i18n;
        // Standalone printable page, outside the site layout
        include __DIR__ . '/../Views/quiz/admin/report.php';
    }

    /** Fullscreen classroom board, projected during the exam (auto-refreshing). */
    private function board(): void
    {
        $session = $this->requireSessionFromQuery();
        $students = $this->quiz->listStudents((int)$session['id']);
        $students = array_map(fn(array $student): array => ['id' => (int)$student['id'], 'first_name' => $student['first_name'], 'last_name' => $student['last_name'],
            'access_allowed' => $this->quiz->projectedAccessAllowed((int)$session['id'], (int)$student['id'])], $students);
        $i18n = $this->i18n;
        // Standalone fullscreen page, outside the site layout
        include __DIR__ . '/../Views/quiz/admin/board.php';
    }

    /** Live feed: events newer than ?after=<id>, oldest first. */
    private function apiEvents(bool $projected = false): void
    {
        $session = $this->requireSessionFromQuery();
        $after = (int)($_GET['after'] ?? 0);
        $version = $this->quiz->rulesVersion($session);
        $revision = (int)$session['history_revision'];
        $requestedVersion = (string)($_GET['rules_version'] ?? '');
        $requestedRevision = $_GET['history_revision'] ?? null;
        $reset = ($requestedVersion !== '' && !hash_equals($version, $requestedVersion))
            || ($requestedRevision !== null && (int)$requestedRevision !== $revision);
        $events = $reset
            ? $this->quiz->listRecentEvents((int)$session['id'], 100, $projected)
            : $this->quiz->listEventsSince((int)$session['id'], $after, 100, $projected);

        $rows = array_map(function (array $e): array {
            return [
                'id' => (int)$e['id'],
                'student_id' => (int)($e['student_id'] ?? 0),
                'first_name' => $e['first_name'],
                'last_name' => $e['last_name'],
                'event_type' => $e['event_type'],
                'event_label' => quizEventLabel($e, $this->i18n),
                'away_seconds' => (int)$e['away_seconds'],
                'duration_ms' => $e['duration_ms'] !== null ? (int)$e['duration_ms'] : null,
                'duration_label' => quizEventDuration($e),
                'source' => $e['source'],
                'absence_uid' => $e['absence_uid'],
                'event_uid' => $e['event_uid'],
                'related_event_uid' => $e['related_event_uid'],
                'dropped_events' => $e['dropped_events'] !== null ? (int)$e['dropped_events'] : null,
                'is_incident' => (int)$e['is_incident'] === 1,
                'excused' => !empty($e['excused']),
                'date' => formatParisTime($e['created_at']),
            ];
        }, $events);

        if ($projected) {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['event_type'] !== 'tracking_diagnostic'));
            $rows = array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['id', 'student_id', 'first_name', 'last_name', 'event_type', 'away_seconds', 'duration_ms', 'duration_label', 'is_incident', 'excused', 'date'])), $rows);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['events' => $rows, 'rules_version' => $version, 'history_revision' => $revision, 'reset' => $reset]);
    }

    private function apiAttempts(): void
    {
        $session = $this->requireSessionFromQuery();
        // This private poll observes expiry even when the student's JavaScript has stopped.
        $this->quiz->refreshTrackingProofs((int)$session['id']);
        $attempts = $this->quiz->listAttempts((int)$session['id']);
        $state = $this->quiz->buildStatePayload($session);
        $now = $state['server_now'];

        $rows = array_map(function (array $a) use ($now, $session): array {
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
                'access_context' => $this->quiz->getStudentAccess((int)$session['id'], (int)$a['student_id']),
            ];
        }, $attempts);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge($state, [
            'pin' => $session['access_pin'] ?? null,
            'attempts' => $rows,
        ]));
    }

    private function changeManualAccess(bool $blocked): void
    {
        foreach (['id', 'student_id'] as $field) {
            $value = $_POST[$field] ?? null;
            if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int)$value < 1) {
                http_response_code(400);
                echo htmlspecialchars($this->i18n->t('quiz.access.invalid'), ENT_QUOTES, 'UTF-8');
                return;
            }
        }
        $id = (int)($_POST['id'] ?? 0);
        $studentId = (int)($_POST['student_id'] ?? 0);
        try {
            if (!is_string($_POST['reason'] ?? null)) { throw new RuntimeException('invalid_access_reason'); }
            $this->quiz->setManualAccess($id, $studentId, $blocked, $_POST['reason']);
            $flash = $this->i18n->t('quiz.access.saved');
        } catch (RuntimeException $e) {
            if (!in_array($e->getMessage(), ['invalid_access_reason', 'access_unchanged'], true)) { throw $e; }
            $flash = $this->i18n->t('quiz.access.invalid');
        }
        header('Location: /quiz-admin/session?id=' . $id . '&flash=' . urlencode($flash));
        exit;
    }

    /** Deliberate allowlist for the projected board; never serialize teacher policy details. */
    private function apiBoard(): void
    {
        $session = $this->requireSessionFromQuery();
        $state = $this->quiz->buildStatePayload($session);
        $state = array_intersect_key($state, array_flip(['state', 'title', 'duration_minutes', 'max_incidents', 'min_away_seconds', 'require_fullscreen', 'reload_is_incident', 'rules_version', 'server_now', 'remaining_seconds', 'history_revision']));
        $attemptMap = [];
        foreach ($this->quiz->listAttempts((int)$session['id']) as $attempt) { $attemptMap[(int)$attempt['student_id']] = $attempt; }
        $rows = [];
        foreach ($this->quiz->listStudents((int)$session['id']) as $student) {
            $attempt = $attemptMap[(int)$student['id']] ?? null;
            $last = !empty($attempt['last_heartbeat_at']) ? strtotime($attempt['last_heartbeat_at'] . ' UTC') : null;
            $rows[] = ['student_id' => (int)$student['id'], 'attempt_id' => $attempt !== null ? (int)$attempt['id'] : null,
                'first_name' => $student['first_name'], 'last_name' => $student['last_name'],
                'incident_count' => (int)($attempt['incident_count'] ?? 0), 'status' => $attempt['status'] ?? 'waiting',
                'finished' => !empty($attempt['finished_at']), 'connected' => $last !== null && $state['server_now'] - $last < 30,
                'access_allowed' => $this->quiz->projectedAccessAllowed((int)$session['id'], (int)$student['id'])];
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge($state, ['pin' => $session['access_pin'] ?? null, 'attempts' => $rows]));
    }

    private function changeTechnicalOverride(bool $grant): void
    {
        foreach ($grant ? ['id', 'student_id'] : ['id', 'student_id', 'override_id'] as $field) {
            $value = $_POST[$field] ?? null;
            if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int)$value < 1) {
                http_response_code(400); echo htmlspecialchars($this->i18n->t('quiz.override.invalid'), ENT_QUOTES, 'UTF-8'); return;
            }
        }
        $sid = (int)$_POST['id']; $studentId = (int)$_POST['student_id'];
        try {
            if (!is_string($_POST['generation'] ?? null) || !is_string($_POST['reason'] ?? null)) { throw new RuntimeException('invalid_access_reason'); }
            if ($grant) {
                if (!is_array($_POST['scopes'] ?? null)) { throw new RuntimeException('invalid_override_scopes'); }
                $this->quiz->grantTechnicalOverride($sid, $studentId, $_POST['generation'], $_POST['scopes'], $_POST['reason']);
            } else {
                $this->quiz->revokeTechnicalOverride($sid, $studentId, (int)$_POST['override_id'], $_POST['generation'], $_POST['reason']);
            }
            $flash = $this->i18n->t('quiz.override.saved');
        } catch (RuntimeException $e) {
            if (!in_array($e->getMessage(), ['invalid_access_reason', 'invalid_override_scopes', 'invalid_override_state', 'stale_generation', 'override_exists', 'override_not_active'], true)) { throw $e; }
            $flash = $this->i18n->t('quiz.override.invalid');
        }
        header('Location: /quiz-admin/session?id=' . $sid . '&flash=' . urlencode($flash));
        exit;
    }

    private function changeTrackingMode(): void
    {
        $sid=$_POST['id']??null;$revision=$_POST['settings_revision']??null;
        if(!is_string($sid)||!ctype_digit($sid)||(int)$sid<1||!is_string($revision)||!ctype_digit($revision)||!is_string($_POST['mode']??null)||!is_string($_POST['reason']??null)){http_response_code(400);echo htmlspecialchars($this->i18n->t('quiz.tracking.invalid'),ENT_QUOTES,'UTF-8');return;}
        try{$this->quiz->setTrackingMode((int)$sid,$_POST['mode'],(int)$revision,$_POST['reason']);$flash=$this->i18n->t('quiz.tracking.saved');}
        catch(RuntimeException $e){if(!in_array($e->getMessage(),['invalid_tracking_mode','invalid_access_reason','settings_revision_mismatch','tracking_mode_unchanged'],true)){throw $e;}$flash=$this->i18n->t('quiz.tracking.invalid');}
        header('Location: /quiz-admin/session?id='.(int)$sid.'&flash='.urlencode($flash));exit;
    }

    private function users(): void
    {
        $this->requireSuperAdmin();
        render('quiz/admin/users', [
            'admin' => $this->currentAdmin,
            'admins' => $this->auth->listAdmins(),
            'flash' => (string)($_GET['flash'] ?? ''),
            'csrfToken' => $this->csrfToken(),
        ], $this->i18n, $this->config);
    }

    private function createUser(): void
    {
        $this->requireSuperAdmin();
        try {
            $this->auth->createAdmin(
                (string)($_POST['email'] ?? ''),
                (string)($_POST['display_name'] ?? ''),
                (string)($_POST['password'] ?? ''),
                (string)($_POST['role'] ?? QuizAdminAuthService::ROLE_QUIZ_ADMIN),
                true
            );
            $flash = 'Compte administrateur créé.';
        } catch (Throwable $e) {
            $flash = 'Création impossible : ' . $e->getMessage();
        }
        $this->redirectUsers($flash);
    }

    private function setUserStatus(): void
    {
        $this->requireSuperAdmin();
        $id = (int)($_POST['admin_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        try {
            if ($id === (int)$this->currentAdmin['id'] && $status === QuizAdminAuthService::STATUS_DISABLED) {
                throw new RuntimeException('self_disable_forbidden');
            }
            if ($status === QuizAdminAuthService::STATUS_DISABLED) {
                foreach ($this->quiz->listSessions(true) as $session) {
                    if ((int)$session['owner_admin_id'] === $id) {
                        throw new RuntimeException('transfer_sessions_first');
                    }
                }
                $target = $this->auth->findAdmin($id);
                if ($target !== null && $target['role'] === QuizAdminAuthService::ROLE_SUPER_ADMIN) {
                    $activeSupers = array_filter($this->auth->listAdmins(), static function (array $admin): bool {
                        return $admin['role'] === QuizAdminAuthService::ROLE_SUPER_ADMIN
                            && $admin['status'] === QuizAdminAuthService::STATUS_ACTIVE;
                    });
                    if (count($activeSupers) <= 1) {
                        throw new RuntimeException('last_super_admin');
                    }
                }
            }
            $this->auth->setStatus($id, $status);
            $flash = 'Statut du compte mis à jour.';
        } catch (Throwable $e) {
            $flash = 'Modification impossible : ' . $e->getMessage();
        }
        $this->redirectUsers($flash);
    }

    private function resetUserPassword(): void
    {
        $this->requireSuperAdmin();
        try {
            $this->auth->resetPassword(
                (int)($_POST['admin_id'] ?? 0),
                (string)($_POST['password'] ?? ''),
                true
            );
            $flash = 'Mot de passe temporaire enregistré.';
        } catch (Throwable $e) {
            $flash = 'Réinitialisation impossible : ' . $e->getMessage();
        }
        $this->redirectUsers($flash);
    }

    private function transferUserSessions(): void
    {
        $this->requireSuperAdmin();
        try {
            $count = $this->auth->transferSessionsOwnership(
                (int)($_POST['from_admin_id'] ?? 0),
                (int)($_POST['to_admin_id'] ?? 0)
            );
            $flash = $count . ' quiz transféré(s).';
        } catch (Throwable $e) {
            $flash = 'Transfert impossible : ' . $e->getMessage();
        }
        $this->redirectUsers($flash);
    }

    private function transferSession(): void
    {
        $this->requireSuperAdmin();
        $id = (int)($_POST['id'] ?? 0);
        try {
            $this->quiz->transferSession($id, (int)($_POST['owner_admin_id'] ?? 0));
            $flash = 'Propriétaire du quiz mis à jour.';
        } catch (Throwable $e) {
            $flash = 'Transfert impossible : ' . $e->getMessage();
        }
        header('Location: /quiz-admin/session?id=' . $id . '&flash=' . urlencode($flash));
        exit;
    }

    private function changePassword(): void
    {
        $oldPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        if (!$this->auth->authenticate((string)$this->currentAdmin['email'], $oldPassword)) {
            render('quiz/admin/change-password', [
                'admin' => $this->currentAdmin,
                'error' => 'Mot de passe actuel incorrect.',
                'csrfToken' => $this->csrfToken(),
            ], $this->i18n, $this->config);
            return;
        }
        try {
            $this->auth->resetPassword((int)$this->currentAdmin['id'], $newPassword, false);
        } catch (Throwable $e) {
            render('quiz/admin/change-password', [
                'admin' => $this->currentAdmin,
                'error' => 'Le nouveau mot de passe doit contenir au moins 12 caractères.',
                'csrfToken' => $this->csrfToken(),
            ], $this->i18n, $this->config);
            return;
        }
        header('Location: /quiz-admin');
        exit;
    }

    private function isSuperAdmin(): bool
    {
        return $this->currentAdmin !== null
            && $this->currentAdmin['role'] === QuizAdminAuthService::ROLE_SUPER_ADMIN;
    }

    private function requireSuperAdmin(): void
    {
        if (!$this->isSuperAdmin()) {
            throw new RuntimeException('resource_not_found');
        }
    }

    private function redirectUsers(string $flash): void
    {
        header('Location: /quiz-admin/users?flash=' . urlencode($flash));
        exit;
    }

    private function csrfToken(): string
    {
        if (!isset($_SESSION['quiz_admin_csrf']) || !is_string($_SESSION['quiz_admin_csrf'])) {
            $_SESSION['quiz_admin_csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['quiz_admin_csrf'];
    }

    private function assertCsrf(): void
    {
        $given = (string)($_POST['_csrf'] ?? '');
        if ($given === '' || !hash_equals($this->csrfToken(), $given)) {
            throw new RuntimeException('invalid_csrf_token');
        }
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
