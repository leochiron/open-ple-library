<?php

declare(strict_types=1);

error_reporting(E_ALL);
// Suppress deprecation warnings from google/apiclient v2.0 (compatible with PHP 8.1+)
error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$isDevelopment = getenv('APP_ENV') === 'development';
ini_set('display_errors', $isDevelopment ? '1' : '0');
ini_set('log_errors', '1');
$runtimeStorage = __DIR__ . '/../storage';
$runtimeLog = $runtimeStorage . '/php-error.log';
if (is_dir($runtimeStorage)
    && is_writable($runtimeStorage)
    && (!is_file($runtimeLog) || is_writable($runtimeLog))) {
    ini_set('error_log', $runtimeLog);
} else {
    // An empty destination delegates logging to the active SAPI logger.
    ini_set('error_log', '');
}

$renderIncident = static function (Throwable $exception, string $context, int $status = 500) use ($isDevelopment): void {
    static $responseSent = false;
    if ($responseSent) {
        return;
    }
    $responseSent = true;

    try {
        $incidentId = bin2hex(random_bytes(6));
    } catch (Throwable $randomFailure) {
        $incidentId = str_replace('.', '', uniqid('fallback', true));
    }

    error_log(sprintf(
        '[incident:%s] %s: %s: %s in %s:%d',
        $incidentId,
        $context,
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    if ($isDevelopment) {
        echo 'Application error. Incident: ' . $incidentId . "\n";
        echo get_class($exception) . ': ' . $exception->getMessage();
        return;
    }
    echo 'Service temporarily unavailable. Incident: ' . $incidentId;
};

set_exception_handler(static function (Throwable $exception) use ($renderIncident): void {
    $renderIncident($exception, 'Unhandled application failure');
});
register_shutdown_function(static function () use ($renderIncident): void {
    $error = error_get_last();
    if (!is_array($error) || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    $renderIncident(
        new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']),
        'Fatal application failure'
    );
});

$reqUri = $_SERVER['REQUEST_URI'] ?? '';
$earlyPath = parse_url($reqUri, PHP_URL_PATH) ?: '/';
$earlyPath = preg_replace('#^/index\.php(?=/|$)#', '', $earlyPath) ?: '/';

// Serve the exact favicon path in environments without static file mapping.
if ($earlyPath === '/favicon.ico') {
    $favicon = base64_decode('AAABAAEAEBAAAAEAIABoBAAAFgAAACgAAAAQAAAAIAAAAAEAGAAAAAAAAAMAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAAAAA==');
    header('Content-Type: image/x-icon');
    header('Content-Length: ' . strlen($favicon));
    echo $favicon;
    exit;
}

use App\Controllers\ErrorController;
use App\Controllers\LibraryController;
use App\Controllers\QuizAdminController;
use App\Controllers\QuizController;
use App\Controllers\SyncController;
use App\Services\AuthService;
use App\Services\ApplicationProfile;
use App\Services\ApplicationRouter;
use App\Services\FileSystemService;
use App\Services\GoogleDriveService;
use App\Services\I18nService;
use App\Services\MimeService;
use App\Services\PublicUrlResolver;
use App\Services\SecurityService;
use App\Services\ZipService;

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

try {
    $config = require __DIR__ . '/../app/Config/config.php';
    $translations = require __DIR__ . '/../app/Config/i18n.php';
    if (!is_array($config) || !isset($config['branding']) || !is_array($config['branding']) || !is_array($translations)) {
        throw new UnexpectedValueException('Application configuration files must return arrays.');
    }
    $applicationProfile = ApplicationProfile::fromBranding($config['branding']);
    $applicationRouter = new ApplicationRouter($applicationProfile);
    $routeCategory = $applicationRouter->classify($_SERVER['REQUEST_URI'] ?? '/');
} catch (Throwable $exception) {
    $renderIncident($exception, 'Application bootstrap failure', 503);
    exit;
}

// Keep canonical SEO files public even when library password protection is enabled.
if ($earlyPath === '/robots.txt' || $earlyPath === '/sitemap.xml') {
    try {
        $publicBaseUrl = PublicUrlResolver::fromConfig($config)->resolve($_SERVER);
    } catch (Throwable $exception) {
        $renderIncident($exception, 'Public URL bootstrap failure', 503);
        exit;
    }

    if ($earlyPath === '/robots.txt') {
        serveRobotsTxt($publicBaseUrl);
    } else {
        serveSitemapXml($publicBaseUrl);
    }
    exit;
}

if ($routeCategory === ApplicationRouter::MAINTENANCE) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Service temporarily unavailable.';
    exit;
}

if ($routeCategory === ApplicationRouter::UNAVAILABLE
    || $routeCategory === ApplicationRouter::DIAGNOSTIC
    || $routeCategory === ApplicationRouter::PUBLIC_RESOURCE) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

try {
    require __DIR__ . '/../app/Helpers/view.php';
    require __DIR__ . '/../app/Helpers/url.php';
    // Session and translations are shared by the library and quiz modules.
    if (session_status() === PHP_SESSION_NONE && !session_start()) {
        throw new RuntimeException('Unable to start the application session.');
    }
    $i18n = new I18nService($config, $translations);
    $i18n->detectLanguage();
} catch (Throwable $exception) {
    $renderIncident($exception, 'Shared service bootstrap failure');
    exit;
}

// ============================================================
// QUIZ MODULE ROUTES (own authentication: room PIN + personal
// code for students, dedicated password for the teacher admin —
// independent from the optional library password)
// ============================================================
if ($routeCategory === ApplicationRouter::QUIZ || $routeCategory === ApplicationRouter::QUIZ_ADMIN) {
    try {
        $quizStorage = __DIR__ . '/../storage';
        $quizDb = new App\Services\QuizDbService($quizStorage);
        $quizService = new App\Services\QuizService($quizDb, $config, $quizStorage);
        $quizPath = ApplicationRouter::normalizePath($_SERVER['REQUEST_URI'] ?? '/');

        if ($routeCategory === ApplicationRouter::QUIZ_ADMIN) {
            (new QuizAdminController($quizService, $i18n, $config))
                ->handle(substr($quizPath, strlen('/quiz-admin')));
        } else {
            // In quiz-only mode, / is an alias for the canonical /quiz entry.
            $quizSubPath = $quizPath === '/' ? '' : substr($quizPath, strlen('/quiz'));
            (new QuizController($quizService, $i18n, $config))
                ->handle($quizSubPath);
        }
    } catch (Throwable $e) {
        $renderIncident($e, 'Quiz module bootstrap failure');
    }
    exit;
}

// Library services are initialized only after the top-level feature gate.
// This prevents quiz-only requests from creating content/ or loading Drive.
try {
    require __DIR__ . '/../app/Helpers/markdown.php';
    $vendorAutoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($vendorAutoload)) {
        require $vendorAutoload;
    }
    if (!class_exists('Google_Client') && !empty($config['branding']['google_drive_enabled'])) {
        error_log('Google Drive disabled: composer autoload or google/apiclient not available.');
        $config['branding']['google_drive_enabled'] = false;
    }
    if (!isset($config['content_path']) || !is_string($config['content_path']) || $config['content_path'] === '') {
        throw new UnexpectedValueException('The content path is not configured.');
    }
    if (!is_dir($config['content_path'])
        && !mkdir($config['content_path'], 0755, true)
        && !is_dir($config['content_path'])) {
        throw new RuntimeException('Unable to create the content directory.');
    }

    $security = new SecurityService($config['content_path']);
    $fileSystem = new FileSystemService($config['content_path'], $security);
    $mime = new MimeService();
    $zip = new ZipService();
    $auth = new AuthService($config['branding']);
    $googleDrive = new GoogleDriveService($config);
} catch (Throwable $exception) {
    $renderIncident($exception, 'Library service bootstrap failure');
    exit;
}

// ============================================================
// PUBLIC RAW RESOURCES (.md / .skill)
// Served BEFORE the optional library password so external AI agents and direct
// links always work. Mirrors the Apache .htaccess rules, and keeps behaviour
// identical whether a file is static (served by Apache) or routed through PHP.
//   .md    → inline UTF-8 plain text + permissive CORS
//   .skill → forced download
// ============================================================
try {
    $rawMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $rawUri = preg_replace('#^/index\.php#', '', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $rawUri = ltrim((string)$rawUri, '/');
    // Read-only methods only, and leave explicit +download / +open actions to
    // their dedicated handling below.
    if ($rawUri !== '' && in_array($rawMethod, ['GET', 'HEAD', 'OPTIONS'], true)
        && !preg_match('/\+(download|open)\/?$/', $rawUri)) {
        $rawPath = $security->validateRelativePath(rawurldecode($rawUri));
        serveRawResource($fileSystem, $security, $rawPath); // exits when it serves a .md/.skill
    }
} catch (Throwable $e) {
    // Not a serveable resource (bad path, etc.): fall through to the normal flow.
}

// Handle logout
if (isset($_GET['logout'])) {
    $auth->logout();
    header('Location: /');
    exit;
}

// Handle password verification
if ($auth->isEnabled() && isset($_POST['password'])) {
    if ($auth->verifyPassword($_POST['password'])) {
        // Redirect to home after successful login
        header('Location: /');
        exit;
    } else {
        // Show login page with error
        render('login', [
            'error' => $i18n->t('auth.invalid_password'),
        ], $i18n, $config);
        exit;
    }
}

// Check if authentication is required and user is not authenticated
if ($auth->isEnabled() && !$auth->isAuthenticated()) {
    render('login', [
        'error' => '',
    ], $i18n, $config);
    exit;
}

$errorController = new ErrorController($i18n, $config);
$libraryController = new LibraryController($fileSystem, $mime, $security, $i18n, $config);
$syncController = new SyncController($googleDrive, $i18n, $config);

$requestUri = $_SERVER['REQUEST_URI'] ?? '';

// ============================================================
// HANDLE /SYNC ROUTE BEFORE TRY-CATCH (to prevent HTML error pages)
// ============================================================
if ($routeCategory === ApplicationRouter::SYNC) {
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Set JSON headers for POST (sync execution)
            header('Content-Type: application/json; charset=utf-8');
            $syncController->executeSync();
        } else {
            // Set HTML headers for GET (sync page display)
            header('Content-Type: text/html; charset=utf-8');
            $syncController->showSyncPage();
        }
    } catch (\Exception $e) {
        http_response_code(500);
        // Determine response type based on request method
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Sync error: ' . $e->getMessage()
            ]);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<p>Erreur: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        }
        error_log('Sync fatal error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    }
    exit;
}
// ============================================================

$path = '';
$action = null;

try {
    // Extract path from REQUEST_URI directly since .htaccess doesn't work
    $requestUri = preg_replace('#^/index\.php#', '', $requestUri); // Remove /index.php prefix if present
    $requestUri = ltrim($requestUri, '/'); // Remove leading slash
    
    // Check for action suffixes
    if (preg_match('/^(.+)\+download\/?$/', $requestUri, $matches)) {
        $path = $matches[1];
        $action = 'download';
    } elseif (preg_match('/^(.+)\+open\/?$/', $requestUri, $matches)) {
        $path = $matches[1];
        $action = 'open';
    } else {
        // Regular path
        $path = $requestUri;
    }
    
    // Remove query string if present
    if (strpos($path, '?') !== false) {
        $path = substr($path, 0, strpos($path, '?'));
    }
    
    // DECODE URL-encoded characters (like %20 for spaces)
    // Use rawurldecode instead of urldecode because urldecode treats + as space
    // which is wrong for URL paths (+ should be literal)
    $path = rawurldecode($path);
    
    $path = $security->validateRelativePath($path);

    if ($action === 'download') {
        serveDownload($fileSystem, $security, $mime, $zip, $path, $config);
        exit;
    }

    if ($action === 'open') {
        serveInline($fileSystem, $security, $mime, $path);
        exit;
    }

    // Note: plain .md / .skill requests are already served raw (and public)
    // earlier in this front controller, before the auth gate.

    $libraryController->browse($path);
} catch (Throwable $exception) {
    if ($exception instanceof InvalidArgumentException) {
        $errorController->notFound();
    } else {
        $errorController->serverError($exception);
    }
    exit;
}

function serveDownload(FileSystemService $fs, SecurityService $security, MimeService $mime, ZipService $zip, string $relativePath, array $config): void
{
    $absolute = $fs->resolvePath($relativePath);
    
    if (!file_exists($absolute)) {
        throw new InvalidArgumentException('Path not found');
    }

    $security->ensureInsideRoot($absolute);

    // Handle directory download (zip it first)
    if (is_dir($absolute)) {
        $dirName = basename($absolute);
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $dirName);
        $zipFileName = $safeName . '.zip';
        $tempZipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ple_' . uniqid() . '.zip';

        if (!$zip->zipDirectory($absolute, $tempZipPath)) {
            throw new InvalidArgumentException('Failed to create zip archive');
        }

        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($tempZipPath));
        header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
        readfile($tempZipPath);
        
        // Clean up temp file
        unlink($tempZipPath);
        return;
    }

    // Handle file download
    $filename = basename($absolute);
    $safeName = str_replace('"', '', $filename);
    $mimeType = $mime->getMimeType($absolute);

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($absolute));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    readfile($absolute);
}

/**
 * Serves resource files meant to be consumed directly (browser, curl, AI agent):
 *   - .md         : raw UTF-8 plain text, inline, cross-origin readable
 *   - .html/.htm  : rendered as a real HTML page (not the library wrapper)
 *   - .skill      : forced download (attachment)
 * Does nothing (returns) for any other extension or for a directory, so the
 * normal library browser keeps handling them.
 */
function serveRawResource(FileSystemService $fs, SecurityService $security, string $relativePath): void
{
    $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
    if (!in_array($extension, ['md', 'html', 'htm', 'skill'], true)) {
        return;
    }

    $absolute = $fs->resolvePath($relativePath);
    if (!is_file($absolute)) {
        return; // directory or missing file: let the library handle it
    }
    $security->ensureInsideRoot($absolute);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($extension === 'md') {
        // Plain UTF-8 so external agents get the literal content (accents, emojis),
        // and a permissive CORS header so a cross-origin fetch is never blocked.
        header('Content-Type: text/plain; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=300');

        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    } elseif ($extension === 'html' || $extension === 'htm') {
        // Render the teacher's standalone page directly (e.g. a skill landing page),
        // instead of showing the library's file-detail wrapper.
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=300');
    } else { // skill
        $safeName = str_replace('"', '', basename($absolute));
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
    }

    header('Content-Length: ' . filesize($absolute));
    if ($method === 'HEAD') {
        exit;
    }
    readfile($absolute);
    exit;
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

    $fileSize = filesize($absolute);

    // Support HTTP Range requests for large files
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
    
    if (!empty($rangeHeader) && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
        $start = intval($matches[1]);
        $end = $matches[2] !== '' ? intval($matches[2]) : $fileSize - 1;
        
        if ($start <= $end && $start >= 0 && $end < $fileSize) {
            $length = $end - $start + 1;
            
            // Send 206 Partial Content
            http_response_code(206);
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . $length);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            header('Accept-Ranges: bytes');
            header('Cache-Control: public, max-age=3600');
            
            // Stream the requested range
            $fp = fopen($absolute, 'rb');
            if ($fp) {
                fseek($fp, $start);
                $bytesLeft = $length;
                $bufferSize = 1024 * 1024; // 1MB chunks
                
                while ($bytesLeft > 0) {
                    $toRead = min($bufferSize, $bytesLeft);
                    $data = fread($fp, $toRead);
                    if ($data === false || $data === '') break;
                    
                    echo $data;
                    $bytesLeft -= strlen($data);
                    flush();
                }
                fclose($fp);
            }
            exit;
        }
    }
    
    // Full file serving for clients that don't support ranges or first request
    http_response_code(200);
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=3600');
    
    readfile($absolute);
}

function serveRobotsTxt(string $publicBaseUrl): void
{
    header('Content-Type: text/plain; charset=utf-8');

    $robotsFile = __DIR__ . '/../robots.txt';
    $content = '';
    if (is_file($robotsFile)) {
        $fileContent = file_get_contents($robotsFile);
        if ($fileContent !== false) {
            $content = trim($fileContent);
        }
    }

    $sitemapLine = 'Sitemap: ' . $publicBaseUrl . '/sitemap.xml';
    if ($content === '') {
        $content = "User-agent: *\nAllow: /\n\n" . $sitemapLine;
    } elseif (!preg_match('/^Sitemap:/mi', $content)) {
        $content .= "\n\n" . $sitemapLine;
    }

    echo $content . "\n";
}

function serveSitemapXml(string $publicBaseUrl): void
{
    header('Content-Type: application/xml; charset=utf-8');

    $homepage = htmlspecialchars($publicBaseUrl . '/', ENT_QUOTES | ENT_XML1, 'UTF-8');
    $lastmod = gmdate('Y-m-d\TH:i:s\Z');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo "  <url>\n";
    echo "    <loc>{$homepage}</loc>\n";
    echo "    <lastmod>{$lastmod}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>1.0</priority>\n";
    echo "  </url>\n";
    echo "</urlset>\n";
}
