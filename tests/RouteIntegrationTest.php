<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!function_exists('proc_open') || !extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "RouteIntegrationTest: SKIPPED (proc_open and pdo_sqlite are required)\n");
    exit(0);
}

$root = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ple-route-test-' . bin2hex(random_bytes(5));
if (!mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
    throw new RuntimeException('Unable to create integration test directory');
}

function removeTestDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($target) ? removeTestDirectory($target) : unlink($target);
    }
    rmdir($path);
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Unable to reserve a test port: ' . $errorMessage);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr(strrchr((string)$name, ':'), 1);
}

/** @return array{0: resource, 1: array<int, resource>, 2: int} */
function startServer(string $root, string $documentRoot, string $frontController, string $brandingPath, string $contentPath): array
{
    $port = freePort();
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $documentRoot, $root . '/tests/HttpTestRouter.php'];
    $pipes = [];
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    $environment['APP_ENV'] = 'testing';
    $environment['PLE_TEST_BRANDING'] = $brandingPath;
    $environment['PLE_TEST_CONTENT_PATH'] = $contentPath;
    $environment['PLE_TEST_FRONT_CONTROLLER'] = $frontController;
    $process = proc_open($command, [STDIN, ['pipe', 'w'], ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the PHP test server');
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    for ($attempt = 0; $attempt < 40; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            return [$process, $pipes, $port];
        }
        usleep(50000);
    }

    $diagnostic = '';
    foreach ($pipes as $pipe) {
        $diagnostic .= stream_get_contents($pipe) ?: '';
    }
    proc_terminate($process);
    throw new RuntimeException('PHP test server did not start: ' . trim($diagnostic));
}

function requestStatus(int $port, string $path): int
{
    $context = stream_context_create(['http' => [
        'ignore_errors' => true,
        'timeout' => 3,
        'follow_location' => 0,
    ]]);
    @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    $headers = $http_response_header ?? [];
    if (!isset($headers[0]) || preg_match('/\s(\d{3})\s/', $headers[0], $match) !== 1) {
        throw new RuntimeException('No HTTP status for ' . $path);
    }
    return (int)$match[1];
}

function requestBody(int $port, string $path): string
{
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 3]]);
    $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    return is_string($body) ? $body : '';
}

function writeBranding(string $path, string $examplePath, string $mode, bool $quizEnabled = true, bool $showAdminLink = true): void
{
    $source = "<?php\n"
        . '$branding = require ' . var_export($examplePath, true) . ";\n"
        . '$branding[\'app_mode\'] = ' . var_export($mode, true) . ";\n"
        . '$branding[\'quiz\'] = [\'enabled\' => ' . ($quizEnabled ? 'true' : 'false')
        . ", 'show_admin_link' => " . ($showAdminLink ? 'true' : 'false') . "];\n"
        . "return \$branding;\n";
    file_put_contents($path, $source);
}

function writeLegacyBranding(string $path, string $examplePath): void
{
    $source = "<?php\n"
        . '$branding = require ' . var_export($examplePath, true) . ";\n"
        . "unset(\$branding['app_mode'], \$branding['quiz']);\n"
        . "return \$branding;\n";
    file_put_contents($path, $source);
}

function createContentFixtures(string $contentPath): void
{
    mkdir($contentPath . DIRECTORY_SEPARATOR . 'course', 0700, true);
    file_put_contents($contentPath . DIRECTORY_SEPARATOR . 'lesson.md', "# Fixture lesson\n");
    file_put_contents($contentPath . DIRECTORY_SEPARATOR . 'package.skill', "fixture skill\n");
    file_put_contents($contentPath . DIRECTORY_SEPARATOR . 'notes.txt', "fixture text\n");
    file_put_contents($contentPath . DIRECTORY_SEPARATOR . 'course' . DIRECTORY_SEPARATOR . 'chapter.md', "# Chapter\n");
}

try {
    $examplePath = $root . '/app/Config/branding.example.php';
    $documentRoots = [
        'public' => [$root . '/public', $root . '/public/index.php'],
        'repository' => [$root, $root . '/index.php'],
    ];

    foreach ($documentRoots as $rootName => [$documentRoot, $router]) {
        foreach (['library', 'quiz', 'hybrid'] as $mode) {
            $casePath = $temporaryRoot . DIRECTORY_SEPARATOR . $rootName . '-' . $mode;
            mkdir($casePath, 0700, true);
            $brandingPath = $casePath . DIRECTORY_SEPARATOR . 'branding.php';
            $contentPath = $casePath . DIRECTORY_SEPARATOR . 'content';
            writeBranding($brandingPath, $examplePath, $mode);
            if ($mode !== 'quiz') {
                createContentFixtures($contentPath);
            }

            [$process, $pipes, $port] = startServer($root, $documentRoot, $router, $brandingPath, $contentPath);
            try {
                $expected = [
                    'library' => ['/' => 200, '/quiz' => 404, '/quiz-admin' => 404, '/sync' => 503],
                    'quiz' => ['/' => 200, '/quiz' => 200, '/quiz-admin' => 503, '/sync' => 404],
                    'hybrid' => ['/' => 200, '/quiz' => 200, '/quiz-admin' => 503, '/sync' => 503],
                ][$mode];
                foreach ($expected as $path => $status) {
                    $actual = requestStatus($port, $path);
                    $indexActual = requestStatus($port, '/index.php' . ($path === '/' ? '' : $path));
                    assertSameValue($status, $actual, $rootName . ' ' . $mode . ' ' . $path);
                    assertSameValue($status, $indexActual, $rootName . ' ' . $mode . ' index variant ' . $path);
                }
                assertSameValue(404, requestStatus($port, '/debug'), $rootName . ' debug route');
                assertSameValue(404, requestStatus($port, '/debug.php'), $rootName . ' debug file');
                if ($rootName === 'repository') {
                    assertSameValue(404, requestStatus($port, '/public/debug.php'), 'repository direct public debug file');
                }
                $assetPath = $rootName === 'public' ? '/assets/css/main.css' : '/public/assets/css/main.css';
                assertSameValue(200, requestStatus($port, $assetPath), $rootName . ' static assets remain available');
                foreach (['/favicon.ico', '/robots.txt', '/sitemap.xml'] as $publicPath) {
                    assertSameValue(200, requestStatus($port, $publicPath . '?cache=1'), $rootName . ' exact public path with query ' . $publicPath);
                    assertSameValue(200, requestStatus($port, '/index.php' . $publicPath . '?cache=1'), $rootName . ' exact index public path ' . $publicPath);
                    assertSameValue(404, requestStatus($port, '/nested' . $publicPath), $rootName . ' nested public lookalike ' . $publicPath);
                }
                if ($mode === 'quiz') {
                    assertSameValue(404, requestStatus($port, '/lesson.md'), $rootName . ' quiz markdown route');
                    assertSameValue(404, requestStatus($port, '/package.skill'), $rootName . ' quiz skill route');
                    assertSameValue(404, requestStatus($port, '/notes.txt'), $rootName . ' quiz ordinary file route');
                    assertSameValue(false, is_dir($contentPath), $rootName . ' quiz request must not create content');
                    $joinBody = requestBody($port, '/');
                    assertSameValue(true, strpos($joinBody, 'href="/quiz-admin"') !== false, $rootName . ' quiz homepage exposes the configured admin link');
                } else {
                    assertSameValue(200, requestStatus($port, '/lesson.md'), $rootName . ' raw markdown fixture');
                    assertSameValue(200, requestStatus($port, '/package.skill'), $rootName . ' raw skill fixture');
                    assertSameValue(200, requestStatus($port, '/notes.txt'), $rootName . ' ordinary file fixture');
                    assertSameValue(200, requestStatus($port, '/course'), $rootName . ' folder navigation fixture');
                    assertSameValue(200, requestStatus($port, '/course/chapter.md'), $rootName . ' nested raw markdown fixture');
                    assertSameValue("# Fixture lesson\n", requestBody($port, '/lesson.md'), $rootName . ' markdown is served raw');
                    assertSameValue("fixture skill\n", requestBody($port, '/package.skill'), $rootName . ' skill fixture body');
                    assertSameValue(true, strpos(requestBody($port, '/notes.txt'), 'notes.txt') !== false, $rootName . ' ordinary file uses library rendering');
                }
            } finally {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
            }
        }

        $legacyPath = $temporaryRoot . DIRECTORY_SEPARATOR . $rootName . '-legacy';
        mkdir($legacyPath, 0700, true);
        $legacyBranding = $legacyPath . DIRECTORY_SEPARATOR . 'branding.php';
        $legacyContent = $legacyPath . DIRECTORY_SEPARATOR . 'content';
        writeLegacyBranding($legacyBranding, $examplePath);
        createContentFixtures($legacyContent);
        [$process, $pipes, $port] = startServer($root, $documentRoot, $router, $legacyBranding, $legacyContent);
        try {
            assertSameValue(200, requestStatus($port, '/'), $rootName . ' legacy homepage');
            assertSameValue(200, requestStatus($port, '/quiz'), $rootName . ' legacy quiz');
            assertSameValue(503, requestStatus($port, '/sync'), $rootName . ' legacy sync reaches disabled Drive controller');
            assertSameValue(200, requestStatus($port, '/course'), $rootName . ' legacy folder navigation');
            assertSameValue(200, requestStatus($port, '/lesson.md'), $rootName . ' legacy raw markdown');
            assertSameValue(200, requestStatus($port, '/package.skill'), $rootName . ' legacy raw skill');
            assertSameValue(200, requestStatus($port, '/notes.txt'), $rootName . ' legacy ordinary file');
            assertSameValue("# Fixture lesson\n", requestBody($port, '/lesson.md'), $rootName . ' legacy markdown body');
            assertSameValue("fixture skill\n", requestBody($port, '/package.skill'), $rootName . ' legacy skill body');
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }

        $preloadedQuizPath = $temporaryRoot . DIRECTORY_SEPARATOR . $rootName . '-quiz-preloaded';
        mkdir($preloadedQuizPath, 0700, true);
        $preloadedQuizBranding = $preloadedQuizPath . DIRECTORY_SEPARATOR . 'branding.php';
        $preloadedQuizContent = $preloadedQuizPath . DIRECTORY_SEPARATOR . 'content';
        writeBranding($preloadedQuizBranding, $examplePath, 'quiz');
        createContentFixtures($preloadedQuizContent);
        [$process, $pipes, $port] = startServer(
            $root,
            $documentRoot,
            $router,
            $preloadedQuizBranding,
            $preloadedQuizContent
        );
        try {
            assertSameValue(200, requestStatus($port, '/'), $rootName . ' preloaded quiz homepage');
            assertSameValue(404, requestStatus($port, '/lesson.md'), $rootName . ' preloaded quiz markdown denial');
            assertSameValue(404, requestStatus($port, '/package.skill'), $rootName . ' preloaded quiz skill denial');
            assertSameValue(404, requestStatus($port, '/notes.txt'), $rootName . ' preloaded quiz ordinary file denial');
            assertSameValue(404, requestStatus($port, '/course'), $rootName . ' preloaded quiz folder denial');
            assertSameValue(404, requestStatus($port, '/course/chapter.md'), $rootName . ' preloaded quiz nested markdown denial');
            assertSameValue(true, is_file($preloadedQuizContent . DIRECTORY_SEPARATOR . 'lesson.md'), $rootName . ' preloaded fixture remains present');
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }

    $missingContentPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'missing-library-content';
    mkdir($missingContentPath, 0700, true);
    $missingBranding = $missingContentPath . DIRECTORY_SEPARATOR . 'branding.php';
    $missingContent = $missingContentPath . DIRECTORY_SEPARATOR . 'content';
    writeBranding($missingBranding, $examplePath, 'library');
    [$process, $pipes, $port] = startServer($root, $root . '/public', $root . '/public/index.php', $missingBranding, $missingContent);
    try {
        assertSameValue(false, is_dir($missingContent), 'Missing library content starts absent');
        assertSameValue(200, requestStatus($port, '/'), 'Library starts with missing content');
        assertSameValue(true, is_dir($missingContent), 'Library initializes missing content');
    } finally {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }

    $hiddenLinkPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'hidden-link';
    mkdir($hiddenLinkPath, 0700, true);
    $hiddenLinkBranding = $hiddenLinkPath . DIRECTORY_SEPARATOR . 'branding.php';
    writeBranding($hiddenLinkBranding, $examplePath, 'quiz', true, false);
    [$process, $pipes, $port] = startServer($root, $root . '/public', $root . '/public/index.php', $hiddenLinkBranding, $hiddenLinkPath . '/content');
    try {
        assertSameValue(false, strpos(requestBody($port, '/'), 'href="/quiz-admin"') !== false, 'Admin link setting must be honored by the join page');
    } finally {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }

    $invalidPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'invalid';
    mkdir($invalidPath, 0700, true);
    $invalidBranding = $invalidPath . DIRECTORY_SEPARATOR . 'branding.php';
    writeBranding($invalidBranding, $examplePath, 'invalid');
    [$process, $pipes, $port] = startServer($root, $root . '/public', $root . '/public/index.php', $invalidBranding, $invalidPath . '/content');
    try {
        assertSameValue(503, requestStatus($port, '/'), 'Invalid configuration must return 503');
        assertSameValue('Service temporarily unavailable.', requestBody($port, '/'), 'Invalid configuration response must remain neutral');
    } finally {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }

    $stoppedPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'stopped';
    mkdir($stoppedPath, 0700, true);
    $stoppedBranding = $stoppedPath . DIRECTORY_SEPARATOR . 'branding.php';
    writeBranding($stoppedBranding, $examplePath, 'quiz', false);
    [$process, $pipes, $port] = startServer($root, $root . '/public', $root . '/public/index.php', $stoppedBranding, $stoppedPath . '/content');
    try {
        assertSameValue(503, requestStatus($port, '/'), 'Stopped quiz-only homepage must return 503');
        assertSameValue(404, requestStatus($port, '/quiz'), 'Stopped quiz subroutes must return 404');
        assertSameValue(false, is_dir($stoppedPath . '/content'), 'Stopped quiz must not initialize content');
    } finally {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }

    echo "RouteIntegrationTest: OK\n";
} finally {
    removeTestDirectory($temporaryRoot);
}
