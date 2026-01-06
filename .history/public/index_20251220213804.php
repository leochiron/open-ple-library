<?php

declare(strict_types=1);

// TEMP: verbose error display for debugging. Remove or disable after diagnosis.
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/php-error.log');

// Serve favicon to avoid 404 in environments without static file mapping.
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($reqUri, 'favicon.ico') !== false) {
    $favicon = base64_decode('AAABAAEAEBAAAAEAIABoBAAAFgAAACgAAAAQAAAAIAAAAAEAGAAAAAAAAAMAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAAAAA==');
    header('Content-Type: image/x-icon');
    header('Content-Length: ' . strlen($favicon));
    echo $favicon;
    exit;
}

use App\Controllers\ErrorController;
use App\Controllers\LibraryController;
use App\Services\FileSystemService;
use App\Services\I18nService;
use App\Services\MimeService;
use App\Services\SecurityService;
use InvalidArgumentException;
use Throwable;

// Simple PSR-4–like autoloader for the App namespace
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../app/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/../app/Helpers/view.php';
require __DIR__ . '/../app/Helpers/url.php';

$config = require __DIR__ . '/../app/Config/config.php';
$translations = require __DIR__ . '/../app/Config/i18n.php';

// Ensure content directory exists to avoid runtime errors on first deploy.
if (!is_dir($config['content_path'])) {
    mkdir($config['content_path'], 0755, true);
}

$security = new SecurityService($config['content_path']);
$fileSystem = new FileSystemService($config['content_path'], $security);
$mime = new MimeService();
$i18n = new I18nService($config, $translations);
$i18n->detectLanguage();

$errorController = new ErrorController($i18n, $config);
$libraryController = new LibraryController($fileSystem, $mime, $security, $i18n, $config);

$path = '';
$action = $_GET['action'] ?? null;

try {
    $path = $security->validateRelativePath($_GET['path'] ?? '');

    if ($action === 'download') {
        serveDownload($fileSystem, $security, $mime, $path);
        exit;
    }

    if ($action === 'open') {
        serveInline($fileSystem, $security, $mime, $path);
        exit;
    }

    $libraryController->browse($path);
} catch (Throwable $exception) {
    if ($exception instanceof InvalidArgumentException) {
        $errorController->notFound();
    } else {
        $errorController->serverError($exception);
    }
    exit;
}

function serveDownload(FileSystemService $fs, SecurityService $security, MimeService $mime, string $relativePath): void
{
    $absolute = $fs->resolvePath($relativePath);
    if (!is_file($absolute)) {
        throw new InvalidArgumentException('Not a file');
    }

    $security->ensureInsideRoot($absolute);

    $filename = basename($absolute);
    $safeName = str_replace('"', '', $filename);
    $mimeType = $mime->getMimeType($absolute);

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($absolute));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    readfile($absolute);
}

function serveInline(FileSystemService $fs, SecurityService $security, MimeService $mime, string $relativePath): void
{
    $absolute = $fs->resolvePath($relativePath);
    if (!is_file($absolute)) {
        throw new InvalidArgumentException('Not a file');
    }

    $security->ensureInsideRoot($absolute);
    $mimeType = $mime->getMimeType($absolute);

    if (!$mime->isPreviewable($mimeType)) {
        throw new InvalidArgumentException('File not previewable');
    }

    $filename = basename($absolute);
    $safeName = str_replace('"', '', $filename);
    $fileSize = filesize($absolute);

    // Support HTTP Range requests for video/audio streaming
    $isVideo = strpos($mimeType, 'video') === 0;
    $isAudio = strpos($mimeType, 'audio') === 0;
    
    if (($isVideo || $isAudio) && isset($_SERVER['HTTP_RANGE'])) {
        // Parse range header (e.g., "bytes=0-1023")
        if (!preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }

        $start = intval($matches[1]);
        $end = $matches[2] !== '' ? intval($matches[2]) : $fileSize - 1;
        $end = min($end, $fileSize - 1);
        $length = $end - $start + 1;

        if ($start > $end || $start >= $fileSize) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }

        // Send partial content - use http_response_code() instead of header() for status
        http_response_code(206);
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $length);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=3600');

        $fp = fopen($absolute, 'rb');
        fseek($fp, $start);
        $buffer = 8192;
        $bytesLeft = $length;
        
        while ($bytesLeft > 0 && !feof($fp)) {
            $read = min($buffer, $bytesLeft);
            echo fread($fp, $read);
            $bytesLeft -= $read;
            flush();
        }
        fclose($fp);
    } else {
        // Normal full file serving
        http_response_code(200);
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $fileSize);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=3600');
        readfile($absolute);
    }
}
