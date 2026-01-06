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
    'default_language' => 'fr',
    'languages' => ['fr', 'en', 'eo'],
    'language_cookie' => 'ple_lang',
    'language_cookie_ttl' => 60 * 60 * 24 * 30, // 30 days
    'branding' => $branding,
];
