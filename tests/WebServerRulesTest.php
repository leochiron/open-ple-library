<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__);
$rootRules = (string)file_get_contents($root . '/.htaccess');
$publicRules = (string)file_get_contents($root . '/public/.htaccess');
$contentRulesPath = $root . '/content/.htaccess';
$contentRules = (string)file_get_contents($contentRulesPath);
$llmsRoot = (string)file_get_contents($root . '/llms.txt');
$llmsPublic = (string)file_get_contents($root . '/public/llms.txt');
$contentRulesTemplate = (string)file_get_contents($root . '/app/Config/content.htaccess');
$debugEndpoint = (string)file_get_contents($root . '/public/debug.php');
$robotsTemplate = (string)file_get_contents($root . '/robots.txt');

assertSameValue(true, $robotsTemplate !== '', 'The canonical root robots template must not be empty');
assertSameValue(false, strpos($robotsTemplate, 'ple-sansfrontieres.org') !== false, 'The robots template must not contain the historical domain');
assertSameValue(false, is_file($root . '/public/robots.txt'), 'No public robots copy may bypass canonical generation');
assertSameValue(false, is_file($root . '/sitemap.xml'), 'No root static sitemap may contain a stale domain');
assertSameValue(false, is_file($root . '/public/sitemap.xml'), 'No public static sitemap may contain a stale domain');

$denyPosition = strpos($rootRules, 'RewriteRule ^(app|storage|content|\.git)(/|$) - [F,L]');
$rootBypassPosition = strpos($rootRules, 'RewriteCond %{REQUEST_FILENAME} -f [OR]');
assertSameValue(true, $denyPosition !== false, 'Repository rules must deny sensitive directories');
assertSameValue(true, $rootBypassPosition !== false, 'Repository rules must contain the real-file bypass');
assertSameValue(true, $denyPosition < $rootBypassPosition, 'Sensitive directory denial must precede the real-file bypass');
$rootSeoPosition = strpos($rootRules, 'RewriteRule ^(robots\.txt|sitemap\.xml)$ index.php [L]');
assertSameValue(true, $rootSeoPosition !== false, 'Repository rules must route SEO files through PHP');
assertSameValue(true, $rootSeoPosition < $rootBypassPosition, 'Repository SEO routing must precede the real-file bypass');

$debugRewritePosition = strpos($publicRules, 'RewriteRule ^debug(?:\.php)?/?$ /index.php/debug.php [L]');
$publicBypassPosition = strpos($publicRules, 'RewriteCond %{REQUEST_FILENAME} -f');
assertSameValue(true, $debugRewritePosition !== false, 'Public rules must route debug aliases to the disabled endpoint');
assertSameValue(true, $publicBypassPosition !== false, 'Public rules must contain the real-file bypass');
assertSameValue(true, $debugRewritePosition < $publicBypassPosition, 'Debug denial must precede the real-file bypass');
$publicSeoPosition = strpos($publicRules, 'RewriteRule ^(robots\.txt|sitemap\.xml)$ /index.php [L]');
assertSameValue(true, $publicSeoPosition !== false, 'Public rules must route SEO files through PHP');
assertSameValue(true, $publicSeoPosition < $publicBypassPosition, 'Public SEO routing must precede the real-file bypass');
assertSameValue(true, strpos($debugEndpoint, 'http_response_code(404)') !== false, 'The direct debug endpoint must return 404');
assertSameValue($llmsRoot, $llmsPublic, 'Both supported document roots must expose the same llms.txt guidance');
assertSameValue(true, strpos($llmsRoot, '# Cours et Exercices de Léo Chiron') === 0, 'LLM guidance must start with the required H1');
assertSameValue(true, strpos($llmsRoot, 'ne peuvent pas être republiés ailleurs') !== false, 'LLM guidance must state the no-republication condition');
assertSameValue(true, strpos($rootRules, 'RewriteRule ^llm\\.txt$ /llms.txt [R=301,L,NC]') !== false, 'Root rules must redirect the requested singular LLM.txt alias');
assertSameValue(true, strpos($publicRules, 'RewriteRule ^llm\\.txt$ /llms.txt [R=301,L,NC]') !== false, 'Public rules must redirect the requested singular LLM.txt alias');
assertSameValue(true, strpos($rootRules, 'LICENSE|llms\\.txt|(?!robots\\.txt$).+\\.(pdf|md|markdown|txt|csv|tsv|rtf|epub|html|htm|skill|doc|docx|xls|xlsx|ppt|pptx|odt|ods|odp)') !== false, 'Root rules must target static pedagogical documents and LICENSE');
assertSameValue(true, strpos($publicRules, 'LICENSE|llms\\.txt|(?!robots\\.txt$).+\\.(pdf|md|markdown|txt|csv|tsv|rtf|epub|html|htm|skill|doc|docx|xls|xlsx|ppt|pptx|odt|ods|odp)') !== false, 'Public rules must target static pedagogical documents and LICENSE');
assertSameValue(true, strpos($rootRules, 'Header set X-Robots-Tag "noindex"') !== false, 'Root static pedagogical files must be noindex');
assertSameValue(true, strpos($publicRules, 'Header set X-Robots-Tag "noindex"') !== false, 'Public static pedagogical files must be noindex');

assertSameValue(true, is_readable($contentRulesPath), 'PHP must retain filesystem read access to the content protection file');
assertSameValue($contentRulesTemplate, $contentRules, 'Deployed content rules must match the canonical sync-safe template');
assertSameValue(true, strpos($contentRules, '<IfModule mod_rewrite.c>') !== false, 'Content rules must use the project rewrite module');
assertSameValue(true, strpos($contentRules, 'RewriteEngine On') !== false, 'Content rules must enable local rewriting');
assertSameValue(true, strpos($contentRules, 'RewriteRule ^ - [F,L]') !== false, 'Content rules must forbid every direct HTTP path');

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
