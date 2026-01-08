<?php

declare(strict_types=1);

namespace App\Services;

class FileSystemService
{
    private string $root;
    private SecurityService $security;

    public function __construct(string $root, SecurityService $security)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->security = $security;
    }

    public function resolvePath(string $relativePath): string
    {
        $clean = $this->security->validateRelativePath($relativePath);
        $absolute = $this->root . ($clean === '' ? '' : DIRECTORY_SEPARATOR . $clean);
        $normalized = realpath($absolute);

        if ($normalized !== false) {
            $this->security->ensureInsideRoot($normalized);
            return $normalized;
        }

        // If realpath fails, try to find the path accounting for trailing spaces
        // This handles cases where directory names have trailing whitespace
        $absolute = $this->attemptFuzzyResolve($absolute);
        $this->security->ensureInsideRoot($absolute);
        return $absolute;
    }

    /**
     * Try to resolve a path that might have trailing spaces in directory names.
     * Walks the path segment by segment, matching directories with flexible whitespace.
     */
    private function attemptFuzzyResolve(string $path): string
    {
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $current = '';
        
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                $current .= DIRECTORY_SEPARATOR;
                continue;
            }
            
            if ($current === '') {
                $current = DIRECTORY_SEPARATOR . $part;
            } else {
                $trimmedPart = trim($part);
                
                // If the part doesn't exist, try finding it with fuzzy matching
                if (!file_exists($current . DIRECTORY_SEPARATOR . $part)) {
                    $found = false;
                    if (is_dir($current)) {
                        $items = @scandir($current);
                        if ($items) {
                            foreach ($items as $item) {
                                // Match with trimmed comparison (accounts for trailing spaces)
                                if (trim($item) === $trimmedPart) {
                                    $current .= DIRECTORY_SEPARATOR . $item;
                                    $found = true;
                                    break;
                                }
                            }
                        }
                    }
                    
                    if (!$found) {
                        // Fallback to original part
                        $current .= DIRECTORY_SEPARATOR . $part;
                    }
                } else {
                    $current .= DIRECTORY_SEPARATOR . $part;
                }
            }
        }
        
        return $current;
    }

    /**
     * @return array<int, array{name:string,is_dir:bool,size:int,modified:int}>
     */
    public function listDirectory(string $absolutePath): array
    {
        $entries = scandir($absolutePath) ?: [];
        $result = [];

        foreach ($entries as $entry) {
            if ($this->isIgnored($entry)) {
                continue;
            }

            $full = $absolutePath . DIRECTORY_SEPARATOR . $entry;
            $isDir = is_dir($full);

            $result[] = [
                'name' => $entry,
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : (int) filesize($full),
                'modified' => (int) filemtime($full),
            ];
        }

        return $this->sortEntries($result);
    }

    public function isIgnored(string $name): bool
    {
        if ($name === '.' || $name === '..') {
            return true;
        }

        if (strpos($name, '.') === 0) {
            return true; // hidden files
        }

        $lower = strtolower($name);
        return $lower === 'thumbs.db' || $lower === '.ds_store';
    }

    /**
     * @param array<int, array{name:string,is_dir:bool,size:int,modified:int}> $entries
     * @return array<int, array{name:string,is_dir:bool,size:int,modified:int}>
     */
    public function sortEntries(array $entries): array
    {
        usort($entries, static function (array $a, array $b): int {
            if ($a['is_dir'] && !$b['is_dir']) {
                return -1;
            }
            if (!$a['is_dir'] && $b['is_dir']) {
                return 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $entries;
    }

    public function getHumanFileSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        $value = $bytes / (1024 ** $power);
        return number_format($value, $power >= 1 ? 1 : 0) . ' ' . $units[$power];
    }
}
