<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\I18nService;
use App\Services\QuizService;
use RuntimeException;
use Throwable;

/**
 * Student-facing routes of the quiz module.
 *
 *   GET  /quiz                → PIN + personal code form
 *   POST /quiz/join           → validates and creates/resumes the attempt
 *   GET  /quiz/room           → monitored room (lobby → exam → closed)
 *   GET  /quiz/api/state      → state polled by the room page
 *   POST /quiz/api/heartbeat  → alive ping
 *   POST /quiz/api/event      → monitoring event (alt-tab, etc.)
 */
class QuizController
{
    private const SESSION_ATTEMPT = 'quiz_attempt_id';
    private const SESSION_SESSION = 'quiz_session_id';

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
        header('Cache-Control: no-store');
        $subPath = '/' . trim($subPath, '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            if ($subPath === '/' && $method === 'GET') {
                $this->showJoinForm();
            } elseif ($subPath === '/join' && $method === 'POST') {
                $this->join();
            } elseif ($subPath === '/room' && $method === 'GET') {
                $this->showRoom();
            } elseif ($subPath === '/api/state' && $method === 'GET') {
                $this->apiState();
            } elseif ($subPath === '/api/heartbeat' && $method === 'POST') {
                $this->apiHeartbeat();
            } elseif ($subPath === '/api/event' && $method === 'POST') {
                $this->apiEvent();
            } else {
                http_response_code(404);
                echo 'Not found';
            }
        } catch (Throwable $e) {
            if (strpos($subPath, '/api/') === 0) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'access_unavailable']);
            } else {
                http_response_code(500);
                echo htmlspecialchars($this->i18n->t('quiz.journal.detached'), ENT_QUOTES, 'UTF-8');
            }
            error_log('Quiz error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    // ------------------------------------------------------------------

    private function showJoinForm(string $error = ''): void
    {
        render('quiz/join', [
            'error' => $error,
            'title' => $this->i18n->t('quiz.join.title'),
            'csrfToken' => $this->csrfToken(),
        ], $this->i18n, $this->config);
    }

    private function join(): void
    {
        if (!$this->validCsrf($_POST)) {
            http_response_code(403);
            $this->showJoinForm($this->i18n->t('quiz.journal.detached'));
            return;
        }
        if (!is_string($_POST['pin'] ?? null) || !is_string($_POST['code'] ?? null)) {
            $this->showJoinForm($this->i18n->t('quiz.journal.detached'));
            return;
        }
        $pin = $_POST['pin'];
        $code = $_POST['code'];
        $consent = isset($_POST['consent']);

        if (!$consent) {
            $this->showJoinForm($this->i18n->t('quiz.journal.detached'));
            return;
        }

        $session = $this->quiz->findSessionByPin($pin);
        if ($session === null) {
            $this->showJoinForm($this->i18n->t('quiz.journal.detached'));
            return;
        }

        try {
            $attempt = $this->quiz->joinAttempt($session, $code);
        } catch (RuntimeException $e) {
            $this->showJoinForm($this->i18n->t('quiz.journal.detached'));
            return;
        }

        $_SESSION[self::SESSION_ATTEMPT] = (int)$attempt['id'];
        $_SESSION[self::SESSION_SESSION] = (int)$session['id'];

        header('Location: /quiz/room');
        exit;
    }

    private function showRoom(): void
    {
        $context = $this->currentContext();
        if ($context === null) {
            header('Location: /quiz');
            exit;
        }
        [$session, $attempt] = $context;

        $state = $this->quiz->buildStatePayload($session, $attempt);
        $state['csrf_token'] = $this->csrfToken();

        // Standalone page (no site layout): the exam needs the full viewport.
        $i18n = $this->i18n;
        $config = $this->config;
        include __DIR__ . '/../Views/quiz/room.php';
    }

    private function apiState(): void
    {
        $context = $this->requireApiContext();
        [$session, $attempt] = $context;
        $this->json(array_merge($this->quiz->buildStatePayload($session, $attempt), ['csrf_token' => $this->csrfToken()]));
    }

    private function apiHeartbeat(): void
    {
        $context = $this->requireApiContext();
        if (!$this->validCsrf($_POST)) { $this->json(['error' => 'access_unavailable'], 403); return; }
        [, $attempt] = $context;
        $this->quiz->recordHeartbeat((int)$attempt['id']);
        $this->json(['ok' => true]);
    }

    private function apiEvent(): void
    {
        $context = $this->requireApiContext();
        [$session, $attempt] = $context;

        $raw = file_get_contents('php://input', false, null, 0, 4097) ?: '';
        if (strlen($raw) > 4096) { $this->json(['error' => 'access_unavailable'], 413); return; }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // sendBeacon may post as form data
            $data = $_POST;
        }

        if (!$this->validCsrf($data)) { $this->json(['error' => 'access_unavailable'], 403); return; }

        if (!is_string($data['type'] ?? null) || (isset($data['away_seconds']) && !is_int($data['away_seconds']) && !(is_string($data['away_seconds']) && ctype_digit($data['away_seconds'])))) {
            $this->json(['error' => 'access_unavailable'], 400);
            return;
        }
        $type = $data['type'];
        $awaySeconds = (int)($data['away_seconds'] ?? 0);

        try {
            $metadata = array_diff_key($data, ['type' => true, 'away_seconds' => true, '_csrf' => true]);
            $result = $this->quiz->recordEvent($session, $attempt, $type, $awaySeconds, $metadata);
        } catch (\PDOException $e) {
            error_log(sprintf('Quiz event failed: session_id=%d; attempt_id=%d; message=%s; file=%s:%d',
                (int)$session['id'], (int)$attempt['id'], str_replace(["\r", "\n"], ' ', mb_substr($e->getMessage(), 0, 512)), $e->getFile(), $e->getLine()));
            $this->json(['error' => 'access_unavailable'], 503);
            return;
        } catch (RuntimeException $e) {
            $bindingError = in_array($e->getMessage(), ['attempt_mismatch', 'stale_generation'], true);
            $this->json(['error' => 'access_unavailable'], $bindingError ? 409 : 400);
            return;
        }

        $this->json($result);
    }

    // ------------------------------------------------------------------

    /** @return array{0: array, 1: array}|null */
    private function currentContext(): ?array
    {
        $attemptId = $_SESSION[self::SESSION_ATTEMPT] ?? null;
        if (!is_int($attemptId)) {
            return null;
        }

        $attempt = $this->quiz->getAttempt($attemptId);
        if ($attempt === null) {
            return null;
        }

        $session = $this->quiz->getSession((int)$attempt['session_id']);
        if ($session === null) {
            return null;
        }

        return [$session, $attempt];
    }

    /** @return array{0: array, 1: array} */
    private function requireApiContext(): array
    {
        $context = $this->currentContext();
        if ($context === null) {
            $this->json(['error' => 'access_unavailable'], 401);
            exit;
        }
        if (isset($_GET['attempt_id']) && (!is_scalar($_GET['attempt_id']) || !ctype_digit((string)$_GET['attempt_id']) || (int)$_GET['attempt_id'] !== (int)$context[1]['id'])) {
            $this->json(['error' => 'access_unavailable'], 409);
            exit;
        }
        return $context;
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    }

    private function csrfToken(): string
    {
        if (!isset($_SESSION['quiz_student_csrf']) || !is_string($_SESSION['quiz_student_csrf'])) {
            $_SESSION['quiz_student_csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['quiz_student_csrf'];
    }

    private function validCsrf(array $data): bool
    {
        $given = $data['_csrf'] ?? ($_SERVER['HTTP_X_QUIZ_CSRF'] ?? null);
        return is_string($given) && $given !== '' && hash_equals($this->csrfToken(), $given);
    }
}
