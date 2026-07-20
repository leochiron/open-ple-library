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
                echo json_encode(['error' => 'server_error']);
            } else {
                http_response_code(500);
                echo 'Erreur interne.';
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
        ], $this->i18n, $this->config);
    }

    private function join(): void
    {
        $pin = (string)($_POST['pin'] ?? '');
        $code = (string)($_POST['code'] ?? '');
        $consent = isset($_POST['consent']);

        if (!$consent) {
            $this->showJoinForm($this->i18n->t('quiz.join.error_consent'));
            return;
        }

        $session = $this->quiz->findSessionByPin($pin);
        if ($session === null) {
            $this->showJoinForm($this->i18n->t('quiz.join.error_pin'));
            return;
        }

        try {
            $attempt = $this->quiz->joinAttempt($session, $code);
        } catch (RuntimeException $e) {
            $this->showJoinForm($this->i18n->t('quiz.join.error_code'));
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

        // Standalone page (no site layout): the exam needs the full viewport.
        $i18n = $this->i18n;
        $config = $this->config;
        include __DIR__ . '/../Views/quiz/room.php';
    }

    private function apiState(): void
    {
        $context = $this->requireApiContext();
        [$session, $attempt] = $context;
        $this->json($this->quiz->buildStatePayload($session, $attempt));
    }

    private function apiHeartbeat(): void
    {
        $context = $this->requireApiContext();
        [, $attempt] = $context;
        $this->quiz->recordHeartbeat((int)$attempt['id']);
        $this->json(['ok' => true]);
    }

    private function apiEvent(): void
    {
        $context = $this->requireApiContext();
        [$session, $attempt] = $context;

        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // sendBeacon may post as form data
            $data = $_POST;
        }

        $type = (string)($data['type'] ?? '');
        $awaySeconds = (int)($data['away_seconds'] ?? 0);

        try {
            $result = $this->quiz->recordEvent($session, $attempt, $type, $awaySeconds);
        } catch (RuntimeException $e) {
            $this->json(['error' => 'invalid_event'], 400);
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
            $this->json(['error' => 'no_attempt'], 401);
            exit;
        }
        return $context;
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    }
}
