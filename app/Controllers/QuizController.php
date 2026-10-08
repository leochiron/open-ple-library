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
        $this->quiz->setBrowserRequestUserAgent(null);
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
            } elseif (in_array($subPath, ['/api/tracking/challenge','/api/tracking/preflight'],true) && $method === 'POST') {
                $this->apiTracking($subPath === '/api/tracking/preflight');
            } elseif (in_array($subPath, ['/api/tracking/pulse/challenge','/api/tracking/pulse'],true) && $method === 'POST') {
                $this->apiTracking($subPath === '/api/tracking/pulse', true);
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

        $roomEpoch = $this->quiz->openTrackingRoomDocument((int)$session['id'],(int)$attempt['id']);
        $this->quiz->setBrowserRequestUserAgent(is_string($_SERVER['HTTP_USER_AGENT']??null)?$_SERVER['HTTP_USER_AGENT']:'');
        $state = $this->quiz->prepareTrackingState((int)$session['id'],(int)$attempt['id']);
        if(QuizService::trackingStrengthened($state['tracking_mode'])){unset($state['form_url']);}
        $state['csrf_token'] = $this->csrfToken();

        // Standalone page (no site layout): the exam needs the full viewport.
        $i18n = $this->i18n;
        $config = $this->config;
        include __DIR__ . '/../Views/quiz/room.php';
    }

    private function apiState(): void
    {
        $context = $this->requireApiContext();
        $this->quiz->setBrowserRequestUserAgent(is_string($_SERVER['HTTP_USER_AGENT']??null)?$_SERVER['HTTP_USER_AGENT']:'');
        [$session, $attempt] = $context;
        $this->quiz->setTrackingRequestEpoch(is_string($_GET['room_epoch']??null)?$_GET['room_epoch']:null);
        try{$state=$this->quiz->prepareTrackingState((int)$session['id'],(int)$attempt['id']);$this->json($state+['csrf_token'=>$this->csrfToken()]);}
        catch(Throwable $e){$this->trackingFailure('state',$e);}
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
        if (strlen($raw) > 4096) {
            if (QuizService::trackingStrengthened($session['tracking_mode'])) { $this->trackingFailure('state', new RuntimeException('body_too_large')); }
            else { $this->json(['error' => 'access_unavailable'], 413); }
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // sendBeacon may post as form data
            $data = $_POST;
        }

        if (!$this->validCsrf($data)) {
            if (QuizService::trackingStrengthened($session['tracking_mode']) && in_array($data['type'] ?? null, ['finish','resume'], true)) { $this->trackingFailure($data['type'], new RuntimeException('csrf_invalid')); }
            else { $this->json(['error' => 'access_unavailable'], 403); }
            return;
        }

        if (!is_string($data['type'] ?? null) || (isset($data['away_seconds']) && !is_int($data['away_seconds']) && !(is_string($data['away_seconds']) && ctype_digit($data['away_seconds'])))) {
            $this->json(['error' => 'access_unavailable'], 400);
            return;
        }
        $type = $data['type'];
        $awaySeconds = (int)($data['away_seconds'] ?? 0);

        try {
            $this->quiz->setTrackingRequestEpoch(is_string($data['room_epoch']??null)?$data['room_epoch']:null);
            if(in_array($type,['finish','resume'],true)){$this->quiz->setBrowserRequestUserAgent(is_string($_SERVER['HTTP_USER_AGENT']??null)?$_SERVER['HTTP_USER_AGENT']:'');}
            $metadata = array_diff_key($data, ['type' => true, 'away_seconds' => true, '_csrf' => true, 'room_epoch'=>true]);
            $result = $this->quiz->recordEvent($session, $attempt, $type, $awaySeconds, $metadata);
        } catch (\PDOException $e) {
            if (QuizService::trackingStrengthened($session['tracking_mode']) && in_array($type, ['finish','resume'], true)) { $this->trackingFailure($type, $e); return; }
            error_log(sprintf('Quiz event failed: session_id=%d; attempt_id=%d; message=%s; file=%s:%d',
                (int)$session['id'], (int)$attempt['id'], str_replace(["\r", "\n"], ' ', mb_substr($e->getMessage(), 0, 512)), $e->getFile(), $e->getLine()));
            $this->json(['error' => 'access_unavailable'], 503);
            return;
        } catch (RuntimeException $e) {
            if(QuizService::trackingStrengthened($session['tracking_mode']) && in_array($type,['finish','resume'],true)){$this->trackingFailure($type,$e);return;}
            $bindingError = in_array($e->getMessage(), ['attempt_mismatch', 'stale_generation'], true);
            $this->json(['error' => 'access_unavailable'], $bindingError ? 409 : 400);
            return;
        }

        if(QuizService::trackingStrengthened($session['tracking_mode']) && !in_array($type,['finish','resume'],true)){
            $result=array_intersect_key($result,array_fill_keys(['event_uid','attempt_id','incident_count','status','is_incident','settings_revision'],true));
        }
        $this->json($result);
    }

    private function apiTracking(bool $submit, bool $pulse = false): void
    {
        $operation=$pulse?($submit?'pulse':'pulse_challenge'):($submit?'preflight':'challenge');
        $context=$this->requireApiContext();[$session,$attempt]=$context;
        $this->quiz->setBrowserRequestUserAgent(is_string($_SERVER['HTTP_USER_AGENT']??null)?$_SERVER['HTTP_USER_AGENT']:'');
        $raw=file_get_contents('php://input',false,null,0,4097)?:'';
        $data=$raw!==''?json_decode($raw,true):$_POST;
        if(strlen($raw)>4096){$this->trackingFailure($operation,new RuntimeException('body_too_large'));return;}
        if(!is_array($data)){$this->trackingFailure($operation,new RuntimeException('schema_invalid'));return;}
        if(!$this->validCsrf($data)){$this->trackingFailure($operation,new RuntimeException('csrf_invalid'));return;}
        $schema=$data['schema_version']??1;
        $allowed=['attempt_id','tracking_generation','room_epoch','_csrf'];if($submit){$allowed=array_merge($allowed,['schema_version','challenge','checks']);}
        if($schema===2){$allowed=array_merge($allowed,['schema_version','browser']);}
        if(array_diff(array_keys($data),$allowed)!==[] || ($data['attempt_id']??null)!==(int)$attempt['id'] || !is_string($data['tracking_generation']??null) || !is_string($data['room_epoch']??null)){$this->trackingFailure($operation,new RuntimeException('schema_invalid'));return;}
        try{
            if(!in_array($schema,[1,2],true) || (!empty($session['browser_enabled']) && $schema!==2) || ($schema===2 && !is_array($data['browser']??null))){throw new RuntimeException('schema_invalid');}
            $browser=$schema===2?$data['browser']:null;
            if($submit){if(!in_array($data['schema_version']??null,[1,2],true) || !is_string($data['challenge']??null)||!is_array($data['checks']??null)){throw new RuntimeException('schema_invalid');}
                $result=$pulse?$this->quiz->submitTrackingPulse((int)$session['id'],(int)$attempt['id'],$data['tracking_generation'],$data['room_epoch'],$data['challenge'],$data['checks'],$browser):$this->quiz->submitTrackingPreflight((int)$session['id'],(int)$attempt['id'],$data['tracking_generation'],$data['room_epoch'],$data['challenge'],$data['checks'],$browser);
            }else{$result=$pulse?$this->quiz->createTrackingPulseChallenge((int)$session['id'],(int)$attempt['id'],$data['tracking_generation'],$data['room_epoch'],$browser):$this->quiz->createTrackingChallenge((int)$session['id'],(int)$attempt['id'],$data['tracking_generation'],$data['room_epoch'],$browser);}
            $this->json($result);
        }catch(Throwable $e){$this->trackingFailure($operation,$e);}
    }
    private function trackingFailure(string $operation,Throwable $error): void
    {
        $code=$error instanceof \PDOException?'database_failure':$error->getMessage();
        if ($code === 'stale_generation') { $code = 'generation_mismatch'; }
        $this->quiz->recordTrackingRefusal($operation,$code,$error);
        $status=$code==='database_failure'?503:($code==='csrf_invalid'?403:($code==='body_too_large'?413:(in_array($code,['schema_invalid','invalid_tracking_state'],true)?400:409)));
        $this->json(['error'=>'access_unavailable'],$status);
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
            $this->quiz->recordTrackingRefusal('state','attempt_mismatch');
            $this->json(['error' => 'access_unavailable'], 401);
            exit;
        }
        if (isset($_GET['attempt_id']) && (!is_scalar($_GET['attempt_id']) || !ctype_digit((string)$_GET['attempt_id']) || (int)$_GET['attempt_id'] !== (int)$context[1]['id'])) {
            $this->quiz->recordTrackingRefusal('state','attempt_mismatch');
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
