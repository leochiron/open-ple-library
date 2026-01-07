<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Google Drive Service
 * 
 * Provides on-demand access to Google Drive files via API using pure cURL.
 * No dependency on google/apiclient to avoid PHP 8.1+ compatibility issues.
 */
class GoogleDriveService
{
    private ?string $accessToken = null;
    private array $config;
    private array $credentials;
    private bool $available = false;

    /**
     * Initialize Google Drive client
     * 
     * @param array $config Application configuration
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        
        // Load credentials from branding config
        $credentialsPath = $config['branding']['google_drive_credentials'] ?? null;
        
        if ($credentialsPath && file_exists($credentialsPath)) {
            $json = @file_get_contents($credentialsPath);
            if ($json) {
                $this->credentials = json_decode($json, true) ?: [];
                if (isset($this->credentials['client_email']) && isset($this->credentials['private_key'])) {
                    $this->available = true;
                }
            }
        }
    }

    /**
     * Check if Google Drive is configured and available
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Get the configured Google Drive folder ID
     */
    public function getRootFolderId(): ?string
    {
        return $this->config['branding']['google_drive_folder_id'] ?? null;
    }

    /**
     * Get OAuth access token (generates JWT and exchanges for token)
     */
    private function getAccessToken(): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        if (!$this->available) {
            return null;
        }

        try {
            // Base64url encode helper
            $base64url_encode = function ($data) {
                return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
            };

            $clientEmail = $this->credentials['client_email'];
            $privateKey = $this->credentials['private_key'];
            $now = time();

            // Build JWT
            $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $payload = json_encode([
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/drive.readonly',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ]);

            $base64UrlHeader = $base64url_encode($header);
            $base64UrlPayload = $base64url_encode($payload);
            $signatureInput = $base64UrlHeader . '.' . $base64UrlPayload;

            $signature = '';
            if (!openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                error_log('GoogleDriveService: Failed to sign JWT');
                return null;
            }

            $jwt = $signatureInput . '.' . $base64url_encode($signature);

            // Exchange JWT for access token
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                error_log("GoogleDriveService: Token request failed (HTTP $httpCode): $response");
                return null;
            }

            $data = json_decode($response, true);
            $this->accessToken = $data['access_token'] ?? null;

            return $this->accessToken;
        } catch (\Exception $e) {
            error_log('GoogleDriveService: Error getting access token: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Make a GET request to Google Drive API
     */
    private function driveApiGet(string $endpoint, array $params = []): ?array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        $params['supportsAllDrives'] = 'true';
        $url = 'https://www.googleapis.com/drive/v3/' . ltrim($endpoint, '/') 
             . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("GoogleDriveService: API request failed (HTTP $httpCode): $endpoint");
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * List files in a Google Drive folder
     * 
     * @param string|null $folderId Folder ID (null for root)
     * @return array Array of file objects (as associative arrays)
     */
    public function listFiles(?string $folderId = null): array
    {
        if (!$this->isAvailable()) {
            error_log('GoogleDriveService not available');
            return [];
        }

        $folderId = $folderId ?? $this->getRootFolderId();
        if (!$folderId) {
            error_log('GoogleDriveService listFiles: missing folderId');
            return [];
        }

        $all = [];
        $pageToken = null;

        do {
            $params = [
                'q' => "'{$folderId}' in parents and trashed=false",
                'orderBy' => 'folder,name',
                'pageSize' => 1000,
                'fields' => 'nextPageToken,files(id,name,mimeType,size,modifiedTime)',
                'includeItemsFromAllDrives' => 'true',
            ];

            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $result = $this->driveApiGet('files', $params);
            if (!$result) {
                error_log("GoogleDriveService: Failed to list files for folder $folderId");
                break;
            }

            $files = $result['files'] ?? [];
            $all = array_merge($all, $files);
            $pageToken = $result['nextPageToken'] ?? null;
        } while ($pageToken);

        if (empty($all)) {
            error_log("GoogleDriveService: No files found in folder $folderId");
        }

        return $all;
    }

    /**
     * Get file metadata by ID
     * 
     * @param string $fileId Google Drive file ID
     * @return array|null File metadata as associative array
     */
    public function getFile(string $fileId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        return $this->driveApiGet("files/$fileId", [
            'fields' => 'id,name,mimeType,size,modifiedTime,webViewLink',
        ]);
    }

    /**
     * Download file content from Google Drive
     * 
     * @param string $fileId Google Drive file ID
     * @param string|null $destinationPath Optional: path to save file directly (avoids memory issues)
     * @return string|int|null File content (if no destination), bytes written (if destination), or null on error
     */
    public function downloadFile(string $fileId, ?string $destinationPath = null)
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        $url = "https://www.googleapis.com/drive/v3/files/$fileId?alt=media&supportsAllDrives=true";

        // If destination path provided, stream directly to file
        if ($destinationPath) {
            // Ensure parent directory exists
            $dir = dirname($destinationPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $fp = fopen($destinationPath, 'wb');
            if (!$fp) {
                error_log("GoogleDriveService: Cannot open destination file: $destinationPath");
                return null;
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $bytesDownloaded = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            curl_close($ch);
            fclose($fp);

            if ($httpCode !== 200) {
                error_log("GoogleDriveService: Failed to download file $fileId (HTTP $httpCode)");
                @unlink($destinationPath);
                return null;
            }

            return (int)$bytesDownloaded;
        }

        // Otherwise load into memory (for small files)
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $content === false) {
            error_log("GoogleDriveService: Failed to download file $fileId (HTTP $httpCode)");
            return null;
        }

        return $content;
    }

    /**
     * Stream file directly to output (for large files)
     * 
     * @param string $fileId Google Drive file ID
     * @param string $filename Original filename for headers
     * @param string $mimeType MIME type for Content-Type header
     */
    public function streamFile(string $fileId, string $filename, string $mimeType): void
    {
        $token = $this->getAccessToken();
        if (!$token) {
            http_response_code(500);
            return;
        }

        // Set headers
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
        
        $url = "https://www.googleapis.com/drive/v3/files/$fileId?alt=media&supportsAllDrives=true";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // Stream directly to output
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("GoogleDriveService: Failed to stream file $fileId (HTTP $httpCode)");
            http_response_code(500);
        }
    }

    /**
     * Search files by name in a folder
     * 
     * @param string $name File/folder name to search
     * @param string|null $parentId Parent folder ID
     * @return array Matching files
     */
    public function searchByName(string $name, ?string $parentId = null): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $parentId = $parentId ?? $this->getRootFolderId();
        if (!$parentId) {
            return [];
        }

        $result = $this->driveApiGet('files', [
            'q' => "name = '$name' and '$parentId' in parents and trashed=false",
            'fields' => 'files(id,name,mimeType,size,modifiedTime)',
        ]);

        return $result['files'] ?? [];
    }
}
