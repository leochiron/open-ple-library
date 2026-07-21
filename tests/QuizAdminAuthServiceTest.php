<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Services\QuizAdminAuthService;
use App\Services\QuizDbService;

if (!extension_loaded('pdo_sqlite')) {
    echo "QuizAdminAuthServiceTest: skipped (pdo_sqlite unavailable)\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ple-admin-auth-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);

try {
    $_SESSION = [];
    $database = new QuizDbService($tmp);
    $db = $database->pdo();
    $columns = $db->query('PRAGMA table_info(quiz_sessions)')->fetchAll(PDO::FETCH_COLUMN, 1);
    assertSameValue(true, in_array('owner_admin_id', $columns, true), 'Owner migration must be installed');

    $db->exec("INSERT INTO quiz_sessions (slug, title, google_form_url, attempt_entry_id) VALUES ('legacy', 'Legacy', 'https://docs.google.com/forms/d/e/example/viewform', '123')");
    $auth = new QuizAdminAuthService($database, false);
    $bootstrap = $auth->bootstrapFromConfig([
        'quiz' => ['bootstrap_admin_email' => 'Owner@Example.test', 'bootstrap_admin_name' => 'Owner'],
        'quiz_admin_password' => 'legacy-password-strong',
    ]);
    assertSameValue('owner@example.test', $bootstrap['email'] ?? null, 'Bootstrap email must be normalized');
    assertSameValue(QuizAdminAuthService::ROLE_SUPER_ADMIN, $bootstrap['role'] ?? null, 'First account must be super-admin');
    assertSameValue(null, $auth->bootstrapFromConfig([], 'another-password'), 'Bootstrap must be idempotent');
    assertSameValue((int)$bootstrap['id'], (int)$db->query("SELECT owner_admin_id FROM quiz_sessions WHERE slug = 'legacy'")->fetchColumn(), 'Legacy quizzes must be assigned to the first super-admin');

    $storedHash = (string)$db->query("SELECT password_hash FROM admin_users WHERE email = 'owner@example.test'")->fetchColumn();
    assertSameValue(false, $storedHash === 'legacy-password-strong', 'Password must never be stored in clear text');
    assertSameValue(true, password_verify('legacy-password-strong', $storedHash), 'Password hash must be verifiable');

    $teacher = $auth->createAdmin('teacher@example.test', 'Teacher', 'teacher-password-strong', QuizAdminAuthService::ROLE_QUIZ_ADMIN, false);
    assertSameValue(false, $auth->authenticate('teacher@example.test', 'wrong-password'), 'Wrong password must be rejected');
    assertSameValue(true, $auth->authenticate('TEACHER@example.test', 'teacher-password-strong'), 'Named account must authenticate case-insensitively');
    assertSameValue((int)$teacher['id'], $auth->currentAdmin()['id'] ?? null, 'Session must resolve the named administrator identity');
    assertSameValue('Teacher', $auth->currentAdmin()['display_name'] ?? null, 'Current identity must include the display name loaded from storage');
    assertSameValue((int)$teacher['id'], $_SESSION['quiz_admin_identity'] ?? null, 'Session storage must contain only the administrator ID');

    $auth->setStatus((int)$teacher['id'], QuizAdminAuthService::STATUS_DISABLED);
    assertSameValue(null, $auth->currentAdmin(), 'Disabling an account must invalidate its active session');
    assertSameValue(false, $auth->authenticate('teacher@example.test', 'teacher-password-strong'), 'Disabled account must not authenticate');

    $auth->setStatus((int)$teacher['id'], QuizAdminAuthService::STATUS_ACTIVE);
    $auth->resetPassword((int)$teacher['id'], 'replacement-password', true);
    assertSameValue(false, $auth->authenticate('teacher@example.test', 'teacher-password-strong'), 'Old password must stop working after reset');
    assertSameValue(true, $auth->authenticate('teacher@example.test', 'replacement-password'), 'Reset password must authenticate');
    assertSameValue(true, $auth->currentAdmin()['must_change_password'] ?? null, 'Password reset must force a password change by default');

    assertSameValue(1, $auth->transferSessionsOwnership((int)$bootstrap['id'], (int)$teacher['id']), 'Ownership transfer must update all source sessions');
    assertSameValue((int)$teacher['id'], (int)$db->query("SELECT owner_admin_id FROM quiz_sessions WHERE slug = 'legacy'")->fetchColumn(), 'Transferred session must belong to its new administrator');

    echo "QuizAdminAuthServiceTest: OK\n";
} finally {
    $_SESSION = [];
    $db = null;
    $database = null;
    $files = is_dir($tmp) ? scandir($tmp) : false;
    if (is_array($files)) {
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($tmp . DIRECTORY_SEPARATOR . $file);
            }
        }
    }
    @rmdir($tmp);
}
