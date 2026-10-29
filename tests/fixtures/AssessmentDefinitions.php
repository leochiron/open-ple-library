<?php
declare(strict_types=1);

/** Synthetic configuration only: never reads deployed course or student data. */
function assessmentFixture(string $storage): array
{
    $definitions = [
        'DEMO-1' => ['scope' => 'training/module/assessment', 'title' => 'Example assessment',
            'return' => '/training/module', 'access' => 'public', 'submission' => true,
            'platform' => 'github', 'deadline' => '2026-10-14T19:00:00+02:00',
            'feedback' => ['subject' => 'training/module/assessment', 'returns' => 'training/module/feedback']],
        'DEMO-2' => ['scope' => 'other-school/module/assessment', 'title' => 'Protected assessment',
            'return' => '/other-school/module', 'access' => 'protected', 'submission' => true,
            'platform' => 'gitlab', 'deadline' => '2026-10-15T19:00:00+02:00'],
    ];
    file_put_contents($storage . '/assessments.json', json_encode(['version' => 1, 'assessments' => $definitions], JSON_THROW_ON_ERROR));
    return $definitions;
}
