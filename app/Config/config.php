<?php

declare(strict_types=1);

$contentPath = realpath(__DIR__ . '/../../content');
if ($contentPath === false) {
    // Fallback in case realpath fails (should not happen because .gitkeep exists)
    $contentPath = __DIR__ . '/../../content';
}

// Load branding configuration
$brandingPath = __DIR__ . '/branding.php';
$branding = file_exists($brandingPath) ? require $brandingPath : require __DIR__ . '/branding.example.php';

return [
    'app_name' => $branding['site_name'],
    'content_path' => $contentPath,
    'default_language' => $branding['default_language'],
    'languages' => $branding['available_languages'],
    'language_cookie' => $branding['language_cookie'],
    'language_cookie_ttl' => $branding['language_cookie_ttl'],
    'branding' => $branding,
];
