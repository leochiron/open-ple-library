<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Services\QuizService;
use App\Services\QuizDbService;
use App\Services\QuizAdminAuthService;

final class PreparationAlertFixture extends QuizService
{
    public int $clock = 100000;
    protected function now(): string { return gmdate('Y-m-d H:i:s', $this->clock); }
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ple-preparation-alerts-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
try {
    $_SESSION = [];
    $database = new QuizDbService($tmp); $db = $database->pdo();
    $auth = new QuizAdminAuthService($database, false);
    $owner = $auth->createAdmin('teacher@example.test', 'Teacher', 'test-password-strong', 'quiz_admin', false);
    $quiz = new PreparationAlertFixture($database, ['branding' => ['quiz_hmac_secret' => str_repeat('p', 64)]], $tmp);
    $quiz->setAdminContext($owner);
    $rules = ['title' => 'Preparation', 'google_form_url' => 'https://docs.google.com/forms/d/e/test/viewform',
        'attempt_entry_id' => '123456', 'duration_minutes' => 15, 'max_incidents' => 2, 'min_away_seconds' => 5];
    $sid = (int)$quiz->createSession($rules, 'Alice Alpha alice@example.test')['id'];
    $quiz->openLobby($sid); $student = $quiz->listStudents($sid)[0];
    $attempt = $quiz->joinAttempt($quiz->getSession($sid), $student['code']); $aid = (int)$attempt['id'];
    $_SESSION['quiz_attempt_id'] = $aid;
    $quiz->setTrackingMode($sid, 'continuous', 0, 'Preparation alert regression'); $quiz->launch($sid);
    $epoch = $quiz->openTrackingRoomDocument($sid, $aid);
    $generation = $quiz->getSession($sid)['tracking_generation'];
    $challenge = $quiz->createTrackingChallenge($sid, $aid, $generation, $epoch);
    $quiz->clock += 12;
    $event = static fn(string $type) => $quiz->recordEvent($quiz->getSession($sid), $quiz->getAttempt($aid), $type, 12);
    $result = $event('hidden');
    assertSameValue(false, $result['is_incident'], 'Requested tab exit is never an incident during the bound preparation');
    assertSameValue(true, $result['preparation_event'], 'Server identifies the preparation without trusting a client exemption');
    assertSameValue([], $quiz->listEventsSince($sid, 0, 100, true), 'Preparation never appears as a projected alert');
    $rules['min_away_seconds'] = 1; $quiz->updateSession($sid, $rules);
    assertSameValue(0, (int)$quiz->getAttempt($aid)['incident_count'], 'Rule changes never requalify a preparation as an incident');
    $challenge = $quiz->createTrackingChallenge($sid, $aid, $generation, $epoch);
    $checks = array_fill_keys(['listener_roundtrip', 'trusted_enter_received', 'hidden_received', 'visible_after_hidden_received', 'focus_after_hidden_received', 'fullscreen_change_received', 'fullscreen_active'], true);
    $quiz->submitTrackingPreflight($sid, $aid, $generation, $epoch, $challenge['challenge'], $checks);
    assertSameValue(true, $event('hidden')['is_incident'], 'Ordinary exits after admission still count');
    $challenge = $quiz->createTrackingChallenge($sid, $aid, $generation, $epoch);
    assertSameValue(false, $event('blur')['is_incident'], 'A new preparation after admission is also protected');
    $quiz->clock += 45;
    assertSameValue(true, $event('blur')['is_incident'], 'An abandoned preparation is bounded to the actual 45-second test');
    $quiz->excuseAttempt($aid);
    assertSameValue(0, (int)$quiz->getAttempt($aid)['incident_count'], 'Whole-student arbitration clears counted incidents');
    assertSameValue('started', $quiz->getAttempt($aid)['status'], 'Arbitration recalculates status');
    assertSameValue(5, count($quiz->listEventsForAttempt($aid)), 'Arbitration preserves the join and all four observations');
    $challenge = $quiz->createTrackingChallenge($sid, $aid, $generation, $epoch);
    $quiz->submitTrackingPreflight($sid, $aid, $generation, $epoch, $challenge['challenge'], $checks);
    $metadata = ['attempt_id' => $aid, 'event_uid' => bin2hex(random_bytes(16)), 'tracking_generation' => $generation, 'source' => 'page'];
    $quiz->recordEvent($quiz->getSession($sid), $quiz->getAttempt($aid), 'finish', 0, $metadata);
    $quiz->clock += 60;
    $receipt = $quiz->prepareTrackingState($sid, $aid);
    assertSameValue(true, $receipt['completion_recorded'], 'Saved completion survives tracking proof expiry');
    assertSameValue(false, $receipt['access_allowed'], 'Receipt grants no permission to the questionnaire');
    assertSameValue(false, isset($receipt['form_url']), 'Expired permission never sends the form URL');
    $quiz->setManualAccess($sid, (int)$student['id'], true, 'Explicit teacher suspension');
    assertSameValue(false, $quiz->prepareTrackingState($sid, $aid)['completion_recorded'], 'Manual suspension still takes precedence');
    echo "QuizPreparationAlertsTest: OK\n";
} finally {
    unset($quiz, $auth, $database, $db);
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) { @unlink($file); }
    @rmdir($tmp);
}
