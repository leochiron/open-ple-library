<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/app/Helpers/url.php';

use App\Controllers\QuizAdminController;
use App\Services\I18nService;
use App\Services\QuizAdminAuthService;
use App\Services\QuizDbService;
use App\Services\QuizService;

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('QuizIncidentReclassificationTest requires pdo_sqlite');
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ple-reclassification-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);

try {
    $_SESSION = [];
    $database = new QuizDbService($tmp);
    $db = $database->pdo();
    $auth = new QuizAdminAuthService($database, false);
    $admin = $auth->createAdmin('teacher@example.test', 'Teacher', 'test-password-strong', 'quiz_admin', false);
    $quiz = new QuizService($database, ['branding' => ['quiz_hmac_secret' => str_repeat('b', 64)]], $tmp);
    $quiz->setAdminContext($admin);
    $rules = [
        'title' => 'Reclassification test',
        'google_form_url' => 'https://docs.google.com/forms/d/e/test/viewform',
        'attempt_entry_id' => 'entry.123456', 'duration_minutes' => 15,
        'max_incidents' => 2, 'min_away_seconds' => 10,
    ];
    $created = $quiz->createSession($rules, "Alice Alpha alice@example.test\nBob Beta bob@example.test");
    $sid = (int)$created['id'];
    $quiz->openLobby($sid);
    $students = $quiz->listStudents($sid);
    $a = $quiz->joinAttempt($quiz->getSession($sid), $students[0]['code']);
    $b = $quiz->joinAttempt($quiz->getSession($sid), $students[1]['code']);
    $aid = (int)$a['id'];
    $bid = (int)$b['id'];
    $quiz->launch($sid);
    $send = static function (int $attemptId, string $type, int $seconds) use ($quiz, $sid): int {
        $quiz->recordEvent($quiz->getSession($sid), $quiz->getAttempt($attemptId), $type, $seconds);
        return (int)$quiz->listEventsForAttempt($attemptId)[0]['id'];
    };
    $counts = static function (int $expectedA, int $expectedB, string $label) use ($quiz, $aid, $bid): void {
        assertSameValue($expectedA, (int)$quiz->getAttempt($aid)['incident_count'], $label . ': count A');
        assertSameValue($expectedB, (int)$quiz->getAttempt($bid)['incident_count'], $label . ': count B');
    };
    $update = static function (array $newRules) use ($quiz, $sid, &$rules): void {
        $rules = array_merge($rules, $newRules);
        // Checkbox absence represents false in the administration POST.
        foreach (['require_fullscreen', 'reload_is_incident'] as $key) {
            if (array_key_exists($key, $rules) && $rules[$key] === false) { unset($rules[$key]); }
        }
        $quiz->updateSession($sid, $rules);
    };
    $rawEvents = static fn(): array => $db->query('SELECT id, attempt_id, event_type, away_seconds, excused, created_at FROM quiz_events ORDER BY id')->fetchAll();
    $hiddenA = $send($aid, 'hidden', 7);
    $send($bid, 'blur', 12);
    $send($aid, 'fullscreen_exit', 12);
    $send($bid, 'fullscreen_exit', 12);
    $send($aid, 'reload', 0);
    $send($bid, 'reload', 0);
    $send($aid, 'devtools', 0);
    $send($bid, 'leave', 0);
    $initialRaw = $rawEvents();
    $initialVersion = $quiz->rulesVersion($quiz->getSession($sid));
    $counts(0, 1, 'Initial threshold 10 seconds');
    $update(['min_away_seconds' => 5]);
    $counts(1, 1, 'Threshold reduced to 5');
    $update(['min_away_seconds' => 15]);
    $counts(0, 0, 'Threshold increased to 15');
    assertSameValue($initialRaw, $rawEvents(), 'Requalification must preserve every raw event field');
    $staleSession = $quiz->getSession($sid);
    $staleAttempt = $quiz->getAttempt($bid);

    $update(['min_away_seconds' => 5, 'require_fullscreen' => 'on', 'reload_is_incident' => 'on']);
    $counts(3, 3, 'Fullscreen and reload enabled');
    assertSameValue('invalid', $quiz->getAttempt($aid)['status'], 'Quota two invalidates three unexcused incidents');
    $quiz->setEventExcused($hiddenA, true);
    $counts(2, 3, 'Excused absence is excluded from counts');
    $send($aid, 'finish', 0);
    $finishedAt = $quiz->getAttempt($aid)['finished_at'];
    assertSameValue(true, !empty($finishedAt), 'Completion timestamp exists');
    $rawAfterArbitration = $rawEvents();
    $update(['max_incidents' => 3]);
    assertSameValue('suspect', $quiz->getAttempt($aid)['status'], 'Quota three accepts two incidents');
    assertSameValue('invalid', $quiz->getAttempt($bid)['status'], 'Quota three still invalidates three incidents');
    $update(['max_incidents' => 4]);
    assertSameValue('suspect', $quiz->getAttempt($bid)['status'], 'Quota four changes the second status');
    $update(['require_fullscreen' => false]);
    $counts(1, 2, 'Disabling fullscreen removes historical fullscreen incidents');
    $update(['reload_is_incident' => false]);
    $counts(0, 1, 'Disabling reload removes historical reload incidents');
    $update(['min_away_seconds' => 15]);
    $counts(0, 0, 'Higher threshold removes remaining historical absences');
    $update(['min_away_seconds' => 5, 'require_fullscreen' => 'on', 'reload_is_incident' => 'on', 'max_incidents' => 3]);
    $counts(2, 3, 'Reenabling rules preserves the previous arbitration');
    assertSameValue($rawAfterArbitration, $rawEvents(), 'Repeated reclassification preserves raw data, excuses and completion events');
    assertSameValue($finishedAt, $quiz->getAttempt($aid)['finished_at'], 'Finished attempt survives every rule update');
    assertSameValue(1, (int)$db->query('SELECT excused FROM quiz_events WHERE id = ' . $hiddenA)->fetchColumn(), 'Excuse persists when the event becomes incident again');

    // An API request can hold snapshots taken before the teacher changed rules.
    $freshEvent = $quiz->recordEvent($staleSession, $staleAttempt, 'hidden', 7);
    assertSameValue(true, $freshEvent['is_incident'], 'New event uses committed current rules rather than stale request data');
    $counts(2, 4, 'Stale attempt snapshot cannot replace the database count');

    // Exercise the real feed endpoint with a cursor past all existing events.
    $i18n = new I18nService(['default_language' => 'fr'], require dirname(__DIR__) . '/app/Config/i18n.php');
    $controller = new QuizAdminController($quiz, $auth, $i18n, ['branding' => []]);
    $endpoint = new ReflectionMethod($controller, 'apiEvents');
    $endpoint->setAccessible(true);
    $latestId = (int)$db->query('SELECT MAX(id) FROM quiz_events')->fetchColumn();
    $callFeed = static function (array $query) use ($endpoint, $controller): array {
        $_GET = $query;
        ob_start();
        $endpoint->invoke($controller);
        return json_decode((string)ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    };
    $reset = $callFeed(['id' => $sid, 'after' => $latestId, 'rules_version' => $initialVersion]);
    assertSameValue(true, $reset['reset'], 'Changed rules replace already displayed feed history');
    assertSameValue((int)$db->query('SELECT COUNT(*) FROM quiz_events')->fetchColumn(), count($reset['events']), 'Feed snapshot returns existing events despite cursor');
    $currentVersion = $quiz->rulesVersion($quiz->getSession($sid));
    assertSameValue($currentVersion, $reset['rules_version'], 'Feed declares the current qualification version');
    $steady = $callFeed(['id' => $sid, 'after' => $latestId, 'rules_version' => $currentVersion]);
    assertSameValue(false, $steady['reset'], 'Unchanged rules keep incremental feed behavior');
    assertSameValue([], $steady['events'], 'History is not replayed when rules remain unchanged');
    $board = $callFeed(['id' => $sid, 'after' => $latestId]);
    assertSameValue(false, $board['reset'], 'Board without a feed version keeps its cursor');
    assertSameValue([], $board['events'], 'Reclassification creates no old-event notifications on the board');
    $latestTwo = $quiz->listRecentEvents($sid, 2);
    assertSameValue(2, count($latestTwo), 'Recent snapshot is bounded');
    assertSameValue($latestId, (int)$latestTwo[1]['id'], 'Snapshot selects newest events in ascending display order');
    $insertExtra = $db->prepare("INSERT INTO quiz_events (attempt_id, event_type, away_seconds, is_incident) VALUES (:aid, 'copy', 0, 0)");
    for ($extra = 0; $extra < 110; $extra++) { $insertExtra->execute(['aid' => $bid]); }
    $latestId = (int)$db->query('SELECT MAX(id) FROM quiz_events')->fetchColumn();
    $largeFeed = $callFeed(['id' => $sid, 'after' => $latestId, 'rules_version' => $initialVersion]);
    assertSameValue(100, count($largeFeed['events']), 'Reconciliation keeps the newest 100 when history exceeds the feed limit');
    assertSameValue($latestId - 99, $largeFeed['events'][0]['id'], 'Reconciliation does not take the oldest 100 events');
    assertSameValue($latestId, $largeFeed['events'][99]['id'], 'Reconciliation cursor reaches the current latest event');
    assertSameValue(true, (int)$db->query('SELECT COUNT(*) FROM quiz_events')->fetchColumn() > 100, 'Feed limit must not delete stored history');
    $beforeClockChange = $currentVersion;
    $update(['duration_minutes' => 20, 'title' => 'Renamed quiz']);
    assertSameValue($beforeClockChange, $quiz->rulesVersion($quiz->getSession($sid)), 'Clock/title-only changes do not replay or rebuild incident history');

    // Fail after events and the first attempt were updated: all tables must roll back.
    $snapshot = static fn(): array => array_map(static fn(string $table): array => $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(), ['quiz_sessions', 'quiz_attempts', 'quiz_events']);
    $beforeFailure = $snapshot();
    $db->exec("CREATE TEMP TRIGGER fail_reclassification BEFORE UPDATE OF status ON quiz_attempts WHEN OLD.id = $bid BEGIN SELECT RAISE(ABORT, 'test_reclassification_failure'); END");
    try {
        $quiz->updateSession($sid, array_merge($rules, ['min_away_seconds' => 99, 'title' => 'Must roll back']));
        throw new RuntimeException('Reclassification failure must abort the settings update');
    } catch (PDOException $exception) {
        assertSameValue(true, strpos($exception->getMessage(), 'test_reclassification_failure') !== false, 'Expected injected SQL failure');
    }
    assertSameValue(false, $db->inTransaction(), 'Failed update releases its transaction');
    assertSameValue($beforeFailure, $snapshot(), 'Rules, event qualification and counts roll back together');
    $db->exec('DROP TRIGGER fail_reclassification');

    $quiz->stop($sid);
    $stoppedSession = $quiz->getSession($sid);
    $beforeRejectedFinish = $snapshot();
    foreach (['finish', 'resume'] as $type) {
        try {
            $quiz->recordEvent($stoppedSession, $quiz->getAttempt($aid), $type, 0);
            throw new RuntimeException('Completion outside running must be rejected');
        } catch (RuntimeException $exception) {
            assertSameValue('session_not_running', $exception->getMessage(), 'Finish/resume admission remains protected');
        }
    }
    assertSameValue($beforeRejectedFinish, $snapshot(), 'Rejected completion leaves no event or state change');
    echo "QuizIncidentReclassificationTest: OK\n";
} finally {
    $_SESSION = $_GET = [];
    $callFeed = $send = $counts = $update = $rawEvents = $snapshot = null;
    $insertExtra = $controller = $quiz = $auth = $db = $database = null;
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) { @unlink($file); }
    @rmdir($tmp);
}
