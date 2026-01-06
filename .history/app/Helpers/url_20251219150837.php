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
