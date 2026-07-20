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

function copyTestDirectory(string $source, string $destination, ?string $excludedPath = null): void
{
    if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
        throw new RuntimeException('Unable to create copied test directory');
    }
    $items = scandir($source) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        if ($excludedPath !== null && realpath($sourcePath) === realpath($excludedPath)) {
            continue;
        }
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;
        if (is_dir($sourcePath)) {
            copyTestDirectory($sourcePath, $destinationPath, $excludedPath);
        } elseif (!copy($sourcePath, $destinationPath)) {
            throw new RuntimeException('Unable to copy integration fixture ' . $sourcePath);
        }
    }
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
function startServer(
    string $root,
    string $documentRoot,
    string $frontController,
    string $brandingPath,
    string $contentPath,
    string $applicationEnvironment = 'testing'
): array
{
    $port = freePort();
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $documentRoot, $root . '/tests/HttpTestRouter.php'];
    $pipes = [];
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    $environment['APP_ENV'] = $applicationEnvironment;
    $environment['PLE_TEST_MODE'] = '1';
    $environment['PLE_TEST_BRANDING'] = $brandingPath;
    $environment['PLE_TEST_CONTENT_PATH'] = $contentPath;
    $environment['PLE_TEST_FRONT_CONTROLLER'] = $frontController;
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open($command, [STDIN, ['file', $nullDevice, 'a'], ['file', $nullDevice, 'a']], $pipes, $root, $environment);
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
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $context = stream_context_create(['http' => [
            'ignore_errors' => true,
            'timeout' => 3,
            'header' => "Connection: close\r\n",
        ]]);
        $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
        if (is_string($body)) {
            return $body;
        }
        usleep(20000);
    }
    return '';
}

function requestContentType(int $port, string $path): string
{
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $context = stream_context_create(['http' => [
            'ignore_errors' => true,
            'timeout' => 3,
            'header' => "Connection: close\r\n",
        ]]);
        @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
        $headers = $http_response_header ?? [];
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return trim(substr($header, strlen('Content-Type:')));
            }
        }
        usleep(20000);
    }
    return '';
}

function requestHeader(int $port, string $path, string $headerName, string $method = 'GET', array $headers = []): string
{
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $context = stream_context_create(['http' => [
            'ignore_errors' => true,
            'timeout' => 3,
            'method' => $method,
            'header' => implode("\r\n", $headers),
        ]]);
        @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        foreach ($responseHeaders as $header) {
            if (stripos($header, $headerName . ':') === 0) {
                return trim(substr($header, strlen($headerName) + 1));
            }
        }
        usleep(20000);
    }
    return '';
}

function requestStatusWithHeaders(int $port, string $path, array $headers): int
{
    $context = stream_context_create(['http' => [
        'ignore_errors' => true,
        'timeout' => 3,
        'follow_location' => 0,
        'header' => implode("\r\n", $headers),
    ]]);
    @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    if (!isset($responseHeaders[0]) || preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match) !== 1) {
        throw new RuntimeException('No HTTP status for custom-header request ' . $path);
    }
    return (int)$match[1];
}

function requestBodyWithHeaders(int $port, string $path, array $headers): string
{
    $headers[] = 'Connection: close';
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $context = stream_context_create(['http' => [
            'ignore_errors' => true,
            'timeout' => 3,
            'header' => implode("\r\n", $headers),
        ]]);
        $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
        if (is_string($body)) {
            return $body;
        }
        usleep(20000);
    }
    return '';
}

function assertNeutralIncidentResponse(string $body, string $message): void
{
    assertSameValue(
        1,
        preg_match('/^Service temporarily unavailable\. Incident: [a-zA-Z0-9]+$/', $body),
        $message
    );
}

function writeBranding(
    string $path,
    string $examplePath,
    string $mode,
    bool $quizEnabled = true,
    bool $showAdminLink = true,
    ?string $publicBaseUrl = null,
    bool $trustForwardedProto = false,
    array $trustedProxyIps = []
): void
{
    $source = "<?php\n"
        . '$branding = require ' . var_export($examplePath, true) . ";\n"
        . '$branding[\'app_mode\'] = ' . var_export($mode, true) . ";\n"
        . '$branding[\'quiz\'] = [\'enabled\' => ' . ($quizEnabled ? 'true' : 'false')
        . ", 'show_admin_link' => " . ($showAdminLink ? 'true' : 'false') . "];\n"
        . '$branding[\'public_base_url\'] = ' . var_export($publicBaseUrl, true) . ";\n"
        . '$branding[\'trust_forwarded_proto\'] = ' . ($trustForwardedProto ? 'true' : 'false') . ";\n"
        . '$branding[\'trusted_proxy_ips\'] = ' . var_export($trustedProxyIps, true) . ";\n"
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
    file_put_contents($contentPath . DIRECTORY_SEPARATOR . 'page.html', "<!doctype html><title>Fixture</title>\n");
    file_put_contents($contentPath . DIRECTORY_SEPARATOR . 'document.pdf', "%PDF-1.4\nfixture\n%%EOF\n");
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
                assertSameValue('', requestHeader($port, $assetPath, 'X-Robots-Tag'), $rootName . ' static assets receive no SEO exclusion header');
                assertSameValue('noindex', requestHeader($port, '/llms.txt', 'X-Robots-Tag'), $rootName . ' static LLM guidance is noindex');
                if ($rootName === 'repository') {
                    assertSameValue('noindex', requestHeader($port, '/LICENSE', 'X-Robots-Tag'), 'repository static LICENSE is noindex');
                }
                foreach (['/favicon.ico', '/robots.txt', '/sitemap.xml'] as $publicPath) {
                    assertSameValue(200, requestStatus($port, $publicPath . '?cache=1'), $rootName . ' exact public path with query ' . $publicPath);
                    assertSameValue(200, requestStatus($port, '/index.php' . $publicPath . '?cache=1'), $rootName . ' exact index public path ' . $publicPath);
                    assertSameValue(404, requestStatus($port, '/nested' . $publicPath), $rootName . ' nested public lookalike ' . $publicPath);
                }
                $faviconBody = requestBody($port, '/favicon.ico?cache=1');
                assertSameValue('image/x-icon', requestContentType($port, '/favicon.ico?cache=1'), $rootName . ' favicon content type');
                assertSameValue("\x00\x00\x01\x00", substr($faviconBody, 0, 4), $rootName . ' favicon ICO signature');

                $expectedBaseUrl = 'http://127.0.0.1:' . $port;
                $robotsBody = requestBody($port, '/robots.txt?cache=1');
                assertSameValue('text/plain; charset=utf-8', requestContentType($port, '/robots.txt?cache=1'), $rootName . ' robots content type');
                assertSameValue(true, strpos($robotsBody, "User-agent: *") !== false, $rootName . ' robots body');
                assertSameValue(true, strpos($robotsBody, 'Sitemap: ' . $expectedBaseUrl . '/sitemap.xml') !== false, $rootName . ' robots canonical sitemap URL');
                assertSameValue(false, strpos($robotsBody, 'Disallow: /quiz') !== false, $rootName . ' robots does not hide noindex URLs from crawlers');
                assertSameValue(false, strpos($robotsBody, 'ple-sansfrontieres.org') !== false, $rootName . ' robots has no historical domain');

                $sitemapBody = requestBody($port, '/sitemap.xml?cache=1');
                assertSameValue('application/xml; charset=utf-8', requestContentType($port, '/sitemap.xml?cache=1'), $rootName . ' sitemap content type');
                assertSameValue(true, strpos($sitemapBody, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">') !== false, $rootName . ' sitemap XML body');
                assertSameValue($mode !== 'quiz', strpos($sitemapBody, '<loc>' . $expectedBaseUrl . '/</loc>') !== false, $rootName . ' sitemap homepage profile policy');
                assertSameValue(false, strpos($sitemapBody, '<lastmod>') !== false, $rootName . ' sitemap omits dynamic lastmod');
                assertSameValue(false, strpos($sitemapBody, '<changefreq>') !== false, $rootName . ' sitemap omits changefreq');
                assertSameValue(false, strpos($sitemapBody, '<priority>') !== false, $rootName . ' sitemap omits priority');
                assertSameValue(false, strpos($sitemapBody, 'ple-sansfrontieres.org') !== false, $rootName . ' sitemap has no historical domain');
                if ($mode === 'quiz') {
                    assertSameValue(404, requestStatus($port, '/lesson.md'), $rootName . ' quiz markdown route');
                    assertSameValue(404, requestStatus($port, '/package.skill'), $rootName . ' quiz skill route');
                    assertSameValue(404, requestStatus($port, '/notes.txt'), $rootName . ' quiz ordinary file route');
                    assertSameValue(false, is_dir($contentPath), $rootName . ' quiz request must not create content');
                    $joinBody = requestBody($port, '/');
                    assertSameValue(true, strpos($joinBody, 'href="/quiz-admin"') !== false, $rootName . ' quiz homepage exposes the configured admin link');
                    assertSameValue('noindex', requestHeader($port, '/', 'X-Robots-Tag'), $rootName . ' quiz homepage noindex header');
                    assertSameValue(true, strpos($joinBody, '<meta name="robots" content="noindex">') !== false, $rootName . ' quiz homepage noindex meta');
                    $untrustedForwardedBody = requestBodyWithHeaders($port, '/sitemap.xml', ['X-Forwarded-Proto: https']);
                    assertSameValue(false, strpos($untrustedForwardedBody, '<loc>') !== false, $rootName . ' quiz sitemap remains empty behind an untrusted proxy');
                    assertSameValue(503, requestStatusWithHeaders($port, '/sitemap.xml', ['Host: bad_host.example']), $rootName . ' hostile Host gets neutral SEO error');
                } else {
                    assertSameValue(200, requestStatus($port, '/lesson.md'), $rootName . ' raw markdown fixture');
                    assertSameValue(200, requestStatus($port, '/package.skill'), $rootName . ' raw skill fixture');
                    assertSameValue(200, requestStatus($port, '/notes.txt'), $rootName . ' ordinary file fixture');
                    assertSameValue('text/html; charset=utf-8', strtolower(requestContentType($port, '/notes.txt')), $rootName . ' ordinary file is rendered by the library');
                    assertSameValue(200, requestStatus($port, '/course'), $rootName . ' folder navigation fixture');
                    assertSameValue(200, requestStatus($port, '/course/chapter.md'), $rootName . ' nested raw markdown fixture');
                    assertSameValue("# Fixture lesson\n", requestBody($port, '/lesson.md'), $rootName . ' markdown is served raw');
                    foreach (['/course', '/notes.txt', '/lesson.md', '/page.html', '/document.pdf+open', '/document.pdf+download'] as $internalPath) {
                        assertSameValue('noindex', requestHeader($port, $internalPath, 'X-Robots-Tag'), $rootName . ' internal response noindex ' . $internalPath);
                    }
                    assertSameValue('noindex', requestHeader($port, '/document.pdf+open', 'X-Robots-Tag', 'HEAD'), $rootName . ' PDF HEAD noindex');
                    assertSameValue(206, requestStatusWithHeaders($port, '/document.pdf+open', ['Range: bytes=0-3']), $rootName . ' PDF range response');
                    assertSameValue('noindex', requestHeader($port, '/document.pdf+open', 'X-Robots-Tag', 'GET', ['Range: bytes=0-3']), $rootName . ' PDF range noindex');
                    assertSameValue(true, strpos(requestBody($port, '/course'), '<meta name="robots" content="noindex">') !== false, $rootName . ' internal HTML meta noindex');
                    assertSameValue(false, strpos(requestBody($port, '/course'), 'rel="canonical"') !== false, $rootName . ' internal HTML has no canonical link');
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

        $configuredUrlPath = $temporaryRoot . DIRECTORY_SEPARATOR . $rootName . '-configured-url';
        mkdir($configuredUrlPath, 0700, true);
        $configuredUrlBranding = $configuredUrlPath . DIRECTORY_SEPARATOR . 'branding.php';
        writeBranding($configuredUrlBranding, $examplePath, 'library', true, true, 'https://canonical.example:8443');
        [$process, $pipes, $port] = startServer(
            $root,
            $documentRoot,
            $router,
            $configuredUrlBranding,
            $configuredUrlPath . DIRECTORY_SEPARATOR . 'content',
            'production'
        );
        try {
            assertSameValue(true, strpos(requestBody($port, '/robots.txt'), 'Sitemap: https://canonical.example:8443/sitemap.xml') !== false, $rootName . ' configured robots URL');
            assertSameValue(true, strpos(requestBody($port, '/sitemap.xml'), '<loc>https://canonical.example:8443/</loc>') !== false, $rootName . ' configured sitemap URL');
            assertSameValue(200, requestStatusWithHeaders($port, '/sitemap.xml', ['Host: bad_host.example']), $rootName . ' configured URL takes priority over hostile Host');
            $homepageBody = requestBodyWithHeaders($port, '/', ['Host: hostile.example']);
            assertSameValue('index, follow', requestHeader($port, '/', 'X-Robots-Tag', 'GET', ['Host: hostile.example']), $rootName . ' configured library homepage is indexable');
            assertSameValue(true, strpos($homepageBody, '<meta name="robots" content="index,follow">') !== false, $rootName . ' homepage index meta');
            assertSameValue(true, strpos($homepageBody, '<link rel="canonical" href="https://canonical.example:8443/">') !== false, $rootName . ' homepage canonical ignores Host');
            assertSameValue('noindex', requestHeader($port, '/missing', 'X-Robots-Tag'), $rootName . ' dynamic errors are noindex');
            assertSameValue(true, strpos(requestBody($port, '/missing'), '<meta name="robots" content="noindex">') !== false, $rootName . ' internal error meta noindex');
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }

        $invalidUrlPath = $temporaryRoot . DIRECTORY_SEPARATOR . $rootName . '-invalid-url';
        mkdir($invalidUrlPath, 0700, true);
        $invalidUrlBranding = $invalidUrlPath . DIRECTORY_SEPARATOR . 'branding.php';
        writeBranding($invalidUrlBranding, $examplePath, 'quiz', true, true, 'https://canonical.example/path');
        [$process, $pipes, $port] = startServer(
            $root,
            $documentRoot,
            $router,
            $invalidUrlBranding,
            $invalidUrlPath . DIRECTORY_SEPARATOR . 'content',
            'production'
        );
        try {
            assertSameValue(200, requestStatus($port, '/'), $rootName . ' invalid SEO URL does not break homepage');
            assertSameValue(200, requestStatus($port, '/quiz'), $rootName . ' invalid SEO URL does not break quiz route');
            assertSameValue(503, requestStatus($port, '/robots.txt'), $rootName . ' invalid configured URL robots');
            assertNeutralIncidentResponse(requestBody($port, '/robots.txt'), $rootName . ' invalid URL response remains neutral');
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }

        $productionNoUrlPath = $temporaryRoot . DIRECTORY_SEPARATOR . $rootName . '-production-no-url';
        mkdir($productionNoUrlPath, 0700, true);
        $productionNoUrlBranding = $productionNoUrlPath . DIRECTORY_SEPARATOR . 'branding.php';
        writeBranding($productionNoUrlBranding, $examplePath, 'quiz');
        [$process, $pipes, $port] = startServer(
            $root,
            $documentRoot,
            $router,
            $productionNoUrlBranding,
            $productionNoUrlPath . DIRECTORY_SEPARATOR . 'content',
            'production'
        );
        try {
            assertSameValue(200, requestStatus($port, '/'), $rootName . ' production without URL keeps homepage');
            assertSameValue(200, requestStatus($port, '/quiz'), $rootName . ' production without URL keeps quiz');
            assertSameValue(503, requestStatus($port, '/robots.txt'), $rootName . ' production robots requires configured URL');
            assertSameValue(503, requestStatus($port, '/sitemap.xml'), $rootName . ' production sitemap requires configured URL');
            assertSameValue(503, requestStatusWithHeaders($port, '/sitemap.xml', ['Host: valid.example']), $rootName . ' production valid Host cannot replace configuration');
            assertSameValue(503, requestStatusWithHeaders($port, '/sitemap.xml', ['Host: bad_host.example']), $rootName . ' production hostile Host remains neutral without configuration');
            assertNeutralIncidentResponse(requestBody($port, '/robots.txt'), $rootName . ' missing production URL response remains neutral');
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }

        $trustedProxyPath = $temporaryRoot . DIRECTORY_SEPARATOR . $rootName . '-trusted-proxy';
        mkdir($trustedProxyPath, 0700, true);
        $trustedProxyBranding = $trustedProxyPath . DIRECTORY_SEPARATOR . 'branding.php';
        writeBranding($trustedProxyBranding, $examplePath, 'library', true, true, null, true, ['127.0.0.1']);
        [$process, $pipes, $port] = startServer(
            $root,
            $documentRoot,
            $router,
            $trustedProxyBranding,
            $trustedProxyPath . DIRECTORY_SEPARATOR . 'content'
        );
        try {
            $trustedBody = requestBodyWithHeaders($port, '/sitemap.xml', ['X-Forwarded-Proto: https']);
            assertSameValue(true, strpos($trustedBody, '<loc>https://127.0.0.1:' . $port . '/</loc>') !== false, $rootName . ' trusted proxy protocol');
            assertSameValue(503, requestStatusWithHeaders($port, '/sitemap.xml', ['X-Forwarded-Proto: https, http']), $rootName . ' hostile trusted XFP is rejected');
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
        assertNeutralIncidentResponse(requestBody($port, '/'), 'Invalid configuration response must remain neutral');
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

    $brokenConfigPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'broken-config';
    mkdir($brokenConfigPath, 0700, true);
    $brokenBranding = $brokenConfigPath . DIRECTORY_SEPARATOR . 'branding.php';
    file_put_contents($brokenBranding, "<?php\nreturn null;\n");
    [$process, $pipes, $port] = startServer(
        $root,
        $root . '/public',
        $root . '/public/index.php',
        $brokenBranding,
        $brokenConfigPath . '/content'
    );
    try {
        assertSameValue(503, requestStatus($port, '/'), 'Malformed branding return must be contained');
        assertNeutralIncidentResponse(requestBody($port, '/'), 'Malformed branding response must remain neutral');
    } finally {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }

    $missingDependencyPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'missing-dependency';
    mkdir($missingDependencyPath, 0700, true);
    $missingDependencyBranding = $missingDependencyPath . DIRECTORY_SEPARATOR . 'branding.php';
    file_put_contents(
        $missingDependencyBranding,
        "<?php\nMissingDeploymentDependency::load();\nreturn [];\n"
    );
    [$process, $pipes, $port] = startServer(
        $root,
        $root . '/public',
        $root . '/public/index.php',
        $missingDependencyBranding,
        $missingDependencyPath . '/content'
    );
    try {
        assertSameValue(503, requestStatus($port, '/'), 'Missing bootstrap dependency must be contained');
        assertNeutralIncidentResponse(requestBody($port, '/'), 'Missing dependency response must remain neutral');
    } finally {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }

    $partialDeploymentRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'partial-deployment';
    copyTestDirectory(
        $root . DIRECTORY_SEPARATOR . 'app',
        $partialDeploymentRoot . DIRECTORY_SEPARATOR . 'app',
        $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'ApplicationProfile.php'
    );
    mkdir($partialDeploymentRoot . DIRECTORY_SEPARATOR . 'public', 0700, true);
    copy(
        $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php',
        $partialDeploymentRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php'
    );
    $partialBranding = $partialDeploymentRoot . DIRECTORY_SEPARATOR . 'branding.php';
    writeBranding($partialBranding, $examplePath, 'hybrid');
    [$process, $pipes, $port] = startServer(
        $root,
        $partialDeploymentRoot . DIRECTORY_SEPARATOR . 'public',
        $partialDeploymentRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php',
        $partialBranding,
        $partialDeploymentRoot . DIRECTORY_SEPARATOR . 'content'
    );
    try {
        assertSameValue(503, requestStatus($port, '/'), 'Missing ApplicationProfile deployment must be contained');
        assertNeutralIncidentResponse(requestBody($port, '/'), 'Missing ApplicationProfile response must remain neutral');
    } finally {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }

    $blockedContentPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'blocked-content';
    mkdir($blockedContentPath, 0700, true);
    $blockedBranding = $blockedContentPath . DIRECTORY_SEPARATOR . 'branding.php';
    $contentFile = $blockedContentPath . DIRECTORY_SEPARATOR . 'not-a-directory';
    file_put_contents($contentFile, 'fixture');
    writeBranding($blockedBranding, $examplePath, 'library');
    [$process, $pipes, $port] = startServer(
        $root,
        $root . '/public',
        $root . '/public/index.php',
        $blockedBranding,
        $contentFile
    );
    try {
        assertSameValue(500, requestStatus($port, '/'), 'Uncreatable content directory must be contained');
        assertNeutralIncidentResponse(requestBody($port, '/'), 'Content bootstrap failure must remain neutral');
    } finally {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }

    $missingFrontControllerRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'missing-front-controller';
    mkdir($missingFrontControllerRoot, 0700, true);
    copy($root . '/index.php', $missingFrontControllerRoot . DIRECTORY_SEPARATOR . 'index.php');
    [$process, $pipes, $port] = startServer(
        $root,
        $missingFrontControllerRoot,
        $missingFrontControllerRoot . DIRECTORY_SEPARATOR . 'index.php',
        $invalidBranding,
        $missingFrontControllerRoot . DIRECTORY_SEPARATOR . 'content'
    );
    try {
        assertSameValue(503, requestStatus($port, '/'), 'Repository shim must detect a missing public front controller');
        assertNeutralIncidentResponse(requestBody($port, '/'), 'Missing front controller response must remain neutral');
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
