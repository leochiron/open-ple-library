<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__);
$rootRules = (string)file_get_contents($root . '/.htaccess');
$publicRules = (string)file_get_contents($root . '/public/.htaccess');
$debugEndpoint = (string)file_get_contents($root . '/public/debug.php');

$denyPosition = strpos($rootRules, 'RewriteRule ^(app|storage|content|\.git)(/|$) - [F,L]');
$rootBypassPosition = strpos($rootRules, 'RewriteCond %{REQUEST_FILENAME} -f [OR]');
assertSameValue(true, $denyPosition !== false, 'Repository rules must deny sensitive directories');
assertSameValue(true, $rootBypassPosition !== false, 'Repository rules must contain the real-file bypass');
assertSameValue(true, $denyPosition < $rootBypassPosition, 'Sensitive directory denial must precede the real-file bypass');

$debugRewritePosition = strpos($publicRules, 'RewriteRule ^debug(?:\.php)?/?$ /index.php/debug.php [L]');
$publicBypassPosition = strpos($publicRules, 'RewriteCond %{REQUEST_FILENAME} -f');
assertSameValue(true, $debugRewritePosition !== false, 'Public rules must route debug aliases to the disabled endpoint');
assertSameValue(true, $publicBypassPosition !== false, 'Public rules must contain the real-file bypass');
assertSameValue(true, $debugRewritePosition < $publicBypassPosition, 'Debug denial must precede the real-file bypass');
assertSameValue(true, strpos($debugEndpoint, 'http_response_code(404)') !== false, 'The direct debug endpoint must return 404');

function apacheBinary(): ?string
{
    $commands = PHP_OS_FAMILY === 'Windows'
        ? ['where.exe httpd.exe', 'where.exe apache2.exe']
        : ['command -v apache2', 'command -v httpd'];
    foreach ($commands as $command) {
        $lines = [];
        $status = 1;
        exec($command . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $lines, $status);
        if ($status === 0 && isset($lines[0]) && trim($lines[0]) !== '') {
            return trim($lines[0]);
        }
    }
    return null;
}

function apacheStatus(string $baseUrl, string $path): int
{
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
    @file_get_contents(rtrim($baseUrl, '/') . $path, false, $context);
    $headers = $http_response_header ?? [];
    if (!isset($headers[0]) || preg_match('/\s(\d{3})\s/', $headers[0], $match) !== 1) {
        throw new RuntimeException('No Apache response for ' . $path);
    }
    return (int)$match[1];
}

$apache = apacheBinary();
if ($apache === null) {
    echo "WebServerRulesTest: OK (Apache runtime unavailable; ordered static rules verified)\n";
    exit(0);
}

$publicUrl = getenv('PLE_APACHE_PUBLIC_URL');
$repositoryUrl = getenv('PLE_APACHE_REPOSITORY_URL');
if (!is_string($publicUrl) || $publicUrl === '' || !is_string($repositoryUrl) || $repositoryUrl === '') {
    throw new RuntimeException(
        'Apache was detected at ' . $apache . '. Set PLE_APACHE_PUBLIC_URL and '
        . 'PLE_APACHE_REPOSITORY_URL to two test deployments before running this test.'
    );
}

assertSameValue(404, apacheStatus($publicUrl, '/debug'), 'Apache public docroot debug alias');
assertSameValue(404, apacheStatus($publicUrl, '/debug.php'), 'Apache public docroot debug file');
assertSameValue(403, apacheStatus($repositoryUrl, '/app/Config/config.php'), 'Apache repository app denial');
assertSameValue(403, apacheStatus($repositoryUrl, '/storage/quiz.db'), 'Apache repository storage denial');
assertSameValue(403, apacheStatus($repositoryUrl, '/content/direct-access-test'), 'Apache repository content denial');
assertSameValue(403, apacheStatus($repositoryUrl, '/.git/config'), 'Apache repository git denial');

echo "WebServerRulesTest: OK (live Apache checks passed for both document roots)\n";
