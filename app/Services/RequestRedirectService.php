<?php
declare(strict_types=1);
namespace App\Services;

/** Compatibility aliases are private deployment data, not institution-specific code. */
final class RequestRedirectService
{
    public static function resolve(string $uri, string $method, array $aliases): ?array
    {
        if (!in_array($method, ['GET', 'HEAD'], true)) { return null; }
        $parts = explode('?', $uri, 2);
        $path = ApplicationRouter::normalizePath($parts[0]);
        foreach ($aliases as $alias) {
            $from = $alias['from'] ?? ''; $to = $alias['to'] ?? ''; $status = $alias['status'] ?? 301;
            if (!is_string($from) || $from === '' || $from[0] !== '/' || !is_string($to) || preg_match('/[\r\n]/', $to) || !in_array($status, [301, 302, 307, 308], true)) { continue; }
            if (!(strpos($to, '/') === 0 && strpos($to, '//') !== 0) && !(filter_var($to, FILTER_VALIDATE_URL) && parse_url($to, PHP_URL_SCHEME) === 'https' && !parse_url($to, PHP_URL_USER))) { continue; }
            $prefix = !empty($alias['prefix']);
            if ($path !== $from && !($prefix && (strpos($path, $from . '/') === 0 || strpos($path, $from . '+') === 0))) { continue; }
            return ['location' => $to . ($prefix ? substr($path, strlen($from)) : '') . (isset($parts[1]) ? '?' . $parts[1] : ''), 'status' => $status];
        }
        return null;
    }
}
