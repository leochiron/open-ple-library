<?php

declare(strict_types=1);

$contentPath = realpath(__DIR__ . '/../../content');
if ($contentPath === false) {
    // Fallback in case realpath fails (should not happen because .gitkeep exists)
    $contentPath = __DIR__ . '/../../content';
}

// Load branding configuration
$brandingPath = __DIR__ . '/branding.php';
$testBrandingPath = getenv('APP_ENV') === 'testing' ? getenv('PLE_TEST_BRANDING') : false;
if (is_string($testBrandingPath) && $testBrandingPath !== '' && is_file($testBrandingPath)) {
    $brandingPath = $testBrandingPath;
}
$branding = file_exists($brandingPath) ? require $brandingPath : require __DIR__ . '/branding.example.php';

$testContentPath = getenv('APP_ENV') === 'testing' ? getenv('PLE_TEST_CONTENT_PATH') : false;
if (is_string($testContentPath) && $testContentPath !== '') {
    $contentPath = $testContentPath;
}

// Provide sane fallbacks when a deployment's branding.php predates new keys
$defaultLanguage = $branding['default_language'] ?? 'fr';
$languages = $branding['available_languages'] ?? ['fr', 'en', 'eo', 'ar'];
$languageCookie = $branding['language_cookie'] ?? 'ple_lang';
$languageCookieTtl = $branding['language_cookie_ttl'] ?? (60 * 60 * 24 * 30);

return [
    'app_name' => $branding['site_name'],
    'content_path' => $contentPath,
    'default_language' => $defaultLanguage,
    'languages' => $languages,
    'language_cookie' => $languageCookie,
    'language_cookie_ttl' => $languageCookieTtl,
    'branding' => $branding,
];
