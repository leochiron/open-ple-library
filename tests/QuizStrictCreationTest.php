<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/app/Helpers/url.php';
use App\Services\QuizDbService;
use App\Services\QuizAdminAuthService;
use App\Services\QuizService;
use App\Services\I18nService;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiz-strict-create-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
try {
    $_SESSION = [];
    $database = new QuizDbService($tmp); $db = $database->pdo();
    $auth = new QuizAdminAuthService($database, false);
    $admin = $auth->createAdmin('teacher@example.test', 'Teacher', 'local-test-password', 'quiz_admin', false);
    $config = ['branding' => ['quiz_hmac_secret' => str_repeat('a', 64)]];
    $quiz = new QuizService($database, $config, $tmp); $quiz->setAdminContext($admin);
    $base = ['title' => 'Test', 'google_form_url' => 'https://docs.google.com/forms/d/e/test/viewform', 'attempt_entry_id' => 'entry.123'];
    $legacyId = $quiz->createSession($base, 'Legacy Learner legacy@example.test')['id'];
    assertSameValue('off', $quiz->getSession($legacyId)['tracking_mode'], 'Legacy callers are not migrated implicitly');
    $strict = $base + ['initial_tracking_mode' => 'continuous', 'initial_browser_enabled' => 'on', 'require_fullscreen' => 'on', 'reload_is_incident' => 'on', 'max_incidents' => 1, 'min_away_seconds' => 5];
    $sid = $quiz->createSession($strict, 'Test Learner learner@example.test')['id']; $created = $quiz->getSession($sid);
    assertSameValue('continuous', $created['tracking_mode'], 'New strict creation persists continuous tracking');
    assertSameValue(1, (int)$created['require_fullscreen'], 'Fullscreen is persisted');
    assertSameValue(1, (int)$created['browser_enabled'], 'Browser policy is persisted with tracking');
    assertSameValue(1, count($quiz->listAudit($sid)['rows']), 'Initial policy is audited');
    assertSameValue('off', $quiz->getSession($legacyId)['tracking_mode'], 'Creating Strict leaves previous sessions intact');
    $before = (int)$db->query('SELECT COUNT(*) FROM quiz_sessions')->fetchColumn();
    try { $quiz->createSession($base + ['initial_tracking_mode' => 'off', 'initial_browser_enabled' => 'on'], 'Learner learner@example.test'); throw new LogicException('Expected invalid policy'); }
    catch (RuntimeException $error) { assertSameValue('browser_requires_tracking', $error->getMessage(), 'Inconsistent policy rejected before creation'); }
    $db->exec("CREATE TRIGGER fail_create BEFORE INSERT ON quiz_session_audit WHEN NEW.action='created' BEGIN SELECT RAISE(ABORT,'failed_creation_audit'); END");
    try { $quiz->createSession($strict, 'Rollback Learner rollback@example.test'); throw new LogicException('Expected rollback'); }
    catch (PDOException $error) { assertSameValue(true, str_contains($error->getMessage(), 'failed_creation_audit'), 'Audit failure exercised'); }
    assertSameValue($before, (int)$db->query('SELECT COUNT(*) FROM quiz_sessions')->fetchColumn(), 'Session, roster and initial policy roll back together');
    $i18n = new I18nService(['default_language' => 'fr'], require dirname(__DIR__) . '/app/Config/i18n.php');
    $sessions = []; $flash = $csrfToken = ''; $showAll = false; $old = [];
    ob_start(); include dirname(__DIR__) . '/app/Views/quiz/admin/index.php'; $html = ob_get_clean();
    assertSameValue(1, preg_match('/id="qa-fullscreen"[^>]*checked/', $html), 'Fresh form defaults to fullscreen');
    assertSameValue(1, preg_match('/value="continuous" selected/', $html), 'Fresh form defaults to continuous');
    $old = ['title' => 'Retry', 'initial_tracking_mode' => 'off', 'max_incidents' => '4', 'min_away_seconds' => '20'];
    ob_start(); include dirname(__DIR__) . '/app/Views/quiz/admin/index.php'; $retry = ob_get_clean();
    assertSameValue(0, preg_match('/id="qa-fullscreen"[^>]*checked/', $retry), 'Validation error preserves unchecked fullscreen');
    assertSameValue(1, preg_match('/value="off" selected/', $retry), 'Validation error preserves the chosen mode');
    echo "QuizStrictCreationTest: OK (initial strict policy, legacy isolation, atomic rollback, form defaults and retry)\n";
} finally {
    unset($quiz, $auth, $database, $db);
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) { if (is_file($file)) { @unlink($file); } }
    @rmdir($tmp);
}
