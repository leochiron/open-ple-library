<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/app/Helpers/url.php';

use App\Services\QuizDbService;
use App\Services\QuizService;
use App\Services\QuizAdminAuthService;
use App\Services\I18nService;
use App\Controllers\QuizController;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ple-journal-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
try {
    $_SESSION = [];
    $database = new QuizDbService($tmp);
    $db = $database->pdo();
    $auth = new QuizAdminAuthService($database, false);
    $admin = $auth->createAdmin('teacher@example.test', 'Teacher', 'test-password-strong', 'quiz_admin', false);
    $quiz = new QuizService($database, ['branding' => ['quiz_hmac_secret' => str_repeat('b', 64)]], $tmp);
    $quiz->setAdminContext($admin);
    $rules = ['title' => 'Journal test', 'google_form_url' => 'https://docs.google.com/forms/d/e/test/viewform',
        'attempt_entry_id' => '123456', 'duration_minutes' => 15, 'max_incidents' => 2, 'min_away_seconds' => 10];
    $sid = (int)$quiz->createSession($rules, "Alice Alpha alice@example.test\nBob Beta bob@example.test")['id'];
    $quiz->openLobby($sid);
    $students = $quiz->listStudents($sid);
    $a = $quiz->joinAttempt($quiz->getSession($sid), $students[0]['code']);
    $b = $quiz->joinAttempt($quiz->getSession($sid), $students[1]['code']);
    $aid = (int)$a['id']; $bid = (int)$b['id'];
    $legacy = $db->query('SELECT * FROM quiz_events ORDER BY id')->fetchAll();
    // Reconstruct the pre-journal schema, then run the real additive upgrader.
    $db->exec('DROP INDEX idx_quiz_events_uid'); $db->exec('DROP INDEX idx_quiz_events_absence');
    foreach (['event_uid', 'absence_uid', 'source', 'related_event_uid', 'tracking_generation', 'duration_ms', 'dropped_events'] as $column) {
        $db->exec('ALTER TABLE quiz_events DROP COLUMN ' . $column);
    }
    $db->exec('ALTER TABLE quiz_sessions DROP COLUMN tracking_generation');
    $upgraded = new QuizDbService($tmp);
    assertSameValue('lobby', $quiz->getSession($sid)['state'], 'Additive upgrade keeps session state');
    assertSameValue($legacy, $db->query('SELECT * FROM quiz_events ORDER BY id')->fetchAll(), 'Additive upgrade preserves raw legacy rows including null metadata');
    assertSameValue(32, strlen($quiz->getSession($sid)['tracking_generation']), 'Old session obtains a generation');
    $quiz->launch($sid);
    $generation = $quiz->getSession($sid)['tracking_generation'];
    $seq = 0;
    $uid = static function () use (&$seq): string { return str_pad(dechex(++$seq), 32, '0', STR_PAD_LEFT); };
    $metadata = static function (int $attempt, string $source, array $extra = []) use ($uid, &$generation): array {
        return array_merge(['attempt_id' => $attempt, 'event_uid' => $uid(), 'tracking_generation' => $generation, 'source' => $source], $extra);
    };
    $send = static fn(int $id, string $type, array $meta = [], int $seconds = 0): array => $quiz->recordEvent($quiz->getSession($sid), $quiz->getAttempt($id), $type, $seconds, $meta);
    $event = static function (string $uid) use ($db): array {
        $stmt = $db->prepare('SELECT * FROM quiz_events WHERE event_uid = :uid'); $stmt->execute(['uid' => $uid]); return $stmt->fetch();
    };
    $count = static fn(int $id): int => (int)$quiz->getAttempt($id)['incident_count'];
    $reject = static function (callable $action, string $reason) use ($db): void {
        $before = $db->query('SELECT * FROM quiz_events ORDER BY id')->fetchAll();
        try { $action(); throw new LogicException('Expected rejection: ' . $reason); }
        catch (RuntimeException $e) { assertSameValue($reason, $e->getMessage(), 'Exact internal validation reason'); }
        assertSameValue($before, $db->query('SELECT * FROM quiz_events ORDER BY id')->fetchAll(), 'Rejected event has no mutation');
    };
    $update = static function (array $changes) use ($quiz, $sid, &$rules): void {
        $rules = array_merge($rules, $changes);
        foreach (['require_fullscreen', 'reload_is_incident'] as $field) { if (($rules[$field] ?? null) === false) { unset($rules[$field]); } }
        $quiz->updateSession($sid, $rules);
    };
    $start = static function (int $id, string $source, string $episode) use ($metadata, $send): array {
        $m = $metadata($id, $source, ['absence_uid' => $episode]); $send($id, 'away_start', $m); return $m;
    };
    $finishSource = static function (int $id, string $source, array $start, int $ms) use ($metadata, $send): array {
        $m = $metadata($id, $source, ['absence_uid' => $start['absence_uid'], 'related_event_uid' => $start['event_uid'], 'duration_ms' => $ms]);
        $send($id, $source, $m); return $m;
    };

    $open = $start($aid, 'hidden', $uid());
    assertSameValue(null, $event($open['event_uid'])['duration_ms'], 'Departure without return has unknown duration');
    assertSameValue(0, $count($aid), 'Departure never qualifies');
    $micro = $finishSource($aid, 'hidden', $open, 750);
    assertSameValue(750, (int)$event($micro['event_uid'])['duration_ms'], 'Micro-absence retains milliseconds');
    assertSameValue(0, (int)$event($micro['event_uid'])['away_seconds'], 'Legacy seconds use floor');
    assertSameValue('0.750 s', quizEventDuration($event($micro['event_uid'])), 'UI preserves measured precision');
    assertSameValue('—', quizEventDuration($event($open['event_uid'])), 'UI never invents an open duration');
    assertSameValue('≥ 3600.000 s', quizEventDuration(['duration_ms' => 3600000]), 'A bounded duration is displayed as a lower bound');
    $outOfOrderStart = $metadata($aid, 'blur', ['absence_uid' => $uid()]);
    $outOfOrderReturn = $metadata($aid, 'blur', ['absence_uid' => $outOfOrderStart['absence_uid'], 'related_event_uid' => $outOfOrderStart['event_uid'], 'duration_ms' => 100]);
    $send($aid, 'blur', $outOfOrderReturn); $send($aid, 'away_start', $outOfOrderStart);
    assertSameValue($outOfOrderStart['event_uid'], $event($outOfOrderReturn['event_uid'])['related_event_uid'], 'Out-of-order beacon preserves correlation');
    $missingStart = $metadata($aid, 'hidden', ['absence_uid' => $uid()]);
    $returnBeforeStart = $metadata($aid, 'hidden', ['absence_uid' => $missingStart['absence_uid'], 'related_event_uid' => $missingStart['event_uid'], 'duration_ms' => 100]);
    $send($aid, 'hidden', $returnBeforeStart);
    $wrongStart = array_merge($missingStart, ['source' => 'blur']);
    $reject(static fn() => $send($aid, 'away_start', $wrongStart), 'invalid_event_relation');
    $update(['min_away_seconds' => 1]);
    $boundaryStart = $start($bid, 'blur', $uid()); $finishSource($bid, 'blur', $boundaryStart, 999);
    assertSameValue(0, $count($bid), '999ms is below a one-second threshold');
    $boundaryStart = $start($bid, 'blur', $uid()); $boundary = $finishSource($bid, 'blur', $boundaryStart, 1000);
    assertSameValue(1, $count($bid), '1000ms reaches a one-second threshold');
    $update(['min_away_seconds' => 10]);

    $episode = $uid();
    $hiddenStart = $start($aid, 'hidden', $episode); $blurStart = $start($aid, 'blur', $episode); $fsStart = $start($aid, 'fullscreen_exit', $episode);
    $hidden = $finishSource($aid, 'hidden', $hiddenStart, 12000); $blur = $finishSource($aid, 'blur', $blurStart, 12000);
    $fs = $finishSource($aid, 'fullscreen_exit', $fsStart, 40000);
    assertSameValue(1, $count($aid), 'Overlapping blur/hidden/fullscreen is one incident');
    assertSameValue(1, (int)$event($hidden['event_uid'])['is_incident'], 'First eligible observation represents episode');
    assertSameValue(0, (int)$event($blur['event_uid'])['is_incident'], 'Second eligible source remains factual without double count');
    $raw = $db->query('SELECT id, event_type, away_seconds, duration_ms, source, absence_uid, related_event_uid, event_uid, created_at FROM quiz_events ORDER BY id')->fetchAll();
    $update(['min_away_seconds' => 15]);
    assertSameValue(0, $count($aid), 'Long fullscreen duration cannot inflate a 12s hidden duration when fullscreen is optional');
    $update(['require_fullscreen' => 'on']);
    assertSameValue(1, $count($aid), 'Required fullscreen qualifies its actual 40s source');
    assertSameValue(1, (int)$event($fs['event_uid'])['is_incident'], 'Representative changes to eligible fullscreen observation');
    $quiz->setEventExcused((int)$event($fs['event_uid'])['id'], true);
    foreach ([$hiddenStart, $blurStart, $fsStart, $hidden, $blur, $fs] as $m) { assertSameValue(1, (int)$event($m['event_uid'])['excused'], 'Excuse applies to complete episode'); }
    $update(['min_away_seconds' => 5]);
    assertSameValue(0, $count($aid), 'Changing representative does not revive an excused episode');
    assertSameValue($raw, $db->query('SELECT id, event_type, away_seconds, duration_ms, source, absence_uid, related_event_uid, event_uid, created_at FROM quiz_events ORDER BY id')->fetchAll(), 'Reclassification preserves all factual columns');
    $anotherStart = $start($aid, 'hidden', $episode); $later = $finishSource($aid, 'hidden', $anotherStart, 7000);
    assertSameValue(1, (int)$event($later['event_uid'])['excused'], 'Late/repeated source observation inherits episode excuse');
    $quiz->setEventExcused((int)$event($hidden['event_uid'])['id'], false);
    assertSameValue(1, $count($aid), 'Reinstatement restores only one representative');
    $beforeArbitration = $db->query('SELECT id, excused FROM quiz_events ORDER BY id')->fetchAll();
    $db->exec("CREATE TRIGGER fail_arbitration BEFORE UPDATE ON quiz_attempts WHEN NEW.incident_count <> OLD.incident_count BEGIN SELECT RAISE(ABORT, 'arbitration_rollback'); END");
    foreach ([static fn() => $quiz->setEventExcused((int)$event($hidden['event_uid'])['id'], true), static fn() => $quiz->excuseAttempt($aid)] as $action) {
        try { $action(); throw new LogicException('Expected arbitration rollback'); }
        catch (PDOException $e) { assertSameValue(true, str_contains($e->getMessage(), 'arbitration_rollback'), 'Injected arbitration failure'); }
        assertSameValue($beforeArbitration, $db->query('SELECT id, excused FROM quiz_events ORDER BY id')->fetchAll(), 'Failed recount rolls back every episode excuse');
        assertSameValue(false, $db->inTransaction(), 'Arbitration error closes its transaction');
    }
    $db->exec('DROP TRIGGER fail_arbitration');
    $quiz->excuseAttempt($aid);
    assertSameValue(0, $count($aid), 'Whole attempt arbitration covers all episode rows');
    $laterStart = $start($aid, 'blur', $episode); $later = $finishSource($aid, 'blur', $laterStart, 20000);
    assertSameValue(1, (int)$event($later['event_uid'])['excused'], 'Whole attempt arbitration also survives a late return');

    $before = count($quiz->listEventsForAttempt($aid));
    $duplicate = $send($aid, 'hidden', $micro);
    assertSameValue(true, $duplicate['duplicate'], 'Network retry acknowledges the existing event');
    assertSameValue($micro['event_uid'], $duplicate['event_uid'], 'Ack binds to exact UID');
    assertSameValue($before, count($quiz->listEventsForAttempt($aid)), 'Retry inserts no duplicate');
    $conflict = array_merge($micro, ['duration_ms' => 751]);
    $reject(static fn() => $send($aid, 'hidden', $conflict), 'event_uid_conflict');
    $reject(static fn() => $send($bid, 'hidden', $micro), 'attempt_mismatch');
    $copy = $metadata($aid, 'shortcut'); $send($aid, 'copy', $copy);
    $sameUidOtherAttempt = array_merge($copy, ['attempt_id' => $bid]); $send($bid, 'copy', $sameUidOtherAttempt);
    assertSameValue(2, (int)$db->query("SELECT COUNT(*) FROM quiz_events WHERE event_uid = '" . $copy['event_uid'] . "'")->fetchColumn(), 'UID uniqueness is scoped to the attempt');

    $finish = $metadata($aid, 'page'); $send($aid, 'finish', $finish);
    $finishedAt = $quiz->getAttempt($aid)['finished_at'];
    $update(['min_away_seconds' => 15, 'max_incidents' => 3]);
    assertSameValue($finishedAt, $quiz->getAttempt($aid)['finished_at'], 'Reclassification preserves completion');
    $resume = $metadata($aid, 'page'); $send($aid, 'resume', $resume);
    $replayedFinish = $send($aid, 'finish', $finish);
    assertSameValue(false, $replayedFinish['finished'], 'Old finish retry after resume reports current state without replaying its effect');
    assertSameValue(null, $quiz->getAttempt($aid)['finished_at'], 'Old finish cannot close a resumed attempt');
    $diag = $metadata($aid, 'queue', ['dropped_events' => 100000]); $send($aid, 'tracking_diagnostic', $diag);
    assertSameValue(0, (int)$event($diag['event_uid'])['is_incident'], 'Diagnostic is informational');
    $invalid = array_merge($diag, ['event_uid' => $uid(), 'dropped_events' => 100001]);
    $reject(static fn() => $send($aid, 'tracking_diagnostic', $invalid), 'invalid_diagnostic');
    foreach ([-1, 3600001, '750'] as $ms) {
        $invalid = array_merge($micro, ['event_uid' => $uid(), 'duration_ms' => $ms]);
        $reject(static fn() => $send($aid, 'hidden', $invalid), 'invalid_event_duration');
    }
    $invalid = array_merge($copy, ['event_uid' => $uid(), 'answer' => 'Forbidden']);
    $reject(static fn() => $send($aid, 'copy', $invalid), 'invalid_event_metadata');
    $invalid = array_merge($copy, ['event_uid' => $uid(), 'source' => 'arbitrary']);
    $reject(static fn() => $send($aid, 'copy', $invalid), 'invalid_event_source');
    $invalid = array_merge($copy, ['event_uid' => str_repeat('a', 81)]);
    $reject(static fn() => $send($aid, 'copy', $invalid), 'invalid_event_metadata');

    $i18n = new I18nService(['default_language' => 'fr'], require dirname(__DIR__) . '/app/Config/i18n.php');
    $controller = new QuizController($quiz, $i18n, []);
    $api = new ReflectionMethod($controller, 'apiEvent'); $api->setAccessible(true);
    $_SESSION['quiz_attempt_id'] = $aid; $_GET = ['attempt_id' => $aid];
    foreach ([['type' => ['malformed']], array_merge(['type' => 'copy'], $copy, ['attempt_id' => $bid])] as $data) {
        $_POST = $data; ob_start(); $api->invoke($controller); $body = json_decode((string)ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        assertSameValue(['error' => 'access_unavailable'], $body, 'Public invalid-payload and binding errors disclose no cause');
    }
    $_POST = array_merge(['type' => 'copy'], $metadata($aid, 'shortcut'));
    $db->exec("CREATE TRIGGER fail_api BEFORE INSERT ON quiz_events BEGIN SELECT RAISE(ABORT, 'private_database_reason'); END");
    ob_start(); $api->invoke($controller); $body = json_decode((string)ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    assertSameValue(503, http_response_code(), 'Database fault remains retryable');
    assertSameValue(['error' => 'access_unavailable'], $body, 'Database fault reason remains private');
    $db->exec('DROP TRIGGER fail_api'); $_POST = []; $_GET = []; http_response_code(200);

    $send($bid, 'blur', [], 20);
    assertSameValue(1, $count($bid), 'Legacy client still qualifies whole seconds');
    $update(['min_away_seconds' => 21]); assertSameValue(0, $count($bid), 'Legacy reclassification falls back to seconds');
    $csv = $quiz->exportCsv($sid);
    assertSameValue(true, str_contains($csv, 'duree_absence_ms;precision;episode;event_uid;depart_uid;traces_perdues;generation'), 'Export exposes precision and correlation');
    assertSameValue(true, str_contains($csv, ';750;ms;'), 'Export retains subsecond precision');
    for ($i = 0; $i < 105; $i++) { $send($aid, 'copy'); }
    assertSameValue(true, count($quiz->listEventsForAttempt($aid)) > 100, 'Full student journal is not capped at 100');
    assertSameValue(true, count(explode("\n", trim($quiz->exportCsv($sid)))) > 100, 'Export retains the full history');
    // Fullscreen overlaps at most the first page episode. Real page returns
    // separate subsequent absences, even if fullscreen is never restored.
    foreach ([$aid, $bid] as $id) {
        $update(['min_away_seconds' => 10, 'require_fullscreen' => false]);
        $quiz->excuseAttempt($id);
        $episodeA = $uid(); $episodeB = $uid();
        if ($id === $aid) { $fsStart = $start($id, 'fullscreen_exit', $episodeA); }
        $pageA = $start($id, 'hidden', $episodeA);
        if ($id === $bid) { $fsStart = $start($id, 'fullscreen_exit', $episodeA); }
        $finishSource($id, 'hidden', $pageA, 12000);
        $pageB = $start($id, 'hidden', $episodeB); $finishSource($id, 'hidden', $pageB, 12000);
        $finishSource($id, 'fullscreen_exit', $fsStart, 54000);
        assertSameValue(2, $count($id), 'Two real page absences with optional fullscreen still exited count twice');
        $update(['require_fullscreen' => 'on']);
        assertSameValue(2, $count($id), 'Requiring fullscreen adds no third incident for the overlapping first episode');
        $update(['min_away_seconds' => 15]);
        assertSameValue(1, $count($id), 'Long fullscreen qualifies only its fixed original episode');
        $quiz->excuseAttempt($id);
    }
    $old = $metadata($aid, 'navigation');
    $quiz->launch($sid); $newGeneration = $quiz->getSession($sid)['tracking_generation'];
    assertSameValue(false, $newGeneration === $generation, 'Rapid relaunch renews generation independently of timestamp precision');
    $reject(static fn() => $send($aid, 'reload', $old), 'stale_generation');
    $generation = $newGeneration;
    $old = $metadata($aid, 'navigation');
    $beforeReset = $db->query('SELECT * FROM quiz_events ORDER BY id')->fetchAll();
    $db->exec("CREATE TRIGGER fail_reset BEFORE UPDATE OF state ON quiz_sessions BEGIN SELECT RAISE(ABORT, 'reset_rollback'); END");
    try { $quiz->resetAndRelaunch($sid); throw new LogicException('Expected reset rollback'); }
    catch (PDOException $e) { assertSameValue(true, str_contains($e->getMessage(), 'reset_rollback'), 'Injected reset failure'); }
    assertSameValue($beforeReset, $db->query('SELECT * FROM quiz_events ORDER BY id')->fetchAll(), 'Reset failure rolls back all deletions');
    assertSameValue($generation, $quiz->getSession($sid)['tracking_generation'], 'Reset failure keeps its original fence');
    assertSameValue(false, $db->inTransaction(), 'Reset failure closes transaction');
    $db->exec('DROP TRIGGER fail_reset');
    $quiz->resetAndRelaunch($sid); $resetGeneration = $quiz->getSession($sid)['tracking_generation'];
    assertSameValue(false, $resetGeneration === $generation, 'Rapid reset also renews generation');
    $reject(static fn() => $send($aid, 'reload', $old), 'stale_generation');
    assertSameValue([], $quiz->listEventsForAttempt($aid), 'Rejected old queue cannot repopulate the reset journal');
    echo "QuizEventJournalTest: OK (migration, sources, idempotence, arbitration, bounds, legacy, generation)\n";
} finally {
    unset($send, $metadata, $start, $finishSource, $count, $update, $reject, $event, $action, $api, $controller, $quiz, $auth, $database, $upgraded, $db);
    gc_collect_cycles();
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($tmp);
}
