<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/AssessmentDefinitions.php';
use App\Services\AssessmentFeedbackService as Feedback;
use App\Services\CourseAccessService as Access;
use App\Services\AssessmentSubmissionService as Submission;
$root = sys_get_temp_dir() . '/assessment-feedback-test-' . bin2hex(random_bytes(8));
mkdir($root . '/assessment-feedback/DEMO-1', 0700, true);
assessmentFixture($root);
$subject = 'training/module/assessment';
$returns = 'training/module/feedback';
$records = [hash('sha256', 'first@example.test') => ['student_id' => 'E01', 'html' => '<p>FIRST_ONLY</p>'], hash('sha256', 'second@example.test') => ['student_id' => 'E02', 'html' => '<p>SECOND_ONLY</p>']];
$file = $root . '/assessment-feedback/DEMO-1/results.json';
$dataset = json_encode(['version' => 1, 'assessment' => 'DEMO-1', 'results' => $records]);
file_put_contents($file, $dataset);
$checks = 0;
function check(bool $condition, string $label): void { global $checks; if (!$condition) { throw new RuntimeException($label); } ++$checks; }
function mustThrow(callable $fn, string $label): void { try { $fn(); } catch (RuntimeException $e) { check(true, $label); return; } throw new RuntimeException($label); }
try {
    $_SESSION = []; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '127.0.0.99';
    $state = Feedback::forFolder($subject, $root);
    check(strlen($state['csrf']) === 64 && $state['result'] === null, 'Public subject has no private record');
    check(Feedback::forFolder('unrelated/module', $root) === null, 'Other courses untouched');
    check(Feedback::lookup($root, ' FIRST@EXAMPLE.TEST ', 'DEMO-1')['student_id'] === 'E01', 'Case and surrounding spaces');
    check(Feedback::lookup($root, 'second@example.test', 'DEMO-1')['html'] === '<p>SECOND_ONLY</p>', 'Single record selected');
    check(Feedback::lookup($root, 'unknown@example.test', 'DEMO-1') === null, 'Unknown address does not receive results');
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['assessment_feedback' => '1', 'feedback_csrf' => 'bad', 'feedback_email' => 'first@example.test'];
    check(Feedback::forFolder($returns, $root)['result'] === null && http_response_code() === 403, 'CSRF rejects lookup');
    $_POST['feedback_csrf'] = $state['csrf']; $_POST['feedback_email'] = ['first@example.test'];
    check(Feedback::forFolder($returns, $root)['result'] === null && http_response_code() === 422, 'Array input rejected');
    $_POST['feedback_email'] = ' FIRST@EXAMPLE.TEST ';
    $selected = Feedback::forFolder($returns, $root);
    check($selected['result']['html'] === '<p>FIRST_ONLY</p>' && strpos(json_encode($selected), 'SECOND_ONLY') === false, 'Returned state excludes other student');
    $_POST['feedback_email'] = 'unknown@example.test';
    check(Feedback::forFolder($returns, $root)['result'] === null, 'Unknown form submission');
    for ($i = 0; $i < 100; ++$i) { check(Feedback::consumeAttempt($root, 'shared-campus', 1000), 'Class quota'); }
    check(!Feedback::consumeAttempt($root, 'shared-campus', 1000), 'Quota enforced');
    check(Feedback::consumeAttempt($root, 'shared-campus', 1300), 'Quota expires after five minutes');
    file_put_contents($root . '/assessment-feedback/attempts/' . hash('sha256', 'damaged') . '.json', '{bad');
    mustThrow(fn() => Feedback::consumeAttempt($root, 'damaged', 1000), 'Damaged limiter fails closed');
    file_put_contents($file, '{bad'); mustThrow(fn() => Feedback::lookup($root, 'first@example.test', 'DEMO-1'), 'Invalid dataset fails closed');
    unlink($file); mustThrow(fn() => Feedback::lookup($root, 'first@example.test', 'DEMO-1'), 'Missing dataset fails closed');
    check(Access::requiredAssessments($subject, false) === [], 'Public subject open');
    check(Access::requiredAssessments($subject . '/DEMO-1-base-CC.zip', true) === [], 'Public code base open');
    check(count(Access::requiredAssessments('', true, $root)) === 1, 'Configured protected assessment guarded in archive');
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
    check(Submission::forFolder($subject, $root)['course']['id'] === 'DEMO-1', 'Public submission form still renders without password');
    mustThrow(fn() => Submission::forFolder('other-school/module/assessment', $root), 'Protected submissions still need a grant');
} finally {
    // Remove only the temporary tree created by this test.
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
echo json_encode(['status' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR) . "\n";
