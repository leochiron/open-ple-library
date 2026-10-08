<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/app/Helpers/url.php';
use App\Services\QuizDbService;
use App\Services\QuizService;
use App\Services\QuizAdminAuthService;
use App\Services\I18nService;
use App\Controllers\QuizController;
use App\Controllers\QuizAdminController;

final class AccessFixtureQuiz extends QuizService
{
    public array $extraState = [];
    public function buildStatePayload(array $session, ?array $attempt = null): array { return parent::buildStatePayload($session, $attempt) + $this->extraState; }
}
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ple-access-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
try {
    $_SESSION = [];
    $database = new QuizDbService($tmp); $db = $database->pdo(); $auth = new QuizAdminAuthService($database, false);
    $adminA = $auth->createAdmin('a@example.test', 'Teacher A', 'admin-password-A', 'quiz_admin', false);
    $adminB = $auth->createAdmin('b@example.test', 'Teacher B', 'admin-password-B', 'quiz_admin', false);
    $super = $auth->createAdmin('root@example.test', 'Root', 'super-password', 'super_admin', false);
    $config = ['branding' => ['quiz_hmac_secret' => str_repeat('x', 64)]];
    $quiz = new AccessFixtureQuiz($database, $config, $tmp); $quiz->setAdminContext($adminA);
    $rules = ['title' => 'Access test', 'google_form_url' => 'https://docs.google.com/forms/d/e/PRIVATE_FORM/viewform',
        'attempt_entry_id' => '123456', 'duration_minutes' => 15, 'max_incidents' => 2, 'min_away_seconds' => 10];
    $sid = (int)$quiz->createSession($rules, "Alice Alpha alice@example.test\nBob Beta bob@example.test\nCarol Gamma carol@example.test")['id'];
    $otherSid = (int)$quiz->createSession($rules, "Other Student other@example.test")['id'];
    $quiz->openLobby($sid); $students = $quiz->listStudents($sid); $studentId = (int)$students[0]['id'];
    $privateReason = 'PRIVATE_REASON_MARKER';
    $quiz->setManualAccess($sid, $studentId, true, "  $privateReason\n  context  ");
    $policy = $quiz->getStudentAccess($sid, $studentId);
    assertSameValue($privateReason . ' context', $policy['reason'], 'Reason is normalized');
    assertSameValue((int)$adminA['id'], (int)$policy['actor_admin_id'], 'Actor comes from authenticated context');
    assertSameValue(0, count($quiz->listAttempts($sid)), 'Preconnection block does not invent an attempt');
    $a = $quiz->joinAttempt($quiz->getSession($sid), $students[0]['code']);
    $b = $quiz->joinAttempt($quiz->getSession($sid), $students[1]['code']);
    $aid = (int)$a['id']; $quiz->launch($sid); $session = $quiz->getSession($sid);
    $public = $quiz->buildStatePayload($session, $a);
    assertSameValue(false, $public['access_allowed'], 'Preconnection block follows the real student into their attempt');
    assertSameValue(false, isset($public['form_url']), 'Blocked payload delivers no Forms URL');
    assertSameValue(true, isset($quiz->buildStatePayload($session, $b)['form_url']), 'Second student continues independently');
    foreach (['reason', 'actor_admin_id', 'actor_name', 'manual_blocked', 'scopes', 'access_context'] as $field) { assertSameValue(false, isset($public[$field]), 'Public decision has no private policy field'); }
    $i18n = new I18nService(['default_language' => 'fr'], require dirname(__DIR__) . '/app/Config/i18n.php');
    $studentController = new QuizController($quiz, $i18n, $config);
    $_SESSION['quiz_attempt_id'] = $aid; $_SESSION['quiz_session_id'] = $sid;
    $capture = static function (object $controller, string $method, array $args = []): string {
        $reflection = new ReflectionMethod($controller, $method); $reflection->setAccessible(true);
        ob_start(); try { $reflection->invokeArgs($controller, $args); return (string)ob_get_contents(); } finally { ob_end_clean(); }
    };
    $html = $capture($studentController, 'showRoom');
    assertSameValue(false, str_contains($html, $privateReason) || str_contains($html, 'PRIVATE_FORM') || str_contains($html, 'Teacher A'), 'Initial room HTML/config is private-safe without a form src');
    assertSameValue(true, str_contains($html, 'Accès indisponible. Contactez le formateur.'), 'Only generic suspension message is shown');
    $csrf = $_SESSION['quiz_student_csrf'];
    $send = static fn(string $type, array $metadata = []): array => $quiz->recordEvent($session, $a, $type, 0, $metadata);
    $metadata = static fn(): array => ['attempt_id' => $aid, 'event_uid' => bin2hex(random_bytes(16)), 'tracking_generation' => $quiz->getSession($sid)['tracking_generation'], 'source' => 'page'];
    $finish = $metadata();
    $refused = $send('finish', $finish);
    assertSameValue(false, $refused['access_allowed'], 'Fresh decision refuses completion while blocked');
    assertSameValue(false, isset($refused['event_uid']), 'Refusal does not acknowledge a UID');
    assertSameValue(null, $quiz->getAttempt($aid)['finished_at'], 'Refused completion changes no finish');
    assertSameValue(false, in_array('finish', array_column($quiz->listEventsForAttempt($aid), 'event_type'), true), 'Refused completion adds no success event');
    $send('copy', array_merge($metadata(), ['source' => 'shortcut'])); $quiz->recordHeartbeat($aid);
    assertSameValue(true, in_array('copy', array_column($quiz->listEventsForAttempt($aid), 'event_type'), true), 'Observations remain available during suspension');
    $quiz->setManualAccess($sid, $studentId, false, 'Technical issue resolved');
    assertSameValue(true, $quiz->buildStatePayload($session, $a)['access_allowed'], 'Fresh payload ignores stale session/attempt arrays after lift');
    $accepted = $send('finish', $finish); assertSameValue(true, $accepted['finished'], 'Same explicit completion can be accepted after lift');
    $finishedAt = $quiz->getAttempt($aid)['finished_at'];
    $quiz->setManualAccess($sid, $studentId, true, 'Second block');
    $refused = $send('finish', $finish);
    assertSameValue(false, isset($refused['event_uid']), 'Fresh block is checked before already-received finish UID');
    $refused = $send('resume', $metadata());
    assertSameValue(false, isset($refused['event_uid']), 'Resume also refuses without UID ack');
    assertSameValue($finishedAt, $quiz->getAttempt($aid)['finished_at'], 'Block/refusal preserves prior finish');
    $quiz->setManualAccess($sid, $studentId, false, 'Resume permitted');
    $resume = $metadata(); $send('resume', $resume);
    $quiz->setManualAccess($sid, $studentId, true, 'Replay guard');
    assertSameValue(false, isset($send('resume', $resume)['event_uid']), 'Replay resume uses the current access decision too');
    $countBefore = (int)$quiz->getAttempt($aid)['incident_count'];
    $quiz->updateSession($sid, $rules + ['reload_is_incident' => 'on']); $quiz->excuseAttempt($aid); $quiz->launch($sid);
    assertSameValue(false, $quiz->studentAccessAllowed($sid, $studentId), 'Settings, quota/arbitration and ordinary launch cannot lift manual policy');
    $quiz->close($sid); $quiz->openLobby($sid); $quiz->launch($sid); $quiz->resetAndRelaunch($sid);
    assertSameValue(false, $quiz->studentAccessAllowed($sid, $studentId), 'Manual block persists through close/reopen/reset');
    $archive = $quiz->getArchive((int)array_values(array_filter($quiz->listArchives($sid)['rows'], static fn($r): bool => (int)$r['source_attempt_id'] === $aid))[0]['id']);
    assertSameValue(true, $archive['snapshot']['access_context']['manual_blocked'], 'Future snapshot freezes access policy');
    assertSameValue('Replay guard', $archive['snapshot']['access_context']['reason'], 'Private reason is frozen in private snapshot');
    $archiveJson = $db->query('SELECT snapshot_json FROM quiz_attempt_archives WHERE id = ' . (int)$archive['id'])->fetchColumn();
    $quiz->setManualAccess($sid, $studentId, false, str_repeat('é', 1000));
    assertSameValue(1000, mb_strlen($quiz->getStudentAccess($sid, $studentId)['reason']), 'Character bound accepts UTF-8 reason of exactly 1000 characters');
    assertSameValue($archiveJson, $db->query('SELECT snapshot_json FROM quiz_attempt_archives WHERE id = ' . (int)$archive['id'])->fetchColumn(), 'Current policy change never edits prior snapshot');
    $reject = static function (callable $action, string $expected) use ($quiz, $sid): void {
        $before = count($quiz->listAudit($sid, 0, 50)['rows']);
        try { $action(); throw new LogicException('Expected refusal'); } catch (RuntimeException $e) { assertSameValue($expected, $e->getMessage(), 'Expected private failure'); }
        assertSameValue($before, count($quiz->listAudit($sid, 0, 50)['rows']), 'Refusal writes no success audit');
    };
    foreach (['', '   ', str_repeat('é', 1001), "bad\0value"] as $reason) { $reject(static fn() => $quiz->setManualAccess($sid, $studentId, true, $reason), 'invalid_access_reason'); }
    $reject(static fn() => $quiz->setManualAccess($sid, $studentId, false, 'Already allowed'), 'access_unchanged');
    $reject(static fn() => $quiz->setManualAccess($otherSid, $studentId, true, 'Wrong subject'), 'resource_not_found');
    $beforePolicy = $quiz->getStudentAccess($sid, $studentId); $beforeAudit = $db->query('SELECT * FROM quiz_session_audit ORDER BY id')->fetchAll();
    $db->exec("CREATE TRIGGER fail_access BEFORE INSERT ON quiz_session_audit BEGIN SELECT RAISE(ABORT, 'access_audit_failure'); END");
    try { $quiz->setManualAccess($sid, $studentId, true, 'Fails after policy change'); throw new LogicException('Expected SQL failure'); }
    catch (PDOException $e) { assertSameValue(true, str_contains($e->getMessage(), 'access_audit_failure'), 'Injected audit error'); }
    $db->exec('DROP TRIGGER fail_access');
    assertSameValue($beforePolicy, $quiz->getStudentAccess($sid, $studentId), 'Audit SQL failure rolls back policy and actor together');
    assertSameValue($beforeAudit, $db->query('SELECT * FROM quiz_session_audit ORDER BY id')->fetchAll(), 'Failure leaves no success audit');
    assertSameValue(false, $db->inTransaction(), 'Rollback closes transaction');

    $_GET = ['attempt_id' => $aid]; $_POST = [];
    $heartbeatBefore = $quiz->getAttempt($aid)['last_heartbeat_at'];
    $body = json_decode($capture($studentController, 'apiHeartbeat'), true);
    assertSameValue(['error' => 'access_unavailable'], $body, 'Missing student CSRF is generic'); assertSameValue(403, http_response_code(), 'Missing CSRF refused');
    assertSameValue($heartbeatBefore, $quiz->getAttempt($aid)['last_heartbeat_at'], 'Rejected heartbeat is not a mutation');
    $_SERVER['HTTP_X_QUIZ_CSRF'] = $csrf;
    assertSameValue(['ok' => true], json_decode($capture($studentController, 'apiHeartbeat'), true), 'Heartbeat accepts header CSRF');
    unset($_SERVER['HTTP_X_QUIZ_CSRF']);
    $_POST = ['type' => 'copy', '_csrf' => 'wrong'];
    assertSameValue(['error' => 'access_unavailable'], json_decode($capture($studentController, 'apiEvent'), true), 'Wrong event CSRF is generic');
    $_POST = ['type' => 'copy', '_csrf' => $csrf] + array_merge($metadata(), ['source' => 'shortcut']);
    $body = json_decode($capture($studentController, 'apiEvent'), true);
    assertSameValue($_POST['event_uid'], $body['event_uid'], 'Transport CSRF is removed before immutable metadata validation');
    assertSameValue(false, str_contains(json_encode($quiz->listEventsForAttempt($aid)), $csrf), 'Transport token never enters raw journal');
    $_POST = []; $_GET = ['id' => $sid];
    $adminController = new QuizAdminController($quiz, $auth, $i18n, $config);
    $quiz->extraState = ['private_test_future' => 'SECRET_FUTURE_FIELD'];
    $board = json_decode($capture($adminController, 'apiBoard'), true); $quiz->extraState = [];
    assertSameValue(false, isset($board['private_test_future']), 'Board top-level is an explicit allowlist, even for future private state fields');
    foreach (['code', 'access_context', 'reason', 'actor_name', 'manual_blocked'] as $field) { assertSameValue(false, array_key_exists($field, $board['attempts'][0]), 'Board rows cannot serialize private details'); }
    $db->exec("WITH RECURSIVE nums(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM nums WHERE n<105) INSERT INTO quiz_events(attempt_id,event_type,dropped_events) SELECT $aid, 'tracking_diagnostic', 1 FROM nums");
    $send('hidden'); // legacy public type after >100 private diagnostics
    $_GET = ['id' => $sid, 'after' => 0];
    $events = json_decode($capture($adminController, 'apiEvents', [true]), true)['events'];
    assertSameValue(true, in_array('hidden', array_column($events, 'event_type'), true), 'Filtering occurs before LIMIT, so private diagnostics never trap the public cursor');
    assertSameValue(false, str_contains(json_encode($events), 'dropped_events') || str_contains(json_encode($events), 'tracking_diagnostic'), 'Projected event payload contains no diagnostics');
    $csv = $quiz->exportCsv($sid);
    assertSameValue(true, str_contains($csv, 'contexte_acces_prive') && str_contains($csv, 'Replay guard'), 'Private CSV contains current/frozen access context and transitions');
    assertSameValue(false, str_contains($csv, $csrf), 'CSV excludes CSRF');
    $anonymous = new QuizService($database, $config, $tmp);
    foreach ([static fn() => $anonymous->getStudentAccess($sid, $studentId), static fn() => $anonymous->setManualAccess($sid, $studentId, true, 'Anonymous action')] as $action) {
        try { $action(); throw new LogicException('Expected anonymous denial'); }
        catch (RuntimeException $e) { assertSameValue('admin_auth_required', $e->getMessage(), 'Detailed policy and mutations require administrative context'); }
    }
    // A legacy 3b snapshot lacks this optional extension; never backfill from current policy.
    $legacyId = (int)array_values(array_filter($quiz->listArchives($sid)['rows'], static fn($r): bool => (int)$r['source_attempt_id'] === (int)$b['id']))[0]['id'];
    $legacy = $quiz->getArchive($legacyId); unset($legacy['snapshot']['access_context']);
    $stmt = $db->prepare('UPDATE quiz_attempt_archives SET snapshot_json = :json WHERE id = :id');
    $legacyJson = json_encode($legacy['snapshot'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $stmt->execute(['json' => $legacyJson, 'id' => $legacyId]); $stmt = null;
    $quiz->setManualAccess($sid, (int)$b['student_id'], true, 'CURRENT_BOB_POLICY_MARKER');
    $_GET = ['id' => $legacyId]; $legacyHtml = $capture($adminController, 'archiveReport');
    assertSameValue(true, str_contains($legacyHtml, 'Contexte d’accès non conservé'), 'Old archive renders explicitly unknown policy context');
    assertSameValue(false, str_contains($legacyHtml, 'CURRENT_BOB_POLICY_MARKER'), 'Old archive never consults the living policy');
    assertSameValue($legacyJson, $db->query('SELECT snapshot_json FROM quiz_attempt_archives WHERE id = ' . $legacyId)->fetchColumn(), 'Reading an old snapshot never migrates or overwrites it');
    assertSameValue(false, str_contains($quiz->exportArchiveCsv($legacyId), 'CURRENT_BOB_POLICY_MARKER'), 'Old archive CSV never backfills current policy');
    // Exercise actual admin dispatch for method and CSRF restrictions without exiting a successful redirect.
    $auth->authenticate('a@example.test', 'admin-password-A');
    $policyBefore = $db->query('SELECT * FROM quiz_student_access ORDER BY student_id')->fetchAll();
    $auditBefore = $db->query('SELECT * FROM quiz_session_audit ORDER BY id')->fetchAll();
    $_GET = []; $_POST = ['id' => $sid, 'student_id' => $studentId, 'reason' => 'CSRF rejected'];
    $_SERVER['REQUEST_METHOD'] = 'GET'; ob_start(); $adminController->handle('/access/block'); ob_end_clean();
    assertSameValue(404, http_response_code(), 'GET cannot mutate an access decision');
    $_SERVER['REQUEST_METHOD'] = 'POST'; ob_start(); $adminController->handle('/access/block'); ob_end_clean();
    assertSameValue(403, http_response_code(), 'Admin mutation with missing CSRF refused');
    $_POST['_csrf'] = 'wrong'; ob_start(); $adminController->handle('/access/block'); ob_end_clean();
    assertSameValue(403, http_response_code(), 'Admin mutation with wrong CSRF refused');
    $_POST['_csrf'] = $_SESSION['quiz_admin_csrf']; $_POST['student_id'] = [$studentId];
    ob_start(); $adminController->handle('/access/block'); ob_end_clean();
    assertSameValue(400, http_response_code(), 'Non-scalar subject ID never casts to student 1');
    assertSameValue($policyBefore, $db->query('SELECT * FROM quiz_student_access ORDER BY student_id')->fetchAll(), 'Method/CSRF refusals leave policies intact');
    assertSameValue($auditBefore, $db->query('SELECT * FROM quiz_session_audit ORDER BY id')->fetchAll(), 'Method/CSRF refusals write no success audit');
    $_POST = []; $_GET = [];
    $quiz->setAdminContext($adminB);
    foreach ([static fn() => $quiz->getStudentAccess($sid, $studentId), static fn() => $quiz->setManualAccess($sid, $studentId, true, 'Foreign owner')] as $action) {
        try { $action(); throw new LogicException('Expected owner denial'); } catch (RuntimeException $e) { assertSameValue('resource_not_found', $e->getMessage(), 'Foreign owner cannot read/change policy'); }
    }
    $quiz->setAdminContext($super); $quiz->transferSession($sid, (int)$adminB['id']); $quiz->setAdminContext($adminA);
    try { $quiz->getStudentAccess($sid, $studentId); throw new LogicException('Expected former owner denial'); }
    catch (RuntimeException $e) { assertSameValue('resource_not_found', $e->getMessage(), 'Former owner loses detailed policy access after transfer'); }
    $quiz->setAdminContext($adminB);
    assertSameValue((int)$adminA['id'], (int)$quiz->getStudentAccess($sid, $studentId)['actor_admin_id'], 'Ownership transfer leaves historical actor unchanged');
    $auditBeforeDelete = $db->query('SELECT * FROM quiz_session_audit ORDER BY id')->fetchAll();
    $quiz->deleteStudent($studentId, $sid);
    assertSameValue(1, (int)$db->query('SELECT COUNT(*) FROM quiz_student_access WHERE student_id = ' . $studentId)->fetchColumn(), 'Roster deletion retains independent policy history');
    assertSameValue($auditBeforeDelete, $db->query('SELECT * FROM quiz_session_audit ORDER BY id')->fetchAll(), 'Deletion never loses access transitions or frozen identity');
    assertSameValue($archiveJson, $db->query('SELECT snapshot_json FROM quiz_attempt_archives WHERE id = ' . (int)$archive['id'])->fetchColumn(), 'Deletion does not reconstruct access context from living tables');
    echo "QuizManualAccessTest: OK (fresh decision, replay, preconnection, persistence, privacy, CSRF, audit rollback, snapshots/CSV, ownership)\n";
} finally {
    unset($action, $send, $metadata, $reject, $capture, $studentController, $adminController, $anonymous, $quiz, $auth, $database, $db, $stmt, $e);
    gc_collect_cycles();
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($tmp);
}
