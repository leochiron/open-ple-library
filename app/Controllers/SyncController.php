<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\GoogleDriveService;
use App\Services\I18nService;

/**
 * Sync Controller
 * 
 * Handles manual synchronization from Google Drive to local content folder.
 */
class SyncController
{
    private GoogleDriveService $googleDrive;
    private I18nService $i18n;
    private array $config;
    private string $contentPath;

    public function __construct(
        GoogleDriveService $googleDrive,
        I18nService $i18n,
        array $config
    ) {
        $this->googleDrive = $googleDrive;
        $this->i18n = $i18n;
        $this->config = $config;
        $this->contentPath = $config['content_path'];
    }

    /**
     * Display sync page with password form
     */
    public function showSyncPage(): void
    {
        // Check if Google Drive is enabled
        if (!$this->config['branding']['google_drive_enabled']) {
            http_response_code(503);
            echo "Google Drive synchronization is not enabled.";
            return;
        }

        // Render sync page using the global render() helper
        render('sync/index', [
            // No specific data needed for now
        ], $this->i18n, $this->config);
    }

    /**
     * Execute synchronization (password protected)
     */
    public function executeSync(): void
    {
        // Check if client wants Server-Sent Events stream
        $useSSE = ($_POST['stream'] ?? '') === 'true';
        
        if ($useSSE) {
            // Set SSE headers
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no'); // Disable nginx buffering
            ob_implicit_flush(true);
            
            $this->executeSyncWithProgress();
            return;
        }
        
        // Legacy JSON response
        header('Content-Type: application/json; charset=utf-8');
        
        // Increase limits for large sync operations
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', '600'); // 10 minutes
        
        // Check if Google Drive is enabled
        if (!$this->config['branding']['google_drive_enabled']) {
            http_response_code(503);
            echo json_encode(['error' => 'Google Drive not enabled']);
            return;
        }

        // Verify sync password
        $password = $_POST['sync_password'] ?? '';
        $validPassword = $this->config['branding']['sync_password'] ?? null;

        if (!$validPassword || $password !== $validPassword) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid password']);
            return;
        }

        // Start sync
        set_time_limit(0); // No timeout
        
        try {
            $result = $this->syncFromGoogleDrive();
            echo json_encode([
                'success' => true,
                'message' => 'Synchronization completed successfully',
                'stats' => $result
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            error_log('Sync error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /**
     * Execute sync with Server-Sent Events progress
     */
    private function executeSyncWithProgress(): void
    {
        $sendEvent = function(string $type, $data) {
            echo "event: $type\n";
            echo 'data: ' . json_encode($data) . "\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };
        
        // Check if Google Drive is enabled
        if (!$this->config['branding']['google_drive_enabled']) {
            $sendEvent('error', ['message' => 'Google Drive not enabled']);
            return;
        }

        // Verify sync password
        $password = $_POST['sync_password'] ?? '';
        $validPassword = $this->config['branding']['sync_password'] ?? null;

        if (!$validPassword || $password !== $validPassword) {
            $sendEvent('error', ['message' => 'Invalid password']);
            return;
        }

        // Increase limits
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', '600');
        set_time_limit(0);
        
        try {
            $sendEvent('progress', ['message' => 'Connexion à Google Drive...']);
            
            if (!$this->googleDrive->isAvailable()) {
                throw new \RuntimeException('Google Drive non disponible');
            }

            $rootFolderId = $this->googleDrive->getRootFolderId();
            if (!$rootFolderId) {
                throw new \RuntimeException('Folder ID not configured');
            }

            $stats = [
                'folders_created' => 0,
                'files_downloaded' => 0,
                'files_deleted' => 0,
                'bytes_transferred' => 0,
            ];

            $startTime = microtime(true);

            // Clear existing content
            $deleteExisting = $this->config['branding']['sync_delete_existing'] ?? true;
            if ($deleteExisting && is_dir($this->contentPath)) {
                $sendEvent('progress', ['message' => 'Suppression du contenu existant...']);
                $this->deleteDirectory($this->contentPath, $stats);
            }

            // Ensure content directory exists
            if (!is_dir($this->contentPath)) {
                mkdir($this->contentPath, 0755, true);
            }

            $sendEvent('progress', ['message' => 'Téléchargement en cours...']);

            // Download with progress callback
            $this->downloadFolderWithProgress($rootFolderId, $this->contentPath, $stats, $sendEvent);

            $stats['duration'] = round(microtime(true) - $startTime, 2);

            $sendEvent('complete', [
                'message' => 'Synchronisation terminée avec succès !',
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            $sendEvent('error', ['message' => $e->getMessage()]);
            error_log('Sync error: ' . $e->getMessage());
        }
    }

    /**
     * Synchronize content from Google Drive
     */
    private function syncFromGoogleDrive(): array
    {
        if (!$this->googleDrive->isAvailable()) {
            throw new \RuntimeException('Google Drive non disponible: dépendances Composer manquantes ou credentials invalides.');
        }

        $stats = [
            'folders_created' => 0,
            'files_downloaded' => 0,
            'files_deleted' => 0,
            'bytes_transferred' => 0,
            'duration' => 0,
        ];

        $startTime = microtime(true);

        // Get root folder ID
        $rootFolderId = $this->googleDrive->getRootFolderId();
        if (!$rootFolderId) {
            throw new \RuntimeException('Google Drive folder ID not configured');
        }

        // Clear existing content (optional - you may want to keep this configurable)
        $deleteExisting = $this->config['branding']['sync_delete_existing'] ?? true;
        if ($deleteExisting && is_dir($this->contentPath)) {
            $this->deleteDirectory($this->contentPath, $stats);
        }

        // Ensure content directory exists
        if (!is_dir($this->contentPath)) {
            mkdir($this->contentPath, 0755, true);
        }

        // Start recursive download
        $this->downloadFolder($rootFolderId, $this->contentPath, $stats);

        $stats['duration'] = round(microtime(true) - $startTime, 2);
        return $stats;
    }

    /**
     * Recursively download a folder and its contents
     */
    private function downloadFolder(string $folderId, string $localPath, array &$stats): void
    {
        $files = $this->googleDrive->listFiles($folderId);

        foreach ($files as $file) {
            // Files are now associative arrays instead of Google objects
            $fileName = $file['name'] ?? 'unknown';
            $mimeType = $file['mimeType'] ?? '';
            $fileId = $file['id'] ?? null;
            
            if (!$fileId) {
                continue;
            }
            
            $localFilePath = $localPath . DIRECTORY_SEPARATOR . $fileName;

            // Check if it's a folder
            if ($mimeType === 'application/vnd.google-apps.folder') {
                // Create local folder
                if (!is_dir($localFilePath)) {
                    mkdir($localFilePath, 0755, true);
                    $stats['folders_created']++;
                }

                // Recursively download folder contents
                $this->downloadFolder($fileId, $localFilePath, $stats);
            } else {
                // Skip Google Docs native formats (can't be downloaded as-is)
                if (strpos($mimeType, 'application/vnd.google-apps.') === 0) {
                    continue;
                }

                // Download file directly to disk (avoids memory exhaustion)
                $bytesWritten = $this->googleDrive->downloadFile($fileId, $localFilePath);
                if ($bytesWritten !== null) {
                    $stats['files_downloaded']++;
                    $stats['bytes_transferred'] += $bytesWritten;
                    
                    // Log progress for large operations
                    if ($stats['files_downloaded'] % 10 === 0) {
                        error_log("Sync progress: {$stats['files_downloaded']} files, " . 
                                  round($stats['bytes_transferred'] / 1048576, 2) . " MB");
                    }
                }
            }
        }
    }

    /**
     * Recursively download a folder with progress reporting
     */
    private function downloadFolderWithProgress(string $folderId, string $localPath, array &$stats, callable $sendEvent, int $depth = 0): void
    {
        $files = $this->googleDrive->listFiles($folderId);

        foreach ($files as $file) {
            $fileName = $file['name'] ?? 'unknown';
            $mimeType = $file['mimeType'] ?? '';
            $fileId = $file['id'] ?? null;
            
            if (!$fileId) {
                continue;
            }
            
            $localFilePath = $localPath . DIRECTORY_SEPARATOR . $fileName;

            // Check if it's a folder
            if ($mimeType === 'application/vnd.google-apps.folder') {
                // Log parent folders only (depth 0)
                if ($depth === 0) {
                    $sendEvent('progress', [
                        'message' => "📁 Téléchargement du dossier : $fileName",
                        'folder' => $fileName,
                        'stats' => $stats
                    ]);
                }
                
                // Create local folder
                if (!is_dir($localFilePath)) {
                    mkdir($localFilePath, 0755, true);
                    $stats['folders_created']++;
                }

                // Recursively download folder contents
                $this->downloadFolderWithProgress($fileId, $localFilePath, $stats, $sendEvent, $depth + 1);
            } else {
                // Skip Google Docs native formats
                if (strpos($mimeType, 'application/vnd.google-apps.') === 0) {
                    continue;
                }

                // Download file directly to disk
                $bytesWritten = $this->googleDrive->downloadFile($fileId, $localFilePath);
                if ($bytesWritten !== null) {
                    $stats['files_downloaded']++;
                    $stats['bytes_transferred'] += $bytesWritten;
                    
                    // Send progress every 5 files
                    if ($stats['files_downloaded'] % 5 === 0) {
                        $sendEvent('progress', [
                            'message' => "📄 {$stats['files_downloaded']} fichiers téléchargés (" . 
                                        round($stats['bytes_transferred'] / 1048576, 1) . " MB)",
                            'stats' => $stats
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Recursively delete a directory
     */
    private function deleteDirectory(string $dir, array &$stats): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path, $stats);
            } else {
                unlink($path);
                $stats['files_deleted']++;
            }
        }

        rmdir($dir);
    }

    // View rendering is handled by the global render() helper.
}
