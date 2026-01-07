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
