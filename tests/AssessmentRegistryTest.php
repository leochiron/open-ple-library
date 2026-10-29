<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/AssessmentDefinitions.php';

use App\Services\AssessmentRegistry;
use App\Services\CourseAccessService;
use App\Services\AssessmentSubmissionService;
use App\Services\AssessmentFeedbackService;
use App\Services\AssessmentReviewService;

$tmp = sys_get_temp_dir() . '/ple-registry-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
try {
    assertSameValue([], AssessmentRegistry::load($tmp), 'Fresh deployment has no institution-specific assessments');
    assertSameValue(null, AssessmentSubmissionService::forFolder('training/module/assessment', $tmp), 'No submission without explicit configuration');
    $definitions = assessmentFixture($tmp);
    assertSameValue(2, count(AssessmentRegistry::load($tmp)), 'Both institutions share the configurable engine');
    assertSameValue(1, count(CourseAccessService::requiredAssessments('', true, $tmp)), 'Parent archive respects configured protected scope');
    assertSameValue([], CourseAccessService::requiredAssessments('other-school/module/assessment-copy', false, $tmp), 'Prefix collision does not protect unrelated folder');
    assertSameValue(1, count(CourseAccessService::requiredAssessments('other-school/module/assessment/base.zip', true, $tmp)), 'Child downloads remain protected');
    assertSameValue(2, count((new AssessmentReviewService($tmp))->courses()), 'Every configured deadline is available to review');
    $_SESSION = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    assertSameValue('DEMO-1', AssessmentSubmissionService::forFolder('training/module/assessment', $tmp)['course']['id'], 'Public submission uses configuration');
    assertSameValue('/training/module/feedback', AssessmentFeedbackService::forFolder('training/module/feedback', $tmp)['action'], 'Feedback uses same registry');
    try { AssessmentSubmissionService::forFolder('other-school/module/assessment', $tmp); throw new LogicException('Missing protection'); }
    catch (RuntimeException $expected) {}
    foreach ([['../escape' => $definitions['DEMO-1']], ['BAD' => array_replace($definitions['DEMO-1'], ['scope' => '../escape'])], ['BAD' => array_replace($definitions['DEMO-1'], ['return' => '//evil.test'])], ['BAD' => array_replace($definitions['DEMO-1'], ['access_record' => '../secret'])]] as $invalid) {
        file_put_contents($tmp . '/assessments.json', json_encode(['version' => 1, 'assessments' => $invalid]));
        try { AssessmentRegistry::load($tmp); throw new LogicException('Invalid configuration was accepted'); }
        catch (RuntimeException $expected) {}
    }
    file_put_contents($tmp . '/assessments.json', '{broken');
    try { AssessmentRegistry::load($tmp); throw new LogicException('Corrupt configuration fails open'); }
    catch (RuntimeException $expected) {}
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($tmp);
}
echo "AssessmentRegistryTest: OK\n";
