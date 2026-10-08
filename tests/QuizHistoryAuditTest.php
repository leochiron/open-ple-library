<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/app/Helpers/url.php';
use App\Services\QuizDbService;
use App\Services\QuizService;
use App\Services\QuizAdminAuthService;
use App\Services\I18nService;
use App\Controllers\QuizAdminController;

final class FrozenHistoryQuiz extends QuizService
{
    public string $fixedTime;
    protected function now(): string { return $this->fixedTime; }
}
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ple-history-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
try {
    $_SESSION = [];
    $database = new QuizDbService($tmp); $db = $database->pdo();
    $auth = new QuizAdminAuthService($database, false);
    $adminA = $auth->createAdmin('a@example.test', 'Teacher A', 'admin-a-password', 'quiz_admin', false);
    $adminB = $auth->createAdmin('b@example.test', 'Teacher B', 'admin-b-password', 'quiz_admin', false);
    $super = $auth->createAdmin('root@example.test', 'Root', 'root-password-strong', 'super_admin', false);
    $config = ['branding' => ['quiz_hmac_secret' => str_repeat('b', 64)]];
    $quiz = new FrozenHistoryQuiz($database, $config, $tmp); $quiz->fixedTime = gmdate('Y-m-d H:i:s');
    $quiz->setAdminContext($adminA);
    $rules = ['title' => 'Frozen rules', 'google_form_url' => 'https://docs.google.com/forms/d/e/test/viewform',
        'attempt_entry_id' => '123456', 'duration_minutes' => 15, 'max_incidents' => 2, 'min_away_seconds' => 10];
    $sid = (int)$quiz->createSession($rules, "Alice Alpha alice@example.test\nBob Beta bob@example.test\nCarol Gamma carol@example.test")['id'];
    $quiz->openLobby($sid); $students = $quiz->listStudents($sid);
    $a = $quiz->joinAttempt($quiz->getSession($sid), $students[0]['code']);
    $b = $quiz->joinAttempt($quiz->getSession($sid), $students[1]['code']);
    $aid = (int)$a['id']; $bid = (int)$b['id'];
    $quiz->launch($sid); $firstGeneration = $quiz->getSession($sid)['tracking_generation'];
    $seq = 0;
    $uid = static function () use (&$seq): string { return str_pad(dechex(++$seq), 32, '0', STR_PAD_LEFT); };
    $metadata = static fn(string $source, array $extra = []): array => array_merge(['attempt_id' => $aid, 'event_uid' => $uid(), 'tracking_generation' => $quiz->getSession($sid)['tracking_generation'], 'source' => $source], $extra);
    $send = static fn(string $type, array $meta = [], int $seconds = 0): array => $quiz->recordEvent($quiz->getSession($sid), $quiz->getAttempt($aid), $type, $seconds, $meta);
    $send('hidden', [], 12); $oldEventId = (int)$quiz->listEventsForAttempt($aid)[0]['id'];
    $quiz->setEventExcused($oldEventId, true); $quiz->setEventExcused($oldEventId, false); $quiz->setEventExcused($oldEventId, true);
    $episode = $uid(); $start = $metadata('hidden', ['absence_uid' => $episode]); $send('away_start', $start);
    $returned = $metadata('hidden', ['absence_uid' => $episode, 'related_event_uid' => $start['event_uid'], 'duration_ms' => 12500]); $send('hidden', $returned);
    $quiz->stop($sid); $quiz->launch($sid); $secondGeneration = $quiz->getSession($sid)['tracking_generation'];
    assertSameValue(false, $firstGeneration === $secondGeneration, 'Ordinary launch renews only tracking nonce');
    assertSameValue(1, (int)$quiz->getAttempt($aid)['incident_count'], 'Ordinary launch retains count');
    assertSameValue([], $quiz->listArchives($sid)['rows'], 'Ordinary launch creates no archive');
    assertSameValue(0, (int)$quiz->getSession($sid)['history_revision'], 'Ordinary launch does not revise history');
    $send('copy', $metadata('shortcut')); $send('finish', $metadata('page'));
    $beforeA = $quiz->getAttempt($aid); $rawA = array_reverse($quiz->listEventsForAttempt($aid));
    $beforeB = $quiz->getAttempt($bid); $rawB = array_reverse($quiz->listEventsForAttempt($bid));
    $quiz->resetAndRelaunch($sid);
    $firstPage = $quiz->listArchives($sid);
    assertSameValue(2, count($firstPage['rows']), 'Every existing attempt archived; no snapshot invented for roster without attempt');
    $archivedAId = (int)array_values(array_filter($firstPage['rows'], static fn($r): bool => (int)$r['source_attempt_id'] === $aid))[0]['id'];
    $archivedBId = (int)array_values(array_filter($firstPage['rows'], static fn($r): bool => (int)$r['source_attempt_id'] === $bid))[0]['id'];
    $archiveA = $quiz->getArchive($archivedAId); $archiveB = $quiz->getArchive($archivedBId);
    assertSameValue($rawA, $archiveA['snapshot']['events'], 'UID/ms/excuse and all raw columns are frozen exactly');
    assertSameValue($rawB, $archiveB['snapshot']['events'], 'Zero-incident attempt journal retained');
    assertSameValue($beforeA['finished_at'], $archiveA['snapshot']['attempt']['finished_at'], 'Finished flag preserved in archive');
    assertSameValue($beforeB['incident_count'], $archiveB['snapshot']['attempt']['incident_count'], 'Zero incidents preserved');
    assertSameValue([$firstGeneration, $secondGeneration], $archiveA['snapshot']['event_generations'], 'Snapshot retains all tracking generations');
    assertSameValue($secondGeneration, $archiveA['generation'], 'Snapshot nonce identifies state at reset, not a single execution');
    assertSameValue(0, (int)$quiz->getAttempt($aid)['incident_count'], 'Current attempt resets to zero');
    assertSameValue(null, $quiz->getAttempt($aid)['finished_at'], 'Current finish resets');
    assertSameValue([], $quiz->listEventsForAttempt($aid), 'Current history starts empty');
    $quiz->resetAndRelaunch($sid);
    assertSameValue(4, count($quiz->listArchives($sid)['rows']), 'Second same-second reset creates another pair of snapshots');
    assertSameValue($archiveA['archived_at'], $quiz->getArchive((int)$quiz->listArchives($sid)['rows'][0]['id'])['archived_at'], 'Frozen server clock proves same-second reset');
    assertSameValue(2, (int)$quiz->getSession($sid)['history_revision'], 'Only resets advance history revision');
    try { $send('hidden', $returned); throw new LogicException('Expected old generation rejection'); }
    catch (RuntimeException $e) { assertSameValue('stale_generation', $e->getMessage(), 'Archived observations cannot enter new current history'); }
    assertSameValue(null, $quiz->setEventExcused($oldEventId, false), 'Old event ID cannot arbitrate its archive');
    $frozenJson = $db->query('SELECT snapshot_json FROM quiz_attempt_archives WHERE id = ' . $archivedAId)->fetchColumn();
    $rules['title'] = 'Current edited'; $rules['min_away_seconds'] = 1; $quiz->updateSession($sid, $rules);
    $quiz->excuseAttempt($aid);
    $quiz->updateStudent((int)$beforeA['student_id'], $sid, ['first_name' => 'Renamed', 'last_name' => 'Changed', 'email' => 'new@example.test']);
    assertSameValue($frozenJson, $db->query('SELECT snapshot_json FROM quiz_attempt_archives WHERE id = ' . $archivedAId)->fetchColumn(), 'Current rules, arbitration and identity edits never mutate archive');

    $state = static function () use ($db): array {
        $all = [];
        foreach (['quiz_sessions', 'quiz_attempts', 'quiz_events', 'quiz_attempt_archives', 'quiz_session_audit'] as $table) { $all[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(); }
        return $all;
    };
    $fail = static function (string $trigger, callable $action) use ($db, $state): void {
        $before = $state(); $db->exec($trigger);
        try { $action(); throw new LogicException('Expected injected failure'); }
        catch (PDOException $e) { assertSameValue(true, str_contains($e->getMessage(), 'injected_failure'), 'Injected SQL error reaches caller'); }
        finally { $db->exec('DROP TRIGGER fail_history'); }
        assertSameValue($before, $state(), 'Failure rolls back settings, events, attempts, archives and audit together');
        assertSameValue(false, $db->inTransaction(), 'Failure leaves no open transaction');
    };
    $send('hidden', [], 12);
    $incidentId = (int)$quiz->listEventsForAttempt($aid)[0]['id'];
    $fail("CREATE TRIGGER fail_history BEFORE INSERT ON quiz_attempt_archives WHEN NEW.source_attempt_id = $bid BEGIN SELECT RAISE(ABORT, 'injected_failure'); END", static fn() => $quiz->resetAndRelaunch($sid));
    $auditTrigger = "CREATE TRIGGER fail_history BEFORE INSERT ON quiz_session_audit BEGIN SELECT RAISE(ABORT, 'injected_failure'); END";
    foreach ([static fn() => $quiz->resetAndRelaunch($sid), static fn() => $quiz->updateSession($sid, array_merge($rules, ['min_away_seconds' => 99])),
        static fn() => $quiz->launch($sid), static fn() => $quiz->stop($sid), static fn() => $quiz->close($sid),
        static fn() => $quiz->setEventExcused($incidentId, true), static fn() => $quiz->excuseAttempt($aid)] as $action) { $fail($auditTrigger, $action); }
    $quiz->close($sid); $fail($auditTrigger, static fn() => $quiz->openLobby($sid));
    $quiz->openLobby($sid); $quiz->launch($sid);

    // Volume bounds must refuse everything, never silently truncate.
    $archivesBefore = (int)$db->query('SELECT COUNT(*) FROM quiz_attempt_archives')->fetchColumn();
    $revisionBefore = (int)$quiz->getSession($sid)['history_revision'];
    $stmt = $db->prepare('UPDATE quiz_events SET source = :source WHERE id = :id');
    $stmt->execute(['source' => str_repeat('x', 8388608), 'id' => $incidentId]);
    try { $quiz->resetAndRelaunch($sid); throw new LogicException('Expected oversized archive refusal'); }
    catch (RuntimeException $e) { assertSameValue('archive_size_limit', $e->getMessage(), 'Oversized snapshot is refused'); }
    assertSameValue($archivesBefore, (int)$db->query('SELECT COUNT(*) FROM quiz_attempt_archives')->fetchColumn(), 'Volume refusal inserts no snapshots');
    assertSameValue($revisionBefore, (int)$quiz->getSession($sid)['history_revision'], 'Volume refusal changes no revision');
    $stmt->execute(['source' => null, 'id' => $incidentId]); $stmt = null;
    $db->exec("WITH RECURSIVE nums(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM nums WHERE n<20001) INSERT INTO quiz_events(attempt_id,event_type) SELECT $bid, 'copy' FROM nums");
    try { $quiz->resetAndRelaunch($sid); throw new LogicException('Expected event-count refusal'); }
    catch (RuntimeException $e) { assertSameValue('archive_event_limit', $e->getMessage(), 'Event count bound rejects reset'); }
    assertSameValue(20001, count($quiz->listEventsForAttempt($bid)), 'All excessive raw events remain available after refusal');
    $db->exec('DELETE FROM quiz_events WHERE attempt_id = ' . $bid);

    // Lists remain metadata-only, keyset-bounded; exports retain every snapshot.
    for ($i = 0; $i < 13; $i++) { $quiz->resetAndRelaunch($sid); }
    $page = $quiz->listArchives($sid, 0, 2);
    assertSameValue(2, count($page['rows']), 'Archive page limit');
    assertSameValue(false, array_key_exists('snapshot_json', $page['rows'][0]), 'Metadata listing never loads snapshot JSON');
    $next = $quiz->listArchives($sid, $page['next_before'], 2);
    assertSameValue(true, (int)$next['rows'][0]['id'] < (int)$page['rows'][1]['id'], 'Keyset pagination has no duplicate');
    $archiveOnly = $quiz->exportArchiveCsv($archivedAId); $csv = $quiz->exportCsv($sid);
    assertSameValue(true, str_contains($archiveOnly, ';12500;ms;'), 'Archived CSV keeps raw millisecond precision');
    assertSameValue(true, str_contains($csv, 'archive_tentative') && str_contains($csv, ';audit;'), 'CSV contains separate archive and audit sections');
    assertSameValue((int)$db->query('SELECT COUNT(*) FROM quiz_attempt_archives')->fetchColumn(), substr_count($csv, 'archive_tentative;'), 'Export includes all archive pages');
    $stream = fopen('php://temp', 'w+'); $quiz->writeExportCsv($sid, $stream); rewind($stream);
    assertSameValue($csv, stream_get_contents($stream), 'HTTP spooled export matches compatibility CSV'); fclose($stream);
    foreach ([$students[0]['code'], $a['public_token'], 'admin-a-password', 'quiz_hmac_secret'] as $secret) {
        assertSameValue(false, str_contains($frozenJson, $secret), 'Snapshot excludes credentials and secrets');
    }
    $audit = $quiz->listAudit($sid);
    assertSameValue((int)$adminA['id'], (int)$audit['rows'][0]['actor_admin_id'], 'Audit actor is the authenticated administrator');
    $allAudit = $db->query('SELECT before_json, after_json FROM quiz_session_audit')->fetchAll();
    assertSameValue(false, str_contains(json_encode($allAudit), $students[0]['code']), 'Audit excludes student access codes');
    $rules['google_form_url'] .= '?entry.123=PRIVATE_PREFILL'; $rules['attempt_entry_id'] = '654321'; $rules['google_form_edit_url'] = 'https://docs.google.com/forms/d/EDITFORM/edit';
    $quiz->updateSession($sid, $rules); $last = $quiz->listAudit($sid)['rows'][0];
    assertSameValue(false, $last['before'] === $last['after'], 'URL/entry-only edit has meaningful audit values');
    assertSameValue(false, str_contains(json_encode($last), 'PRIVATE_PREFILL'), 'Audit strips prefilled query values');
    assertSameValue(true, $last['before']['google_form_url_hash'] !== $last['after']['google_form_url_hash'], 'Query edits remain distinguishable without their values');

    $hidden = static function (callable $action): void {
        try { $result = $action(); assertSameValue(null, $result, 'Unauthorized resource appears absent'); }
        catch (RuntimeException $e) { assertSameValue(true, in_array($e->getMessage(), ['resource_not_found', 'admin_auth_required'], true), 'Unauthorized service read is refused'); }
    };
    $anonymous = new QuizService($database, $config, $tmp);
    foreach ([static fn() => $anonymous->getArchive($archivedAId), static fn() => $anonymous->listArchives($sid), static fn() => $anonymous->listAudit($sid), static fn() => $anonymous->exportCsv($sid), static fn() => $anonymous->exportArchiveCsv($archivedAId)] as $action) { $hidden($action); }
    $quiz->setAdminContext($adminB);
    foreach ([static fn() => $quiz->getArchive($archivedAId), static fn() => $quiz->listArchives($sid), static fn() => $quiz->listAudit($sid), static fn() => $quiz->exportArchiveCsv($archivedAId), static fn() => $quiz->exportCsv($sid)] as $action) { $hidden($action); }
    $quiz->setAdminContext($super); $quiz->transferSession($sid, (int)$adminB['id']);
    $quiz->setAdminContext($adminA); $hidden(static fn() => $quiz->getArchive($archivedAId)); $hidden(static fn() => $quiz->listAudit($sid));
    $quiz->setAdminContext($adminB); $afterTransfer = $quiz->getArchive($archivedAId);
    assertSameValue($archiveA, $afterTransfer, 'New owner reads immutable historical identity and original actor');
    $quiz->deleteStudent((int)$beforeA['student_id'], $sid);
    assertSameValue(null, $quiz->getAttempt($aid), 'Current attempt deleted');
    assertSameValue($archiveA, $quiz->getArchive($archivedAId), 'Deleting current student leaves independent archive intact');
    assertSameValue(true, str_contains($quiz->exportArchiveCsv($archivedAId), 'Alice'), 'Archived CSV uses frozen identity after deletion');
    assertSameValue([], $db->query('PRAGMA foreign_key_list(quiz_attempt_archives)')->fetchAll(), 'Archive has no deletable-parent foreign key');

    // Exercise actual private report endpoint and feed reset reconciliation.
    $i18n = new I18nService(['default_language' => 'fr'], require dirname(__DIR__) . '/app/Config/i18n.php');
    $controller = new QuizAdminController($quiz, $auth, $i18n, []);
    $_GET = ['id' => $archivedAId]; $report = new ReflectionMethod($controller, 'archiveReport'); $report->setAccessible(true);
    ob_start(); $report->invoke($controller); $html = (string)ob_get_clean();
    assertSameValue(true, str_contains($html, 'Copie avant remise à zéro') && str_contains($html, 'Frozen rules') && str_contains($html, '12.500 s'), 'Archive report uses frozen rules and raw durations');
    assertSameValue(false, str_contains($html, $a['public_token']) || str_contains($html, 'event/excuse'), 'Archive report exposes no token or arbitration form');
    $subjectAudit = $db->query("SELECT * FROM quiz_session_audit WHERE action = 'episode_excused' ORDER BY id LIMIT 1")->fetch();
    $subjectAudit['before'] = json_decode($subjectAudit['before_json'], true, 512, JSON_THROW_ON_ERROR);
    $subjectAudit['after'] = json_decode($subjectAudit['after_json'], true, 512, JSON_THROW_ON_ERROR);
    assertSameValue('Alice', $subjectAudit['before']['first_name'], 'Arbitration audit freezes the student identity before later edit/deletion');
    $session = $quiz->getSession($sid); $attemptFilter = 0; $archives = ['rows' => [], 'next_before' => null];
    $audit = ['rows' => [$subjectAudit], 'next_before' => null];
    ob_start(); include dirname(__DIR__) . '/app/Views/quiz/admin/history.php'; $historyHtml = (string)ob_get_clean();
    assertSameValue(true, str_contains($historyHtml, 'Alice Alpha') && str_contains($historyHtml, 'attempt_id=' . $aid) && str_contains($historyHtml, 'Observation #' . $oldEventId), 'History explicitly links the unchanged selectors and frozen subject of an arbitration');
    $feed = new ReflectionMethod($controller, 'apiEvents'); $feed->setAccessible(true);
    $_GET = ['id' => $sid, 'after' => 999999, 'rules_version' => $quiz->rulesVersion($quiz->getSession($sid)), 'history_revision' => 0];
    ob_start(); $feed->invoke($controller); $json = json_decode((string)ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    assertSameValue(true, $json['reset'], 'History revision resets live feed even with unchanged rule version');
    assertSameValue([], $json['events'], 'Current feed never loads archived events');
    echo "QuizHistoryAuditTest: OK (same-second reset, frozen snapshots, rollback, volumes, pagination/CSV, ownership, report/feed)\n";
} finally {
    unset($action, $hidden, $state, $fail, $send, $metadata, $report, $feed, $controller, $anonymous, $quiz, $auth, $database, $db, $stmt, $e);
    gc_collect_cycles();
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($tmp);
}
