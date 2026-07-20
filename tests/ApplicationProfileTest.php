<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Services\ApplicationProfile;

$legacy = ApplicationProfile::fromBranding([]);
assertSameValue('hybrid', $legacy->mode(), 'A missing profile must preserve hybrid behavior');
assertSameValue(true, $legacy->libraryEnabled(), 'Legacy library capability must stay enabled');
assertSameValue(true, $legacy->quizEnabled(), 'Legacy quiz capability must stay enabled');

$library = ApplicationProfile::fromBranding(['app_mode' => 'library']);
assertSameValue(true, $library->libraryEnabled(), 'Library mode must enable the library');
assertSameValue(false, $library->quizEnabled(), 'Library mode must disable quiz routes');

$quiz = ApplicationProfile::fromBranding([
    'app_mode' => 'quiz',
    'quiz' => ['enabled' => true, 'show_admin_link' => false],
]);
assertSameValue(false, $quiz->libraryEnabled(), 'Quiz mode must disable the library');
assertSameValue(true, $quiz->quizAtHomepage(), 'Quiz mode must serve quiz at the homepage');
assertSameValue(false, $quiz->showAdminLink(), 'The admin link setting must be honored');

$stoppedQuiz = ApplicationProfile::fromBranding([
    'app_mode' => 'quiz',
    'quiz' => ['enabled' => false],
]);
assertSameValue(true, $stoppedQuiz->quizHomepageUnavailable(), 'Emergency stop must mark the quiz homepage unavailable');
assertSameValue(false, $stoppedQuiz->quizEnabled(), 'Emergency stop must disable quiz routes');

assertThrows(
    static fn(): ApplicationProfile => ApplicationProfile::fromBranding(['app_mode' => 'unknown']),
    'An unknown profile must fail validation'
);
assertThrows(
    static fn(): ApplicationProfile => ApplicationProfile::fromBranding(['quiz' => 'enabled']),
    'A malformed quiz block must fail validation'
);
assertThrows(
    static fn(): ApplicationProfile => ApplicationProfile::fromBranding(['quiz' => ['enabled' => 1]]),
    'Capability flags must be booleans'
);

echo "ApplicationProfileTest: OK\n";
