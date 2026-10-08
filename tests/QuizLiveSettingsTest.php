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
    throw new RuntimeException('QuizLiveSettingsTest requires pdo_sqlite');
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ple-live-settings-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);

try {
    $_SESSION = [];
    $database = new QuizDbService($tmp);
    $db = $database->pdo();
    $auth = new QuizAdminAuthService($database, false);
    $admin = $auth->createAdmin('teacher@example.test', 'Teacher', 'test-password-strong', 'quiz_admin', false);
    $quiz = new QuizService($database, ['branding' => ['quiz_hmac_secret' => str_repeat('a', 64)]], $tmp);
    $quiz->setAdminContext($admin);
    $rules = [
        'title' => 'Initial quiz',
        'google_form_url' => 'https://docs.google.com/forms/d/e/initial/viewform',
        'attempt_entry_id' => 'entry.123456',
        'duration_minutes' => 15,
        'max_incidents' => 2,
        'min_away_seconds' => 10,
    ];
    $created = $quiz->createSession($rules, "Alice Alpha alice@example.test\nBob Beta bob@example.test");
    $sessionId = (int)$created['id'];
    $quiz->openLobby($sessionId);
    $session = $quiz->getSession($sessionId);
    $students = $quiz->listStudents($sessionId);
    $attemptA = $quiz->joinAttempt($session, $students[0]['code']);
    $attemptB = $quiz->joinAttempt($session, $students[1]['code']);
    $lobby = $quiz->buildStatePayload($session, $attemptA);
    assertSameValue(false, array_key_exists('form_url', $lobby), 'Lobby must not disclose the questionnaire URL');
    assertSameValue(false, $lobby['require_fullscreen'], 'Fullscreen must be typed as a boolean');
    assertSameValue(false, $lobby['reload_is_incident'], 'Reload rule must be typed as a boolean');

    $quiz->launch($sessionId);
    $session = $quiz->getSession($sessionId);
    $before = $quiz->buildStatePayload($session, $attemptA);
    $finishedResult = $quiz->recordEvent($session, $attemptA, 'finish', 0);
    assertSameValue(true, $finishedResult['finished'], 'Finish remains acknowledged by the server');
    $finishedAt = $quiz->getAttempt((int)$attemptA['id'])['finished_at'];

    $rules = array_merge($rules, [
        'title' => 'Updated quiz',
        'google_form_url' => 'https://docs.google.com/forms/d/e/updated/viewform',
        'duration_minutes' => 20,
        'max_incidents' => 3,
        'min_away_seconds' => 5,
        'require_fullscreen' => 'on',
        'reload_is_incident' => 'on',
    ]);
    $quiz->updateSession($sessionId, $rules);
    $session = $quiz->getSession($sessionId);
    $finishedAttempt = $quiz->getAttempt((int)$attemptA['id']);
    $stateA = $quiz->buildStatePayload($session, $finishedAttempt);
    $stateB = $quiz->buildStatePayload($session, $quiz->getAttempt((int)$attemptB['id']));
    $sharedState = $quiz->buildStatePayload($session);
    $expected = [
        'title' => 'Updated quiz', 'duration_minutes' => 20, 'max_incidents' => 3,
        'min_away_seconds' => 5, 'require_fullscreen' => true, 'reload_is_incident' => true,
    ];
    foreach ($expected as $key => $value) {
        assertSameValue($value, $stateA[$key] ?? null, 'Finished student receives current ' . $key);
        assertSameValue($value, $stateB[$key] ?? null, 'Other student receives current ' . $key);
        assertSameValue($value, $sharedState[$key] ?? null, 'Teacher clock receives current ' . $key);
    }
    assertSameValue(true, $stateA['finished'], 'Settings update must preserve completed attempt');
    assertSameValue(false, $stateB['finished'], 'Settings update must not complete another student');
    assertSameValue($finishedAt, $finishedAttempt['finished_at'], 'Settings update keeps finish timestamp');
    assertSameValue(true, abs(($stateB['remaining_seconds'] - $before['remaining_seconds']) - 300) <= 2, 'Duration change extends the server clock by five minutes');

    // Exercise the actual teacher endpoint, not a second copy of its payload.
    $i18n = new I18nService(['default_language' => 'fr'], require dirname(__DIR__) . '/app/Config/i18n.php');
    $controller = new QuizAdminController($quiz, $auth, $i18n, ['branding' => []]);
    $_GET = ['id' => $sessionId];
    $endpoint = new ReflectionMethod($controller, 'apiAttempts');
    $endpoint->setAccessible(true);
    ob_start();
    $endpoint->invoke($controller);
    $teacherState = json_decode((string)ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    foreach ($expected as $key => $value) {
        assertSameValue($value, $teacherState[$key] ?? null, 'Teacher endpoint emits current ' . $key);
    }
    assertSameValue(2, count($teacherState['attempts']), 'Teacher endpoint still exposes both permitted attempts');

    unset($rules['require_fullscreen'], $rules['reload_is_incident']);
    $quiz->updateSession($sessionId, $rules);
    $session = $quiz->getSession($sessionId);
    $disabled = $quiz->buildStatePayload($session, $quiz->getAttempt((int)$attemptA['id']));
    assertSameValue(false, $disabled['require_fullscreen'], 'Fullscreen can be disabled during the same run');
    assertSameValue(false, $disabled['reload_is_incident'], 'Reload rule can be disabled during the same run');
    $resumed = $quiz->recordEvent($session, $quiz->getAttempt((int)$attemptA['id']), 'resume', 0);
    assertSameValue(false, $resumed['finished'], 'Resume remains acknowledged by the server');
    assertSameValue(null, $quiz->getAttempt((int)$attemptA['id'])['finished_at'], 'Resume clears only the finished timestamp');
    echo "QuizLiveSettingsTest: OK\n";
} finally {
    $_SESSION = [];
    $_GET = [];
    $controller = $quiz = $auth = $db = $database = null;
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
}
