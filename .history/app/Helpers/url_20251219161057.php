<?php

declare(strict_types=1);

/**
 * Build a query-string URL preserving current parameters.
 */
function buildUrl(array $params = []): string
{
    $current = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null) {
            unset($current[$key]);
        } else {
            $current[$key] = $value;
        }
    }

    $query = http_build_query($current);
    return $query === '' ? '?' : '?' . $query;
}

/**
 * Build a clean URL with only specified parameters (no inheritance).
 */
function buildCleanUrl(array $params): string
{
    $query = http_build_query($params);
    return $query === '' ? '?' : '?' . $query;
}
