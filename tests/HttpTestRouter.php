<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$candidate = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . ltrim(rawurldecode($path), '/');

// Mirror a normal web server: existing assets and PHP files are served
// directly, while application URLs are sent to the selected front controller.
if ($path !== '/' && $path !== '/robots.txt' && $path !== '/sitemap.xml' && is_file($candidate)) {
    return false;
}

$frontController = getenv('PLE_TEST_FRONT_CONTROLLER');
if (!is_string($frontController) || !is_file($frontController)) {
    http_response_code(500);
    echo 'Missing test front controller';
    return true;
}

require $frontController;
