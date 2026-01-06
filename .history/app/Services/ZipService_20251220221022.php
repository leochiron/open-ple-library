<?php

declare(strict_types=1);

namespace App\Services;

use ZipArchive;

class ZipService
{
    /**
     * Create a zip archive from a directory.
     *
     * @param string $sourcePath Absolute path to the directory to zip
     * @param string $zipPath Absolute path where the zip file will be created
     * @return bool True on success, false on failure
     */
    public function zipDirectory(string $sourcePath, string $zipPath): bool
    {
        if (!is_dir($sourcePath)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $this->addDirectoryToZip($zip, $sourcePath, basename($sourcePath));
        
        return $zip->close();
    }

    /**
     * Recursively add directory contents to zip archive.
     *
     * @param ZipArchive $zip
     * @param string $sourcePath Absolute path to directory
     * @param string $localPath Path inside the zip archive
     * @return void
     */
    private function addDirectoryToZip(ZipArchive $zip, string $sourcePath, string $localPath): void
    {
        $sourcePath = rtrim($sourcePath, '/\\');
        $items = scandir($sourcePath);
        
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $sourcePath . DIRECTORY_SEPARATOR . $item;
            $zipPath = $localPath . '/' . $item;

            if (is_dir($itemPath)) {
                $zip->addEmptyDir($zipPath);
                $this->addDirectoryToZip($zip, $itemPath, $zipPath);
            } else {
                $zip->addFile($itemPath, $zipPath);
            }
        }
    }
}
