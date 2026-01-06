<?php

namespace App\Services;

declare(strict_types=1);

use InvalidArgumentException;

class SecurityService
{
    private string $root;

    public function __construct(string $root)
    {
        // Allow boot even if the content directory does not exist yet (e.g., first deploy with empty /content).
        $normalized = realpath($root);
        $base = $normalized !== false ? $normalized : $root;
        $this->root = rtrim($base, DIRECTORY_SEPARATOR);
    }

    public function validateRelativePath(?string $path): string
    {
        $path = (string) $path;
        $path = str_replace('\\', '/', $path);
        $path = trim($path, " \t\n\r\0\x0B/");

        if ($path === '') {
            return '';
        }

        $segments = array_filter(explode('/', $path), static function (string $segment): bool {
            return $segment !== '';
        });

        $clean = [];
        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException('Path traversal detected');
            }
            if ($segment === '.') {
                continue;
            }
            $clean[] = $segment;
        }

        return implode('/', $clean);
    }

    public function ensureInsideRoot(string $absolutePath): void
    {
        $normalized = realpath($absolutePath);
        $candidate = $normalized !== false ? $normalized : $absolutePath;

        if (strpos($candidate, $this->root) !== 0) {
            throw new InvalidArgumentException('Path outside allowed root');
        }
    }

    public function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
