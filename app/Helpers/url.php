<?php

declare(strict_types=1);

/**
 * Build a clean path-based URL without query strings.
 * Supports 'download' and 'open' actions via special suffixes.
 */
function buildUrl(array $params = []): string
{
    $path = $params['path'] ?? '';
    $action = $params['action'] ?? null;
    
    // Ensure path starts with /
    if ($path === '' || $path === null) {
        $basePath = '/';
    } else {
        $basePath = '/' . ltrim($path, '/');
    }
    
    // Append action suffix if present
    if ($action === 'download') {
        return $basePath . '+download';
    }
    if ($action === 'open') {
        return $basePath . '+open';
    }
    
    // No action, just return the path
    return $basePath;
}

/**
 * Build a clean URL with only specified parameters (no inheritance).
 * This is an alias for buildUrl in the new system.
 */
function buildCleanUrl(array $params): string
{
    return buildUrl($params);
}

/**
 * Format a UTC datetime string (as stored by the quiz module) in Paris time.
 */
function formatParisTime(?string $utcDateTime): string
{
    if ($utcDateTime === null || $utcDateTime === '') {
        return '—';
    }
    try {
        $dt = new DateTime($utcDateTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Paris'));
        return $dt->format('d/m/Y H:i:s');
    } catch (Exception $e) {
        return $utcDateTime;
    }
}

/**
 * Detect the public asset base path. Same logic as layout.php: defaults to
 * /assets, falls back to /public/assets when the document root is the repo
 * root (shared hosting served through the root index.php shim).
 */
function assetBase(): string
{
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if ($docRoot !== '' && !is_file($docRoot . '/assets/css/main.css') && is_file($docRoot . '/public/assets/css/main.css')) {
        return '/public/assets';
    }
    return '/assets';
}
