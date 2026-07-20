<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Services\ApplicationProfile;
use App\Services\ApplicationRouter;

$cases = [
    'library' => [
        '/' => 'library', '/quiz' => 'unavailable', '/quiz/room' => 'unavailable',
        '/quiz-admin' => 'unavailable', '/sync' => 'sync', '/debug' => 'diagnostic',
        '/debug.php' => 'diagnostic', '/course/file.md' => 'library', '/course/file.skill' => 'library',
    ],
    'quiz' => [
        '/' => 'quiz', '/quiz' => 'quiz', '/quiz/room' => 'quiz',
        '/quiz-admin' => 'quiz_admin', '/sync' => 'unavailable', '/debug' => 'diagnostic',
        '/debug.php' => 'diagnostic', '/course/file.md' => 'unavailable', '/course/file.skill' => 'unavailable',
    ],
    'hybrid' => [
        '/' => 'library', '/quiz' => 'quiz', '/quiz/room' => 'quiz',
        '/quiz-admin' => 'quiz_admin', '/sync' => 'sync', '/debug' => 'diagnostic',
        '/debug.php' => 'diagnostic', '/course/file.md' => 'library', '/course/file.skill' => 'library',
    ],
];

foreach ($cases as $mode => $routes) {
    $router = new ApplicationRouter(ApplicationProfile::fromBranding(['app_mode' => $mode]));
    foreach ($routes as $path => $expected) {
        assertSameValue($expected, $router->classify($path), $mode . ' route ' . $path);
        assertSameValue($expected, $router->classify('/index.php' . ($path === '/' ? '' : $path)), $mode . ' index.php route ' . $path);
    }
    assertSameValue('public_resource', $router->classify('/assets/css/main.css'), $mode . ' assets remain public');
    assertSameValue('public_resource', $router->classify('/robots.txt'), $mode . ' SEO remains public');
    assertSameValue('public_resource', $router->classify('/favicon.ico?cache=1'), $mode . ' favicon query remains public');
    assertSameValue('public_resource', $router->classify('/index.php/sitemap.xml?cache=1'), $mode . ' index SEO query remains public');
    $fallback = $mode === 'quiz' ? 'unavailable' : 'library';
    assertSameValue($fallback, $router->classify('/nested/favicon.ico'), $mode . ' nested favicon must not match');
    assertSameValue($fallback, $router->classify('/nested/robots.txt'), $mode . ' nested robots must not match');
    assertSameValue($fallback, $router->classify('/nested/sitemap.xml'), $mode . ' nested sitemap must not match');
}

$stopped = new ApplicationRouter(ApplicationProfile::fromBranding([
    'app_mode' => 'quiz',
    'quiz' => ['enabled' => false],
]));
assertSameValue('maintenance', $stopped->classify('/'), 'A stopped quiz-only homepage must be unavailable');
assertSameValue('unavailable', $stopped->classify('/quiz'), 'Stopped quiz subroutes must be hidden');
assertSameValue('unavailable', $stopped->classify('/quiz-admin'), 'Stopped admin routes must be hidden');

echo "ApplicationRouterTest: OK\n";
