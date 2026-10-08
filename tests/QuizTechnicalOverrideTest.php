<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/app/Helpers/url.php';
use App\Services\QuizDbService;
use App\Services\QuizService;
use App\Services\QuizAccessEvaluator;
use App\Services\QuizAdminAuthService;
use App\Services\I18nService;
use App\Controllers\QuizController;
use App\Controllers\QuizAdminController;

/** Synthetic causes exist only in this test subclass, never in production or HTTP. */
final class OverrideFixtureQuiz extends QuizService
{
    public string $contextRef = 'A';
    public array $causesByContext = [];
    public string $fixedTime;
    protected function now(): string { return $this->fixedTime; }
    protected function technicalAccessContext(int $sessionId, int $studentId, string $generation): array
    { return array_merge(parent::technicalAccessContext($sessionId, $studentId, $generation), ['context_ref' => $this->contextRef]); }
    protected function currentTechnicalCauses(array $context): array { return $this->causesByContext[$context['context_ref']] ?? []; }
}
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ple-override-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
try {
    $_SESSION = [];
    $database = new QuizDbService($tmp); $db = $database->pdo(); $auth = new QuizAdminAuthService($database, false);
    $adminA = $auth->createAdmin('a@example.test', 'Teacher A', 'password-teacher-a', 'quiz_admin', false);
    $adminB = $auth->createAdmin('b@example.test', 'Teacher B', 'password-teacher-b', 'quiz_admin', false);
    $super = $auth->createAdmin('root@example.test', 'Root', 'password-super-admin', 'super_admin', false);
    $config = ['branding' => ['quiz_hmac_secret' => str_repeat('x', 64)]];
    $quiz = new OverrideFixtureQuiz($database, $config, $tmp); $quiz->fixedTime = gmdate('Y-m-d H:i:s'); $quiz->setAdminContext($adminA);
    $rules = ['title' => 'Override test', 'google_form_url' => 'https://docs.google.com/forms/d/e/PRIVATE_FORM/viewform',
        'attempt_entry_id' => '123456', 'duration_minutes' => 15, 'max_incidents' => 2, 'min_away_seconds' => 10];
    $sid = (int)$quiz->createSession($rules, "Alice Alpha alice@example.test\nBob Beta bob@example.test\nCarol Gamma carol@example.test")['id'];
    $otherSid = (int)$quiz->createSession($rules, "Other Student other@example.test")['id'];
    $quiz->openLobby($sid); $students = $quiz->listStudents($sid); $studentId = (int)$students[0]['id']; $bobId = (int)$students[1]['id'];
    $generation = static fn(): string => $quiz->getSession($sid)['tracking_generation'];
    $reject = static function (callable $action, string $expected) use ($db): void {
        $before = $db->query('SELECT * FROM quiz_session_audit ORDER BY id')->fetchAll();
        try { $action(); throw new LogicException('Expected refusal'); }
        catch (RuntimeException $e) { assertSameValue($expected, $e->getMessage(), 'Expected private refusal'); }
        assertSameValue($before, $db->query('SELECT * FROM quiz_session_audit ORDER BY id')->fetchAll(), 'Refusal creates no success audit');
        assertSameValue(false, $db->inTransaction(), 'Refusal closes its transaction');
    };
    $preId = $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['tracking', 'browser'], '  PRIVATE_GRANT_REASON\n context  ');
    $pre = $quiz->getStudentAccess($sid, $studentId)['technical_override'];
    assertSameValue(['browser', 'tracking'], $pre['scopes'], 'Scopes are canonical');
    assertSameValue(0, count($quiz->listAttempts($sid)), 'Preconnection grant invents no attempt');
    assertSameValue(null, $quiz->getStudentAccess($sid, $bobId)['technical_override'], 'Other student is unaffected');
    $reject(static fn() => $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['browser'], 'Replace'), 'override_exists');
    $a = $quiz->joinAttempt($quiz->getSession($sid), $students[0]['code']); $b = $quiz->joinAttempt($quiz->getSession($sid), $students[1]['code']); $aid = (int)$a['id'];
    $_SESSION['quiz_attempt_id'] = $aid; $_SESSION['quiz_session_id'] = $sid;
    $oldGeneration = $generation(); $quiz->launch($sid);
    assertSameValue('expired', $quiz->listTechnicalOverrides($sid)['rows'][0]['status'], 'Launch durably expires preconnection grant');
    assertSameValue('new_launch', $quiz->listTechnicalOverrides($sid)['rows'][0]['end_kind'], 'Launch records expiration kind');
    assertSameValue(null, $quiz->getStudentAccess($sid, $studentId)['technical_override'], 'Launch does not transfer grant to fresh generation');
    $reject(static fn() => $quiz->grantTechnicalOverride($sid, $studentId, $oldGeneration, ['browser'], 'Stale'), 'stale_generation');
    $reject(static fn() => $quiz->revokeTechnicalOverride($sid, $studentId, $preId, $oldGeneration, 'Stale'), 'stale_generation');
    foreach ([[], ['unknown'], ['browser', 'browser'], ['browser', 1], ['browser', 'tracking', 'browser']] as $scopes) {
        $reject(static fn() => $quiz->grantTechnicalOverride($sid, $studentId, $generation(), $scopes, 'Invalid'), 'invalid_override_scopes');
    }
    foreach (['', '   ', str_repeat('é', 1001), "bad\0value"] as $reason) {
        $reject(static fn() => $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['browser'], $reason), 'invalid_access_reason');
    }
    $reject(static fn() => $quiz->grantTechnicalOverride($otherSid, $studentId, $quiz->getSession($otherSid)['tracking_generation'], ['browser'], 'Foreign pair'), 'invalid_override_state');
    $quiz->openLobby($otherSid);
    $reject(static fn() => $quiz->grantTechnicalOverride($otherSid, $studentId, $quiz->getSession($otherSid)['tracking_generation'], ['browser'], 'Foreign pair'), 'resource_not_found');

    $causes = [['scope' => 'browser', 'active' => true, 'code' => 'PRIVATE_BROWSER_DIAGNOSTIC'], ['scope' => 'tracking', 'active' => true, 'code' => 'PRIVATE_TRACKING_DIAGNOSTIC']];
    foreach ([[[], false], [['browser'], false], [['tracking'], false], [['tracking', 'browser'], true]] as [$scopes, $expected]) {
        assertSameValue($expected, QuizAccessEvaluator::evaluate(false, $causes, $scopes), 'Only selected scopes mask current causes');
        assertSameValue(false, QuizAccessEvaluator::evaluate(true, $causes, $scopes), 'Manual block remains absolute');
    }
    foreach ([[['scope' => 'unknown', 'active' => true]], [['scope' => 'browser', 'active' => 1]], [['scope' => 'tracking']], ['not a cause']] as $invalid) {
        $reject(static fn() => QuizAccessEvaluator::evaluate(true, $invalid, ['browser', 'tracking']), 'invalid_technical_cause');
    }
    $quiz->causesByContext = ['A' => $causes, 'B' => []];
    assertSameValue(false, $quiz->studentAccessAllowed($sid, $studentId), 'Cause fixture affects only its context');
    $quiz->contextRef = 'B'; assertSameValue(true, $quiz->studentAccessAllowed($sid, $studentId), 'Healthy browser B is not blocked by A');
    $quiz->contextRef = 'A';
    $grantId = $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['browser'], str_repeat('é', 1000));
    assertSameValue(1000, mb_strlen($quiz->getStudentAccess($sid, $studentId)['technical_override']['grant_reason']), 'UTF-8 exact 1000 bound accepted');
    assertSameValue(false, $quiz->studentAccessAllowed($sid, $studentId), 'Browser grant leaves tracking cause active');
    $reject(static fn() => $quiz->revokeTechnicalOverride($sid, $bobId, $grantId, $generation(), 'Wrong subject'), 'override_not_active');
    foreach (['', ' ', str_repeat('é', 1001), "bad\0value"] as $reason) {
        $reject(static fn() => $quiz->revokeTechnicalOverride($sid, $studentId, $grantId, $generation(), $reason), 'invalid_access_reason');
    }
    $quiz->revokeTechnicalOverride($sid, $studentId, $grantId, $generation(), str_repeat('à', 1000));
    $reject(static fn() => $quiz->revokeTechnicalOverride($sid, $studentId, $grantId, $generation(), 'Again'), 'override_not_active');
    $grantId = $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['tracking'], 'Tracking exception');
    assertSameValue(false, $quiz->studentAccessAllowed($sid, $studentId), 'Tracking grant leaves browser cause active');
    $quiz->revokeTechnicalOverride($sid, $studentId, $grantId, $generation(), 'Need both');
    $grantId = $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['tracking', 'browser'], 'PRIVATE_GRANT_REASON');
    assertSameValue(true, $quiz->studentAccessAllowed($sid, $studentId), 'Both scopes mask both causes');
    assertSameValue($causes, $quiz->causesByContext['A'], 'Evaluation never rewrites causes or declares them healthy');
    $quiz->setManualAccess($sid, $studentId, true, 'PRIVATE_MANUAL_REASON');
    assertSameValue(false, $quiz->studentAccessAllowed($sid, $studentId), 'Grant cannot lift manual block');
    $quiz->setManualAccess($sid, $studentId, false, 'Manual resolved');
    $prod = new QuizService($database, $config, $tmp); $prod->setAdminContext($adminA);
    assertSameValue(true, $prod->studentAccessAllowed($sid, $bobId), 'Production provider activates no browser/tracking policy');

    $metadata = static fn(): array => ['attempt_id' => $aid, 'event_uid' => bin2hex(random_bytes(16)), 'tracking_generation' => $generation(), 'source' => 'page'];
    $send = static fn(string $type, array $meta): array => $quiz->recordEvent($quiz->getSession($sid), $quiz->getAttempt($aid), $type, 0, $meta);
    $finish = $metadata(); assertSameValue(true, $send('finish', $finish)['finished'], 'Granted completion succeeds');
    $resume = $metadata(); assertSameValue(false, $send('resume', $resume)['finished'], 'Granted resume succeeds');
    assertSameValue($grantId, $quiz->getStudentAccess($sid, $studentId)['technical_override']['id'], 'Finish/resume/reload do not expire grant');
    $quiz->revokeTechnicalOverride($sid, $studentId, $grantId, $generation(), 'PRIVATE_REVOKE_REASON');
    $refused = $send('resume', $resume);
    assertSameValue(false, $refused['access_allowed'], 'Revoke falls back to current causes');
    assertSameValue(false, isset($refused['event_uid']), 'Fresh refusal guards prior success UID replay');
    assertSameValue(false, isset($refused['form_url']), 'Refused decision has no new Forms URL');
    $send('copy', array_merge($metadata(), ['source' => 'shortcut'])); $quiz->recordHeartbeat($aid);
    assertSameValue($causes, $quiz->causesByContext['A'], 'Observations and heartbeat do not clear causes');
    $grantId = $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['browser', 'tracking'], 'PRIVATE_GRANT_REASON');
    $quiz->recordEvent($quiz->getSession($sid), $quiz->getAttempt($aid), 'hidden', 12);
    $quiz->setManualAccess($sid, $bobId, true, 'Bob manual persists');
    $bobGrant = $quiz->grantTechnicalOverride($sid, $bobId, $generation(), ['browser'], 'PRIVATE_BOB_REASON');
    $quiz->stop($sid);
    assertSameValue($grantId, $quiz->getStudentAccess($sid, $studentId)['technical_override']['id'], 'Stop retains grant');

    // Snapshot all relevant tables to prove rollbacks after changes, not just before mutation.
    $state = static function () use ($db): array {
        $all = [];
        foreach (['quiz_sessions', 'quiz_attempts', 'quiz_events', 'quiz_attempt_archives', 'quiz_session_audit', 'quiz_student_access', 'quiz_technical_overrides'] as $table) { $all[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY ' . ($table === 'quiz_student_access' ? 'student_id' : 'id'))->fetchAll(); }
        return $all;
    };
    $fail = static function (string $trigger, callable $action) use ($db, $state): void {
        $before = $state(); $db->exec($trigger);
        try { $action(); throw new LogicException('Expected SQL error'); }
        catch (PDOException $e) { assertSameValue(true, str_contains($e->getMessage(), 'override_failure'), 'Injected SQL failure reached'); }
        finally { $db->exec('DROP TRIGGER fail_override'); }
        assertSameValue($before, $state(), 'SQL failure rolls back policy, multiple decisions, session, audit, events and archives');
        assertSameValue(false, $db->inTransaction(), 'SQL rollback closes transaction');
    };
    $fail("CREATE TRIGGER fail_override BEFORE INSERT ON quiz_session_audit WHEN NEW.action = 'override_granted' BEGIN SELECT RAISE(ABORT, 'override_failure'); END", static fn() => $quiz->grantTechnicalOverride($sid, (int)$students[2]['id'], $generation(), ['tracking'], 'Failed grant'));
    $fail("CREATE TRIGGER fail_override BEFORE INSERT ON quiz_session_audit WHEN NEW.action = 'override_revoked' BEGIN SELECT RAISE(ABORT, 'override_failure'); END", static fn() => $quiz->revokeTechnicalOverride($sid, $studentId, $grantId, $generation(), 'Failed revoke'));
    $cycleTrigger = "CREATE TRIGGER fail_override BEFORE INSERT ON quiz_session_audit WHEN NEW.action = 'override_expired' AND json_extract(NEW.after_json, '$.student_id') = $bobId BEGIN SELECT RAISE(ABORT, 'override_failure'); END";
    $fail($cycleTrigger, static fn() => $quiz->close($sid));
    $fail($cycleTrigger, static fn() => $quiz->launch($sid));
    $fail($cycleTrigger, static fn() => $quiz->resetAndRelaunch($sid));
    $fail("CREATE TRIGGER fail_override BEFORE INSERT ON quiz_session_audit WHEN NEW.action = 'reset' BEGIN SELECT RAISE(ABORT, 'override_failure'); END", static fn() => $quiz->resetAndRelaunch($sid));
    $beforeNonce = $generation(); $quiz->resetAndRelaunch($sid);
    $archiveId = (int)array_values(array_filter($quiz->listArchives($sid)['rows'], static fn(array $r): bool => (int)$r['source_attempt_id'] === $aid))[0]['id'];
    $archive = $quiz->getArchive($archiveId); $snapshotGrant = $archive['snapshot']['access_context']['technical_override'];
    assertSameValue($grantId, $snapshotGrant['id'], 'Reset snapshot includes current grant before expiration');
    assertSameValue('active', $snapshotGrant['status'], 'Snapshot preserves pre-reset active status');
    assertSameValue('expired', array_values(array_filter($quiz->listTechnicalOverrides($sid)['rows'], static fn(array $r): bool => $r['id'] === $grantId))[0]['status'], 'Current decision expires after snapshot');
    assertSameValue(0, (int)$quiz->getAttempt($aid)['incident_count'], 'Reset zeroes current count');
    assertSameValue(true, $quiz->getStudentAccess($sid, $bobId)['manual_blocked'], 'Reset preserves manual block');
    $reject(static fn() => $quiz->grantTechnicalOverride($sid, $studentId, $beforeNonce, ['browser'], 'Archived nonce'), 'stale_generation');
    $archiveJson = $db->query('SELECT snapshot_json FROM quiz_attempt_archives WHERE id = ' . $archiveId)->fetchColumn();
    $grantId = $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['browser'], 'Post-reset');
    $nonceAtClose = $generation(); $quiz->close($sid); $quiz->openLobby($sid);
    assertSameValue($nonceAtClose, $generation(), 'Close/reopen does not need a new nonce');
    assertSameValue(null, $quiz->getStudentAccess($sid, $studentId)['technical_override'], 'Terminal status prevents same-nonce revival');
    $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['browser'], 'Fresh explicit grant');
    $quiz->launch($sid);
    assertSameValue(null, $quiz->getStudentAccess($sid, $studentId)['technical_override'], 'Next launch expires explicit lobby grant');
    assertSameValue($archiveJson, $db->query('SELECT snapshot_json FROM quiz_attempt_archives WHERE id = ' . $archiveId)->fetchColumn(), 'Cycles never edit archived active grant');

    // All active rows terminate, including an old nonce and a student subsequently removed.
    $orphanId = (int)$students[2]['id']; $orphanGrant = $quiz->grantTechnicalOverride($sid, $orphanId, $generation(), ['tracking'], 'Orphan preserved');
    $db->exec("UPDATE quiz_technical_overrides SET tracking_generation = '" . str_repeat('a', 32) . "' WHERE id = $orphanGrant");
    $quiz->deleteStudent($orphanId, $sid); $quiz->launch($sid);
    $orphan = $quiz->listTechnicalOverrides($sid, $orphanId)['rows'][0];
    assertSameValue('expired', $orphan['status'], 'Cycle terminates stale-generation orphan grant');
    assertSameValue('Carol', $orphan['first_name'], 'Orphan history keeps frozen identity');

    // Exercise expiration beyond one materialized batch, with several nonces.
    $roster = [];
    for ($i = 0; $i < 53; $i++) { $roster[] = 'Bulk' . $i . ' Student bulk' . $i . '@example.test'; }
    $bulkSid = (int)$quiz->createSession($rules, implode("\n", $roster))['id']; $quiz->openLobby($bulkSid);
    $bulkStudents = $quiz->listStudents($bulkSid); $bulkGeneration = $quiz->getSession($bulkSid)['tracking_generation'];
    $bulkIds = [];
    foreach ($bulkStudents as $bulkStudent) { $bulkIds[] = $quiz->grantTechnicalOverride($bulkSid, (int)$bulkStudent['id'], $bulkGeneration, ['browser'], 'Bulk decision'); }
    $db->exec("UPDATE quiz_technical_overrides SET tracking_generation = '" . str_repeat('c', 32) . "' WHERE session_id = $bulkSid AND id % 2 = 0");
    $bulkBoundaryId = $bulkIds[50];
    $bulkTrigger = "CREATE TRIGGER fail_override BEFORE INSERT ON quiz_session_audit WHEN NEW.action = 'override_expired' AND json_extract(NEW.after_json, '$.id') = $bulkBoundaryId BEGIN SELECT RAISE(ABORT, 'override_failure'); END";
    $fail($bulkTrigger, static fn() => $quiz->close($bulkSid));
    $fail($bulkTrigger, static fn() => $quiz->resetAndRelaunch($bulkSid));
    $quiz->close($bulkSid);
    assertSameValue(0, (int)$db->query("SELECT COUNT(*) FROM quiz_technical_overrides WHERE session_id = $bulkSid AND status = 'active'")->fetchColumn(), 'Every batch expires, including older nonces');
    assertSameValue(53, (int)$db->query("SELECT COUNT(*) FROM quiz_session_audit WHERE session_id = $bulkSid AND action = 'override_expired'")->fetchColumn(), 'Each bulk decision receives exactly one expiration audit');
    $quiz->contextRef = 'B';
    for ($i = 0; $i < 28; $i++) {
        $id = $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['browser'], 'Pagination ' . $i);
        $quiz->revokeTechnicalOverride($sid, $studentId, $id, $generation(), 'Revoke ' . $i);
    }
    $page = $quiz->listTechnicalOverrides($sid); assertSameValue(25, count($page['rows']), 'Default pagination is bounded');
    $next = $quiz->listTechnicalOverrides($sid, null, $page['next_before']);
    assertSameValue([], array_intersect(array_column($page['rows'], 'id'), array_column($next['rows'], 'id')), 'Pages do not duplicate entries');
    assertSameValue(min(50, (int)$db->query('SELECT COUNT(*) FROM quiz_technical_overrides WHERE session_id = ' . $sid)->fetchColumn()), count($quiz->listTechnicalOverrides($sid, null, 0, 999)['rows']), 'Maximum page size is 50');
    $csv = $quiz->exportCsv($sid);
    assertSameValue((int)$db->query('SELECT COUNT(*) FROM quiz_technical_overrides WHERE session_id = ' . $sid)->fetchColumn(), substr_count($csv, 'derogation_technique;'), 'CSV iterates all override history beyond one page');
    assertSameValue(true, str_contains($csv, 'PRIVATE_GRANT_REASON') && str_contains($csv, 'PRIVATE_REVOKE_REASON') && str_contains($csv, 'Carol'), 'Private CSV keeps reasons, actor and deleted identity');

    $i18n = new I18nService(['default_language' => 'fr'], require dirname(__DIR__) . '/app/Config/i18n.php');
    $adminController = new QuizAdminController($quiz, $auth, $i18n, $config); $studentController = new QuizController($quiz, $i18n, $config);
    $capture = static function (object $controller, string $method, array $args = []): string {
        $reflection = new ReflectionMethod($controller, $method); $reflection->setAccessible(true);
        ob_start(); try { $reflection->invokeArgs($controller, $args); return (string)ob_get_contents(); } finally { ob_end_clean(); }
    };
    $quiz->contextRef = 'A';
    $grantId = $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['browser', 'tracking'], 'PRIVATE_GRANT_REASON');
    $checkPublic = static function (string $text) use ($causes): void {
        foreach (['PRIVATE_GRANT_REASON', 'PRIVATE_REVOKE_REASON', 'PRIVATE_MANUAL_REASON', 'PRIVATE_BOB_REASON', 'Teacher A', 'technical_override', 'scopes', 'grant_actor', 'suivi limité', $causes[0]['code'], $causes[1]['code']] as $marker) { assertSameValue(false, str_contains($text, $marker), 'Public projections exclude private marker ' . $marker); }
    };
    $checkPublic(json_encode($quiz->buildStatePayload($quiz->getSession($sid), $quiz->getAttempt($aid))));
    $checkPublic($capture($studentController, 'showRoom')); $checkPublic($capture($studentController, 'apiState'));
    $checkPublic(json_encode($send('copy', array_merge($metadata(), ['source' => 'shortcut']))));
    $quiz->revokeTechnicalOverride($sid, $studentId, $grantId, $generation(), 'PRIVATE_REVOKE_REASON');
    $html = $capture($studentController, 'showRoom'); $checkPublic($html);
    assertSameValue(false, str_contains($html, 'PRIVATE_FORM'), 'Initially denied HTML contains no Forms URL');
    $_GET = ['id' => $sid]; $checkPublic($capture($adminController, 'board')); $checkPublic($capture($adminController, 'apiBoard'));
    $quiz->contextRef = 'B'; assertSameValue(true, $quiz->studentAccessAllowed($sid, $studentId), 'Browser A denial never contaminates B');
    $quiz->contextRef = 'A'; assertSameValue(false, $quiz->studentAccessAllowed($sid, $studentId), 'B health never authorizes A');

    // A 4a archive has a manual context but no technical key. Absence is distinct from known null.
    $legacy = $quiz->getArchive($archiveId); unset($legacy['snapshot']['access_context']['technical_override']);
    $legacyJson = json_encode($legacy['snapshot'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $stmt = $db->prepare('UPDATE quiz_attempt_archives SET snapshot_json = :json WHERE id = :id'); $stmt->execute(['json' => $legacyJson, 'id' => $archiveId]); $stmt = null;
    $_GET = ['id' => $archiveId]; $legacyHtml = $capture($adminController, 'archiveReport');
    assertSameValue(true, str_contains($legacyHtml, 'Contexte technique non conservé'), 'Old manual context does not imply known absent technical override');
    assertSameValue(false, str_contains($legacyHtml, 'Aucune dérogation technique active'), 'Unknown is not displayed as none');
    assertSameValue($legacyJson, $db->query('SELECT snapshot_json FROM quiz_attempt_archives WHERE id = ' . $archiveId)->fetchColumn(), 'Legacy view never backfills snapshot');
    $accessContext = ['technical_override' => null]; $technicalOverride = null; ob_start(); include dirname(__DIR__) . '/app/Views/quiz/admin/override-details.php'; $knownHtml = (string)ob_get_clean();
    assertSameValue(true, str_contains($knownHtml, 'Aucune dérogation technique active'), 'Present null is known no override');

    $renderControls = static function (array $accessContext) use ($i18n, $quiz, $sid, $studentId): string {
        $session = $quiz->getSession($sid); $accessStudentId = $studentId; $csrfToken = 'TEST_CSRF';
        ob_start(); include dirname(__DIR__) . '/app/Views/quiz/admin/access-controls.php'; return (string)ob_get_clean();
    };
    $browserId = $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['browser'], 'Browser control form');
    $controlHtml = $renderControls($quiz->getStudentAccess($sid, $studentId));
    assertSameValue(true, str_contains($controlHtml, '/access/override/revoke') && str_contains($controlHtml, 'name="override_id"') && str_contains($controlHtml, 'name="generation"'), 'Active row is revoked explicitly with ID/generation');
    assertSameValue(true, str_contains($controlHtml, 'Dérogation navigateur active'), 'Browser-only private label identifies its actual scope');
    assertSameValue(false, str_contains($controlHtml, 'suivi limité'), 'Browser exemption does not invent tracking failure');
    $quiz->revokeTechnicalOverride($sid, $studentId, $browserId, $generation(), 'Replace explicitly');
    $trackingId = $quiz->grantTechnicalOverride($sid, $studentId, $generation(), ['tracking'], 'Tracking form');
    $controlHtml = $renderControls($quiz->getStudentAccess($sid, $studentId));
    assertSameValue(true, str_contains($controlHtml, 'Dérogation de suivi active'), '4b shows a neutral tracking grant label without automatic policy');
    assertSameValue(false, str_contains($controlHtml, 'Autorisé par le formateur') || str_contains($controlHtml, 'suivi limité'), '4b does not assert a healthy/limited technical context');
    $quiz->revokeTechnicalOverride($sid, $studentId, $trackingId, $generation(), 'No active grant');

    $anonymous = new QuizService($database, $config, $tmp);
    foreach ([static fn() => $anonymous->listTechnicalOverrides($sid), static fn() => $anonymous->grantTechnicalOverride($sid, $studentId, $generation(), ['browser'], 'Anonymous'), static fn() => $anonymous->revokeTechnicalOverride($sid, $studentId, $grantId, $generation(), 'Anonymous')] as $action) { $reject($action, 'admin_auth_required'); }
    $currentGeneration = $generation(); $quiz->setAdminContext($adminB);
    foreach ([static fn() => $quiz->listTechnicalOverrides($sid), static fn() => $quiz->grantTechnicalOverride($sid, $studentId, $currentGeneration, ['browser'], 'Foreign owner'), static fn() => $quiz->revokeTechnicalOverride($sid, $studentId, $grantId, $currentGeneration, 'Foreign owner')] as $action) { $reject($action, 'resource_not_found'); }
    $quiz->setAdminContext($adminA); $auth->authenticate('a@example.test', 'password-teacher-a');
    $before = $state(); $_GET = []; $_POST = ['id' => $sid, 'student_id' => $studentId, 'generation' => $generation(), 'reason' => 'CSRF refusal', 'scopes' => ['browser']];
    foreach (['grant', 'revoke'] as $operation) {
        $_POST['override_id'] = $grantId; unset($_POST['_csrf']); $_SERVER['REQUEST_METHOD'] = 'GET'; ob_start(); $adminController->handle('/access/override/' . $operation); ob_end_clean(); assertSameValue(404, http_response_code(), 'GET cannot grant/revoke');
        $_SERVER['REQUEST_METHOD'] = 'POST'; ob_start(); $adminController->handle('/access/override/' . $operation); ob_end_clean(); assertSameValue(403, http_response_code(), 'Missing admin CSRF refused');
        $_POST['_csrf'] = 'wrong'; ob_start(); $adminController->handle('/access/override/' . $operation); ob_end_clean(); assertSameValue(403, http_response_code(), 'Wrong admin CSRF refused');
        $_POST['_csrf'] = $_SESSION['quiz_admin_csrf']; $_POST['student_id'] = [$studentId]; ob_start(); $adminController->handle('/access/override/' . $operation); ob_end_clean(); assertSameValue(400, http_response_code(), 'Non-scalar ID never casts'); $_POST['student_id'] = $studentId;
    }
    assertSameValue($before, $state(), 'Dispatch refusals have no mutation or success audit');
    $_POST = []; $_GET = [];
    $quiz->setAdminContext($super); $quiz->transferSession($sid, (int)$adminB['id']); $quiz->setAdminContext($adminA);
    $reject(static fn() => $quiz->listTechnicalOverrides($sid), 'resource_not_found');
    $quiz->setAdminContext($adminB);
    assertSameValue((int)$adminA['id'], $quiz->listTechnicalOverrides($sid)['rows'][0]['grant_actor_id'], 'Transfer changes authorization without rewriting original actor');
    $quiz->deleteStudent($studentId, $sid);
    assertSameValue('Alice', $quiz->listTechnicalOverrides($sid, $studentId)['rows'][0]['first_name'], 'Deletion retains historical student identity');
    assertSameValue(true, str_contains($quiz->exportCsv($sid), 'PRIVATE_REVOKE_REASON'), 'Owner export retains decisions after roster deletion');
    echo "QuizTechnicalOverrideTest: OK (scope isolation, cookie-context fixtures, cycles, atomic rollback, archives, pagination/CSV, privacy, CSRF and ownership)\n";
} finally {
    unset($action, $reject, $state, $fail, $generation, $metadata, $send, $capture, $checkPublic, $renderControls, $adminController, $studentController, $anonymous, $prod, $quiz, $auth, $database, $db, $stmt, $e);
    gc_collect_cycles();
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($tmp);
}
