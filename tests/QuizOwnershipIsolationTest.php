<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Services\QuizAdminAuthService;
use App\Services\QuizDbService;
use App\Services\QuizService;

if (!extension_loaded('pdo_sqlite')) {
    echo "QuizOwnershipIsolationTest: skipped (pdo_sqlite unavailable)\n";
    exit(0);
}

/** Assert the deliberately opaque error used for cross-owner resources. */
function assertResourceHidden(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        assertSameValue('resource_not_found', $exception->getMessage(), $message);
        return;
    }

    throw new RuntimeException($message . ' (resource was not hidden)');
}

function quizData(string $title): array
{
    return [
        'title' => $title,
        'google_form_url' => 'https://docs.google.com/forms/d/e/example/viewform',
        'attempt_entry_id' => 'entry.123456',
        'duration_minutes' => 30,
        'max_incidents' => 2,
        'min_away_seconds' => 5,
    ];
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ple-quiz-ownership-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);

try {
    $_SESSION = [];
    $database = new QuizDbService($tmp);
    $db = $database->pdo();
    $auth = new QuizAdminAuthService($database, false);

    $super = $auth->createAdmin('root@example.test', 'Root', 'root-password-strong', QuizAdminAuthService::ROLE_SUPER_ADMIN, false);
    $adminA = $auth->createAdmin('a@example.test', 'Admin A', 'admin-a-password', QuizAdminAuthService::ROLE_QUIZ_ADMIN, false);
    $adminB = $auth->createAdmin('b@example.test', 'Admin B', 'admin-b-password', QuizAdminAuthService::ROLE_QUIZ_ADMIN, false);

    $quiz = new QuizService($database, ['branding' => ['quiz_hmac_secret' => str_repeat('a', 64)]], $tmp);
    try {
        $quiz->createSession(quizData('No owner'), '');
        throw new RuntimeException('Creating a quiz without an admin context must fail');
    } catch (RuntimeException $exception) {
        assertSameValue('admin_auth_required', $exception->getMessage(), 'Creation must require a named administrator');
    }

    $quiz->setAdminContext($adminA);
    $createdA = $quiz->createSession(quizData('Quiz A'), "Alice Alpha alice@example.test");
    $quiz->setAdminContext($adminB);
    $createdB = $quiz->createSession(quizData('Quiz B'), "Bob Beta bob@example.test");

    assertSameValue((int)$adminA['id'], (int)$db->query('SELECT owner_admin_id FROM quiz_sessions WHERE id = ' . (int)$createdA['id'])->fetchColumn(), 'Creation must force owner A');
    assertSameValue((int)$adminB['id'], (int)$db->query('SELECT owner_admin_id FROM quiz_sessions WHERE id = ' . (int)$createdB['id'])->fetchColumn(), 'Creation must force owner B');

    $quiz->setAdminContext($adminA);
    assertSameValue([(int)$createdA['id']], array_map('intval', array_column($quiz->listSessions(), 'id')), 'Admin A must list only owned quizzes');
    assertSameValue(null, $quiz->getSession((int)$createdB['id']), 'A foreign quiz detail must look absent');
    assertResourceHidden(
        static fn() => $quiz->updateSession((int)$createdB['id'], quizData('Illicit update')),
        'Admin A must not update quiz B'
    );
    assertSameValue('Quiz B', (string)$db->query('SELECT title FROM quiz_sessions WHERE id = ' . (int)$createdB['id'])->fetchColumn(), 'Rejected update must leave quiz B unchanged');
    assertResourceHidden(
        static fn() => $quiz->listStudents((int)$createdB['id']),
        'Admin A must not read quiz B roster'
    );
    assertResourceHidden(
        static fn() => $quiz->openLobby((int)$createdB['id']),
        'Admin A must not change quiz B lifecycle'
    );
    assertResourceHidden(
        static fn() => $quiz->exportCsv((int)$createdB['id']),
        'Admin A must not export quiz B'
    );
    assertResourceHidden(
        static fn() => $quiz->transferSession((int)$createdA['id'], (int)$adminB['id']),
        'A regular admin must not transfer even an owned quiz'
    );

    // Attempt/event identifiers must be isolated too, not only session URLs.
    $studentB = $db->query('SELECT id FROM quiz_students WHERE session_id = ' . (int)$createdB['id'])->fetchColumn();
    $db->exec("INSERT INTO quiz_attempts (session_id, student_id, public_token) VALUES (" . (int)$createdB['id'] . ', ' . (int)$studentB . ", 'token-b')");
    $attemptB = (int)$db->lastInsertId();
    $db->exec("INSERT INTO quiz_events (attempt_id, event_type, is_incident) VALUES ($attemptB, 'hidden', 1)");
    $eventB = (int)$db->lastInsertId();
    assertSameValue(null, $quiz->getAttempt($attemptB), 'Foreign attempt must look absent');
    assertResourceHidden(static fn() => $quiz->listEventsForAttempt($attemptB), 'Foreign attempt events must remain hidden');
    assertResourceHidden(static fn() => $quiz->setEventExcused($eventB, true), 'Admin A must not arbitrate an incident owned by B');
    assertSameValue(0, (int)$db->query("SELECT excused FROM quiz_events WHERE id = $eventB")->fetchColumn(), 'Rejected arbitration must not mutate the event');

    $quiz->setAdminContext($super);
    assertSameValue(0, count($quiz->listSessions()), 'Super-admin default view must remain scoped to owned quizzes');
    assertSameValue(2, count($quiz->listSessions(true)), 'Super-admin explicit all view must include every owner');
    assertSameValue((int)$createdB['id'], (int)($quiz->getSession((int)$createdB['id'])['id'] ?? 0), 'Super-admin may inspect every quiz');
    $quiz->transferSession((int)$createdB['id'], (int)$adminA['id']);

    $quiz->setAdminContext($adminA);
    assertSameValue(2, count($quiz->listSessions()), 'Transferred quiz must become visible to its new owner');
    $quiz->setAdminContext($adminB);
    assertSameValue(0, count($quiz->listSessions()), 'Transferred quiz must disappear from its former owner');

    echo "QuizOwnershipIsolationTest: OK\n";
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
