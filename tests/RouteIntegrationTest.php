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

            [$process, $pipes, $port] = startServer($root, $documentRoot, $router, $brandingPath, $contentPath);
            try {
                $expected = [
                    'library' => ['/' => 200, '/quiz' => 404, '/quiz-admin' => 404, '/sync' => 'available'],
                    'quiz' => ['/' => 200, '/quiz' => 200, '/quiz-admin' => 'available', '/sync' => 404],
                    'hybrid' => ['/' => 200, '/quiz' => 200, '/quiz-admin' => 'available', '/sync' => 'available'],
                ][$mode];
                foreach ($expected as $path => $status) {
                    $actual = requestStatus($port, $path);
                    $indexActual = requestStatus($port, '/index.php' . ($path === '/' ? '' : $path));
                    if ($status === 'available') {
                        assertSameValue(false, $actual === 404, $rootName . ' ' . $mode . ' ' . $path . ' must reach sync');
                        assertSameValue(false, $indexActual === 404, $rootName . ' ' . $mode . ' index variant ' . $path . ' must reach sync');
                    } else {
                        assertSameValue($status, $actual, $rootName . ' ' . $mode . ' ' . $path);
                        assertSameValue($status, $indexActual, $rootName . ' ' . $mode . ' index variant ' . $path);
                    }
                }
                assertSameValue(404, requestStatus($port, '/debug'), $rootName . ' debug route');
                assertSameValue(404, requestStatus($port, '/debug.php'), $rootName . ' debug file');
                if ($rootName === 'repository') {
                    assertSameValue(404, requestStatus($port, '/public/debug.php'), 'repository direct public debug file');
                }
                $assetPath = $rootName === 'public' ? '/assets/css/main.css' : '/public/assets/css/main.css';
                assertSameValue(200, requestStatus($port, $assetPath), $rootName . ' static assets remain available');
                if ($mode === 'quiz') {
                    assertSameValue(404, requestStatus($port, '/course/file.md'), $rootName . ' quiz content route');
                    assertSameValue(false, is_dir($contentPath), $rootName . ' quiz request must not create content');
                    $joinBody = requestBody($port, '/');
                    assertSameValue(true, strpos($joinBody, 'href="/quiz-admin"') !== false, $rootName . ' quiz homepage exposes the configured admin link');
                } else {
                    assertSameValue(true, is_dir($contentPath), $rootName . ' library request initializes content');
                }
            } finally {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
            }
        }
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
