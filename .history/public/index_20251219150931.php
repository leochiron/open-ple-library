<?php

declare(strict_types=1);

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
    $safeName = $security->escapeHtml($filename);
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
    $safeName = $security->escapeHtml($filename);

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($absolute));
    header('Content-Disposition: inline; filename="' . $safeName . '"');
    readfile($absolute);
}
